<?php

/**
 * Repository lint: PHP syntax plus text hygiene (BOM, CRLF, final newline).
 *
 * Usage:
 *   php tools/lint.php              # PHP syntax and code-file encoding
 *   php tools/lint.php --text       # encoding for every text file, markdown included
 *   php tools/lint.php --no-syntax  # encoding only, skip `php -l`
 *
 * Exit codes: 0 clean, 1 findings, 2 usage or environment problem.
 */

declare(strict_types=1);

$flags = array_slice($argv, 1);
$unknown = array_diff($flags, ['--text', '--no-syntax']);

if ($unknown !== []) {
    fwrite(STDERR, 'unknown option: ' . implode(' ', $unknown) . PHP_EOL);
    exit(2);
}

$withText = in_array('--text', $flags, true);
$syntax = !in_array('--no-syntax', $flags, true);
$root = dirname(__DIR__);

$skipDirs = ['.git', 'var', 'vendor', 'node_modules', 'build', '.ragraf', '.idea', '.vscode'];
$codeExt = ['php', 'js', 'css', 'json', 'sql', 'yml', 'yaml', 'sh'];
$textExt = array_merge($codeExt, ['md', 'txt', 'example', 'gitignore', 'gitattributes', 'editorconfig']);

$findings = [];
$checked = 0;

/** @return iterable<string> */
function walk(string $root, array $skipDirs): iterable
{
    $stack = [$root];

    while ($stack !== []) {
        $dir = array_pop($stack);
        $entries = @scandir($dir);

        if ($entries === false) {
            continue;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                if (!in_array($entry, $skipDirs, true)) {
                    $stack[] = $path;
                }

                continue;
            }

            yield $path;
        }
    }
}

function extensionOf(string $path): string
{
    $name = basename($path);
    $dot = strrpos($name, '.');

    return $dot === false ? '' : strtolower(substr($name, $dot + 1));
}

function isGitMetaFile(string $path): bool
{
    return str_starts_with(basename($path), '.git');
}

foreach (walk($root, $skipDirs) as $path) {
    $relative = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
    $ext = extensionOf($path);
    $isDotConfig = isGitMetaFile($path) && !str_ends_with($path, '.example');

    $isCode = in_array($ext, $codeExt, true);
    $isText = $withText && (in_array($ext, $textExt, true) || $isDotConfig);

    if (!$isCode && !$isText) {
        continue;
    }

    $raw = file_get_contents($path);

    if ($raw === false) {
        $findings[] = [$relative, 0, 'file is not readable'];
        continue;
    }

    $checked++;

    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $findings[] = [$relative, 1, 'UTF-8 BOM (write without BOM)'];
    }

    if (str_contains($raw, "\r\n")) {
        $findings[] = [$relative, 0, 'CRLF line endings (expected LF)'];
    }

    if ($raw !== '' && !str_ends_with($raw, "\n")) {
        $findings[] = [$relative, substr_count($raw, "\n") + 1, 'missing final newline'];
    }

    if ($syntax && $ext === 'php') {
        $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($command, $output, $code);

        if ($code !== 0) {
            $findings[] = [$relative, 0, 'php -l: ' . trim(implode(' ', $output))];
        }
    }
}

printf("lint: %d file(s) checked, %d finding(s)\n", $checked, count($findings));

foreach ($findings as [$file, $line, $message]) {
    printf("  %s:%d  %s\n", $file, $line, $message);
}

exit($findings === [] ? 0 : 1);
