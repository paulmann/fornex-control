<?php

/**
 * Клиент Fornex Public API (https://ru.fornex.com/api, OpenAPI 2.3.29).
 *
 * Транспорт — curl с явным User-Agent: замерено 30.10.2026, что запросы без
 * браузерного UA к этому хосту подвисают на TLS и возвращают 0 байт.
 *
 * Ошибки 4xx не повторяются (это ответ по существу), 429/5xx и сетевые сбои —
 * повторяются с паузой. Ключ читается из настроек и в журнал не выводится.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Fornex;

use FornexPanel\Support\Json;

final class Client
{
    /** @var list<string> */
    private array $log = [];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout = 30,
        private readonly int $retries = 2,
        private readonly string $userAgent = 'fornex-control/1.1',
    ) {
    }

    /** @return list<string> */
    public function log(): array
    {
        return $this->log;
    }

    /** @return list<array<string, mixed>> */
    public function domains(int $pageSize = 100): array
    {
        $response = $this->request('GET', '/dns/domain/', ['page_size' => $pageSize]);
        $rows = $response['results'] ?? $response;

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return array<string, mixed> */
    public function zone(string $domain): array
    {
        $response = $this->request('GET', '/dns/domain/' . rawurlencode($domain) . '/entry_set/');

        return [
            'entries' => is_array($response) ? array_values(array_filter($response, 'is_array')) : [],
        ];
    }

    public function zoneFile(string $domain): string
    {
        return $this->requestRaw('GET', '/dns/domain/' . rawurlencode($domain) . '/zone_file/', 'text/plain');
    }

    /** @return array<string, mixed> */
    public function createEntry(string $domain, array $record): array
    {
        return $this->request('POST', '/dns/domain/' . rawurlencode($domain) . '/entry_set/', $record);
    }

    /** @return array<string, mixed> */
    public function updateEntry(string $domain, int $id, array $record): array
    {
        return $this->request('PUT', '/dns/domain/' . rawurlencode($domain) . '/entry_set/' . $id . '/', $record);
    }

    public function deleteEntry(string $domain, int $id): void
    {
        $this->request('DELETE', '/dns/domain/' . rawurlencode($domain) . '/entry_set/' . $id . '/');
    }

    /** @return array<string, mixed> */
    public function createDomain(string $domain, string $ip): array
    {
        return $this->request('POST', '/dns/domain/', ['name' => $domain, 'ip' => $ip]);
    }

    public function deleteDomain(string $domain): void
    {
        $this->request('DELETE', '/dns/domain/' . rawurlencode($domain) . '/');
    }

    /** @return list<array<string, mixed>> */
    public function orders(int $pageSize = 100): array
    {
        $response = $this->request('GET', '/orders/', ['page_size' => $pageSize]);
        $rows = $response['results'] ?? $response;

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * Смена NS у домена, заказанного у Fornex. Для домена, зарегистрированного
     * у другого регистратора, метод неприменим — там NS ставятся в панели регистратора.
     *
     * @param list<string> $nameservers
     * @return array<string, mixed>
     */
    public function changeNs(string $orderId, array $nameservers): array
    {
        return $this->request(
            'POST',
            '/dns/order/' . rawurlencode($orderId) . '/change_ns/',
            ['nss' => implode(',', $nameservers)],
        );
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $body = $this->requestRaw($method, $path, 'application/json', $payload);

        if (trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new FornexException(502, 'ответ не является JSON', $method, $path);
        }

        return $decoded;
    }

    /** @param array<string, mixed>|null $payload */
    private function requestRaw(string $method, string $path, string $accept, ?array $payload = null): string
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $attempt = 0;
        $lastError = '';

        while ($attempt <= $this->retries) {
            $attempt++;

            $handle = curl_init($url);
            $headers = [
                'Authorization: Api-Key ' . $this->apiKey,
                'Accept: ' . $accept,
                'User-Agent: ' . $this->userAgent,
            ];

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];

            if ($payload !== null) {
                $options[CURLOPT_POSTFIELDS] = Json::encode($payload);
                $headers[] = 'Content-Type: application/json';
                $options[CURLOPT_HTTPHEADER] = $headers;
            }

            curl_setopt_array($handle, $options);
            $responseBody = curl_exec($handle);
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            // curl_close() в PHP 8.5 объявлен устаревшим и ничего не делает.
            unset($handle);

            $this->log[] = sprintf('%s %s → %s', $method, $path, $status > 0 ? (string) $status : 'network error');

            if ($errno !== 0 || $responseBody === false) {
                $lastError = $errno !== 0 ? $error : 'пустой ответ';
                if ($attempt <= $this->retries) {
                    usleep(400_000 * $attempt);

                    continue;
                }

                throw new FornexException(0, 'сетевая ошибка: ' . $lastError, $method, $path);
            }

            $body = (string) $responseBody;

            if ($status >= 200 && $status < 300) {
                return $body;
            }

            if (($status === 429 || $status >= 500) && $attempt <= $this->retries) {
                usleep(700_000 * $attempt);

                continue;
            }

            throw new FornexException($status, $this->shorten($body), $method, $path);
        }

        throw new FornexException(0, 'запрос не выполнен: ' . $lastError, $method, $path);
    }

    private function shorten(string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            return 'пустой ответ';
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            foreach (['detail', 'message', 'error'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key])) {
                    return $decoded[$key];
                }
            }
        }

        return mb_substr($body, 0, 300);
    }
}
