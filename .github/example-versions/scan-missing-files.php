<?php

require __DIR__ . '/lib/missing-files.php';

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php scan-missing-files.php <report results.json>\n");
    exit(2);
}

$results = json_decode((string) @file_get_contents($argv[1]), true);
if (!is_array($results)) {
    fwrite(STDERR, "Cannot read results from {$argv[1]}\n");
    exit(1);
}

foreach (missing_file_report($results) as $path => $pages) {
    printf("%3d  %s  [%s]\n", count($pages), $path, implode(', ', $pages));
}
