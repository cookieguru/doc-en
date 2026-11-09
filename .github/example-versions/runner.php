<?php

require_once dirname(__FILE__) . '/lib/runner-lib.php';

if ($argc !== 4 || !in_array($argv[1], array('run', 'unavailable'))) {
    fwrite(STDERR, "Usage: php runner.php run|unavailable <batch.json> <results.json>\n");
    exit(2);
}

$batch = json_decode(file_get_contents($argv[2]), true);
$results = $argv[1] === 'run' ? run_batch($batch) : mark_unavailable($batch);
file_put_contents($argv[3], json_encode($results));
