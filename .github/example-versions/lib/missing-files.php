<?php

const MISSING_FILE_PATTERNS = [
    '/No such file or directory/i',
    '/failed to open stream/i',
    '/failed to load external entity/i',
    '/Unable to open/i',
    '/Could not open/i',
    '/Cannot create phar/i',
];

function missing_file_path(string $line): ?string
{
    foreach (MISSING_FILE_PATTERNS as $pattern) {
        if (!preg_match($pattern, $line)) {
            continue;
        }
        if (preg_match('/(?:\(|"|\')((?:[a-z]+:\/\/)?[^()"\'\s]*[\/.][^()"\'\s]*)(?:\)|"|\')/i', $line, $matches)) {
            return preg_replace('#^/tmp/ev-\d+-\d+/#', '', $matches[1]);
        }
        return trim($line);
    }
    return null;
}

function missing_file_report(array $results): array
{
    $paths = [];
    foreach ($results as $result) {
        if (in_array($result['status'], ['pass', 'skipped-extension', 'image-unavailable'], true)) {
            continue;
        }
        $output = base64_decode($result['stderr'] ?? '') . "\n" . base64_decode($result['stdout'] ?? '');
        foreach (explode("\n", $output) as $line) {
            $path = missing_file_path($line);
            if ($path !== null) {
                $paths[$path][$result['page']] = true;
            }
        }
    }
    $report = array_map('array_keys', $paths);
    uksort($report, fn(string $a, string $b): int => [count($report[$b]), $a] <=> [count($report[$a]), $b]);
    return $report;
}
