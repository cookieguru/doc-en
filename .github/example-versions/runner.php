<?php

require_once dirname(__FILE__) . '/lib/runner-lib.php';

function fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function read_json_file($path, $description)
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        fail("Cannot read $description file $path");
    }
    $data = json_decode($contents, true);
    if (!is_array($data)) {
        fail("Invalid $description file $path");
    }
    return $data;
}

$usage = "Usage: php runner.php run|unavailable <batch.json> <results.json>\n"
    . "       php runner.php rerun-skipped <batch.json> <results.json> <fallback-tag>\n";
$mode = isset($argv[1]) ? $argv[1] : '';
$expectedArgs = $mode === 'rerun-skipped' ? 5 : 4;
if ($argc !== $expectedArgs || !in_array($mode, array('run', 'unavailable', 'rerun-skipped'))) {
    fwrite(STDERR, $usage);
    exit(2);
}

$batch = read_json_file($argv[2], 'batch');
if (!isset($batch['tag']) || !isset($batch['examples'])) {
    fail('Invalid batch file ' . $argv[2]);
}

$rootOverride = getenv('EXAMPLE_VERSIONS_ROOT');
$support = load_support(dirname(__FILE__) . '/support', $rootOverride === false ? '' : $rootOverride);
if ($mode === 'run') {
    $results = run_batch($batch, $support);
} elseif ($mode === 'unavailable') {
    $results = mark_unavailable($batch);
} else {
    $previous = read_json_file($argv[3], 'results');
    if (!isset($previous['results']) || count($previous['results']) !== count($batch['examples'])) {
        fail('Results file ' . $argv[3] . ' does not match batch ' . $argv[2]);
    }
    $results = rerun_skipped($batch, $previous, $argv[4], $support);
}

$encoded = json_encode($results);
if ($encoded === false) {
    fail('Cannot encode results');
}
if (file_put_contents($argv[3], $encoded) === false) {
    fail('Cannot write results file ' . $argv[3]);
}
