<?php

/**
 * Secret scan: fails when the tree looks like it carries a live credential.
 *
 * Usage:  php tools/secret-scan.php [--root= DIR]
 * Exit:   0 clean, 1 findings, 2 usage error.
 *
 * The patterns are deliberately narrow: a false alarm that forces a real key
 * into a "temporary" exception is worse than a missed one. Every rule prints
 * file:line and the rule id, never the matched text.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $root = rtrim(substr($arg, 7), '/\\');
        continue;
    }

    fwrite(STDERR, 'unknown option: ' . $arg . PHP_EOL);
    exit(2);
}

if (!is_dir($root)) {
    fwrite(STDERR, 'not a directory: ' . $root . PHP_EOL);
    exit(2);
}

/** @var array<string, string> rule id => PCRE */
$rules = [
    'private-key' => '~-----BEGIN (?:RSA |EC |OPENSSH |PGP )?PRIVATE KEY-----~',
    'long-hex' => '~\b[0-9a-fA-F]{48,}\b~',
    'aws-key' => '~\bAKIA[0-9A-Z]{16}\b~',
    'github-token' => '~\bgh[pousr]_[A-Za-z0-9]{20,}~',
    'slack-token' => '~xox[abprs]-[A-Za-z0-9-]{10,}~',
    'google-api-key' => '~\bAIza[0-9A-Za-z_-]{35}\b~',
    // a literal password / secret assignment with a non-empty value, but not
    // a read from input, not an empty default, not a hash placeholder
    'literal-credential' => '~(?i)\b(?:api[_-]?key|secret|passwd|password|token)\b\s*(?:=|:)\s*[\'"][^\'"\s]{8,}[\'"]~',
];

$skipDirs = ['.git', 'var', 'vendor', 'node_modules', 'build', '.ragraf'];
$allowedFiles = ['.env.example'];   // documented placeholders only
$findings = [];

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

        $relative = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);

        if (in_array(basename($path), $allowedFiles, true)) {
            continue;
        }

        $size = filesize($path);

        if ($size === false || $size > 2_000_000) {
            continue;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            continue;
        }

        foreach ($lines as $index => $line) {
            foreach ($rules as $id => $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    // documented shapes that are not secrets
                    if ($id === 'literal-credential' && preg_match(
                        '~(?i)(?:php -r|password_hash|PASSWORD_DEFAULT|\$_(?:POST|GET|REQUEST)|getenv|\$this->|password\.hash|\.password_hash)~',
                        $line,
                    ) === 1) {
                        continue;
                    }

                    $findings[] = sprintf('%s:%d  %s', $relative, $index + 1, $id);
                }
            }
        }
    }
}

$findings = array_values(array_unique($findings));
printf("secret scan: %d finding(s)\n", count($findings));

foreach ($findings as $finding) {
    echo '  ' . $finding . PHP_EOL;
}

exit($findings === [] ? 0 : 1);
