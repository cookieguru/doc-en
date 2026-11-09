<?php

require_once dirname(__FILE__) . '/lib/runner-lib.php';

function fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

if ($argc !== 4 || !in_array($argv[1], array('run', 'unavailable'))) {
    fwrite(STDERR, "Usage: php runner.php run|unavailable <batch.json> <results.json>\n");
    exit(2);
}

$contents = @file_get_contents($argv[2]);
if ($contents === false) {
    fail('Cannot read batch file ' . $argv[2]);
}
$batch = json_decode($contents, true);
if (!is_array($batch) || !isset($batch['tag']) || !isset($batch['examples'])) {
    fail('Invalid batch file ' . $argv[2]);
}

$results = $argv[1] === 'run' ? run_batch($batch) : mark_unavailable($batch);
$encoded = json_encode($results);
if ($encoded === false) {
    fail('Cannot encode results');
}
if (file_put_contents($argv[3], $encoded) === false) {
    fail('Cannot write results file ' . $argv[3]);
}
