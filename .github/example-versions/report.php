<?php

require __DIR__ . '/lib/runner-lib.php';
require __DIR__ . '/lib/report.php';

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php report.php <results-dir> <out-dir>\n");
    exit(2);
}

[, $resultsDir, $outDir] = $argv;
$results = load_results($resultsDir);
$tagsFile = $resultsDir . '/batches/tags.json';
$expectedTags = is_file($tagsFile) ? (json_decode(file_get_contents($tagsFile), true) ?: []) : [];
$markdown = render_report($results, missing_tags($expectedTags, $results));

if (!is_dir($outDir) && !mkdir($outDir, 0777, true)) {
    fwrite(STDERR, "Cannot create $outDir\n");
    exit(1);
}
file_put_contents($outDir . '/report.md', $markdown);
file_put_contents(
    $outDir . '/results.json',
    json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
);

$summaryFile = getenv('GITHUB_STEP_SUMMARY');
if ($summaryFile !== false && $summaryFile !== '') {
    file_put_contents($summaryFile, truncate_summary($markdown, 900000) . "\n", FILE_APPEND);
}
