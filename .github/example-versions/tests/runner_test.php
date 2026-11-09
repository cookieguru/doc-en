<?php

function test_classify_statuses(): void
{
    assert_same('error', classify(255, '', "PHP Fatal error:  Uncaught Error", null, ''));
    assert_same('error', classify(0, '', "Parse error: syntax error", null, ''));
    assert_same('timeout', classify(124, '', '', null, ''));
    assert_same('warning', classify(0, "x\n", "Warning: Undefined property: Dom\\HTMLElement::\$outerHTML", "x", ''));
    assert_same('output-mismatch', classify(0, "Saved 74 bytes\nint(74)\n", '', "Saved 60 bytes\nint(60)", ''));
    assert_same('pass', classify(0, "a  \r\nb\r\n\r\n", '', "\na\nb", ''));
    assert_same('pass', classify(0, "anything", '', "different", 'non-interactive'));
    assert_same('pass', classify(0, "anything", '', null, ''));
}

function test_runner_cli_end_to_end_on_host_php(): void
{
    if (PHP_OS_FAMILY === 'Windows') {
        echo "SKIP test_runner_cli_end_to_end_on_host_php: runner targets Linux\n";
        return;
    }
    exec('timeout --version', $versionOut, $versionCode);
    if ($versionCode !== 0) {
        echo "SKIP test_runner_cli_end_to_end_on_host_php: timeout command unavailable\n";
        return;
    }

    $dir = sys_get_temp_dir() . '/ev-runner-' . uniqid();
    mkdir($dir);
    $batch = ['tag' => 'host', 'extensions' => ['standard', 'no_such_ext'], 'examples' => [
        ['page' => 'p', 'example' => 'ok', 'code' => "<?php echo 'hi';", 'annotations' => '', 'expected' => 'hi', 'extension' => 'standard'],
        ['page' => 'p', 'example' => 'loop', 'code' => "<?php while (true) {}", 'annotations' => '', 'expected' => null, 'extension' => 'standard'],
        ['page' => 'p', 'example' => 'bin', 'code' => "<?php echo \"\\xff\\xfe\";", 'annotations' => '', 'expected' => null, 'extension' => 'standard'],
        ['page' => 'p', 'example' => 'lint', 'code' => "<?php exit(3);", 'annotations' => 'non-interactive', 'expected' => null, 'extension' => 'standard'],
        ['page' => 'p', 'example' => 'missing', 'code' => "<?php", 'annotations' => '', 'expected' => null, 'extension' => 'no_such_ext'],
    ]];
    file_put_contents("$dir/batch.json", json_encode($batch));
    exec(PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__) . '/runner.php') . " run $dir/batch.json $dir/results.json", $out, $code);
    assert_same(0, $code);
    $results = json_decode(file_get_contents("$dir/results.json"), true)['results'];
    assert_same(['pass', 'timeout', 'pass', 'pass', 'skipped-extension'], array_column($results, 'status'));
    assert_same("\xff\xfe", base64_decode($results[2]['stdout']));
    assert_same(false, array_key_exists('code', $results[0]));
}

function test_runner_unavailable_marks_every_example(): void
{
    $dir = sys_get_temp_dir() . '/ev-runner-' . uniqid();
    mkdir($dir);
    $batch = ['tag' => 'old', 'extensions' => [], 'examples' => [
        ['page' => 'p', 'example' => 'a', 'code' => '<?php', 'annotations' => '', 'expected' => null, 'extension' => 'standard'],
    ]];
    file_put_contents("$dir/batch.json", json_encode($batch));
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/runner.php') . " unavailable $dir/batch.json $dir/results.json", $out, $code);
    assert_same(0, $code);
    $decoded = json_decode(file_get_contents("$dir/results.json"), true);
    assert_same('old', $decoded['tag']);
    assert_same('image-unavailable', $decoded['results'][0]['status']);
    assert_same(null, $decoded['results'][0]['exit']);
    assert_same(false, array_key_exists('code', $decoded['results'][0]));
}

function runner_environment_supported(string $testName): bool
{
    if (PHP_OS_FAMILY === 'Windows') {
        echo "SKIP $testName: runner targets Linux\n";
        return false;
    }
    exec('timeout --version', $versionOut, $versionCode);
    if ($versionCode !== 0) {
        echo "SKIP $testName: timeout command unavailable\n";
        return false;
    }
    return true;
}

function test_runner_rejects_invalid_batch(): void
{
    $dir = sys_get_temp_dir() . '/ev-runner-' . uniqid();
    mkdir($dir);
    file_put_contents("$dir/batch.json", 'not json');
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/runner.php') . " run $dir/batch.json $dir/results.json 2>&1", $out, $code);
    assert_same(1, $code);
    assert_same(false, file_exists("$dir/results.json"));
}

function test_runner_drains_large_stderr_without_timeout(): void
{
    if (!runner_environment_supported(__FUNCTION__)) {
        return;
    }
    $dir = sys_get_temp_dir() . '/ev-runner-' . uniqid();
    mkdir($dir);
    $batch = ['tag' => 'host', 'extensions' => ['standard'], 'examples' => [
        ['page' => 'p', 'example' => 'noisy', 'code' => "<?php fwrite(STDERR, str_repeat('x', 200000)); echo 'ok';", 'annotations' => '', 'expected' => null, 'extension' => 'standard'],
    ]];
    file_put_contents("$dir/batch.json", json_encode($batch));
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/runner.php') . " run $dir/batch.json $dir/results.json", $out, $code);
    assert_same(0, $code);
    $results = json_decode(file_get_contents("$dir/results.json"), true)['results'];
    assert_same('warning', $results[0]['status']);
    assert_same(200000, strlen(base64_decode($results[0]['stderr'])));
}
