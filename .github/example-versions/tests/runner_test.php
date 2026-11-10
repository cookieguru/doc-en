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

function test_extension_is_loaded_resolves_opcache_alias(): void
{
    assert_same(true, extension_is_loaded('standard'));
    assert_same(false, extension_is_loaded('no_such_ext'));
    assert_same(extension_loaded('opcache') || extension_loaded('Zend OPcache'), extension_is_loaded('opcache'));
}

function test_runner_truncates_huge_stdout(): void
{
    if (!runner_environment_supported(__FUNCTION__)) {
        return;
    }
    $dir = sys_get_temp_dir() . '/ev-runner-' . uniqid();
    mkdir($dir);
    $batch = ['tag' => 'host', 'extensions' => ['standard'], 'examples' => [
        ['page' => 'p', 'example' => 'huge', 'code' => "<?php echo str_repeat('x', 3 * 1024 * 1024);", 'annotations' => '', 'expected' => null, 'extension' => 'standard'],
    ]];
    file_put_contents("$dir/batch.json", json_encode($batch));
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/runner.php') . " run $dir/batch.json $dir/results.json", $out, $code);
    assert_same(0, $code);
    $stdout = base64_decode(json_decode(file_get_contents("$dir/results.json"), true)['results'][0]['stdout']);
    assert_same(MAX_STREAM_BYTES + strlen(TRUNCATION_MARKER), strlen($stdout));
    assert_same(true, str_ends_with($stdout, TRUNCATION_MARKER));
}

function support_dir(): string
{
    return dirname(__DIR__) . '/support/files';
}

function test_support_entries_for_copies_only_referenced_entries(): void
{
    assert_same(['examples'], support_entries_for("<?php \$doc->load('examples/book-simple.xml');", support_dir()));
    assert_same(['phplogo.png'], support_entries_for("<?php imagecreatefrompng('phplogo.png');", support_dir()));
    assert_same([], support_entries_for("<?php echo 'hi';", support_dir()));
    assert_same([], support_entries_for("<?php echo 'hi';", support_dir() . '/missing'));
}

function test_support_paths_sources_exist(): void
{
    $lines = file(dirname(__DIR__) . '/support/paths.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $fields = preg_split('/\s+/', trim($line));
        if ($line[0] === '#' || count($fields) < 2) {
            continue;
        }
        assert_same(true, file_exists(support_dir() . '/' . $fields[1]), "missing support source for $line");
    }
}

function run_runner_cli(string $arguments): int
{
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/runner.php') . ' ' . $arguments . ' 2>&1', $out, $code);
    return $code;
}

function test_runner_copies_support_files_into_working_directory(): void
{
    if (!runner_environment_supported(__FUNCTION__)) {
        return;
    }
    $dir = sys_get_temp_dir() . '/ev-runner-' . uniqid();
    mkdir($dir);
    $batch = ['tag' => 'host', 'extensions' => ['standard'], 'examples' => [
        ['page' => 'p', 'example' => 'books', 'code' => "<?php echo substr_count(file_get_contents('examples/book-simple.xml'), '<book>');", 'annotations' => '', 'expected' => '3', 'extension' => 'standard'],
    ]];
    file_put_contents("$dir/batch.json", json_encode($batch));
    assert_same(0, run_runner_cli("run $dir/batch.json $dir/results.json"));
    assert_same('pass', json_decode(file_get_contents("$dir/results.json"), true)['results'][0]['status']);
}

function test_runner_rerun_skipped_replaces_only_skipped_results(): void
{
    if (!runner_environment_supported(__FUNCTION__)) {
        return;
    }
    $dir = sys_get_temp_dir() . '/ev-runner-' . uniqid();
    mkdir($dir);
    $batch = ['tag' => '5.3.29', 'extensions' => ['standard'], 'examples' => [
        ['page' => 'p', 'example' => 'ran', 'code' => "<?php echo 'a';", 'annotations' => '', 'expected' => 'a', 'extension' => 'standard'],
        ['page' => 'p', 'example' => 'skipped', 'code' => "<?php echo 'b';", 'annotations' => '', 'expected' => 'b', 'extension' => 'standard'],
        ['page' => 'p', 'example' => 'still-missing', 'code' => "<?php", 'annotations' => '', 'expected' => null, 'extension' => 'no_such_ext'],
    ]];
    $previous = ['tag' => '5.3.29', 'results' => [
        ['page' => 'p', 'example' => 'ran', 'status' => 'warning'],
        ['page' => 'p', 'example' => 'skipped', 'status' => 'skipped-extension'],
        ['page' => 'p', 'example' => 'still-missing', 'status' => 'skipped-extension'],
    ]];
    file_put_contents("$dir/batch.json", json_encode($batch));
    file_put_contents("$dir/results.json", json_encode($previous));
    assert_same(0, run_runner_cli("rerun-skipped $dir/batch.json $dir/results.json 5.5.38"));
    $results = json_decode(file_get_contents("$dir/results.json"), true);
    assert_same('5.3.29', $results['tag']);
    assert_same(['warning', 'pass', 'skipped-extension'], array_column($results['results'], 'status'));
    assert_same('5.5.38', $results['results'][1]['fallback_tag']);
    assert_same(false, isset($results['results'][2]['fallback_tag']));
}

function test_runner_rerun_skipped_rejects_mismatched_results(): void
{
    $dir = sys_get_temp_dir() . '/ev-runner-' . uniqid();
    mkdir($dir);
    file_put_contents("$dir/batch.json", json_encode(['tag' => 't', 'examples' => [['page' => 'p']]]));
    file_put_contents("$dir/results.json", json_encode(['tag' => 't', 'results' => []]));
    assert_same(1, run_runner_cli("rerun-skipped $dir/batch.json $dir/results.json 5.5.38"));
}

function test_requirements_loaded_checks_every_required_extension(): void
{
    assert_same(true, requirements_loaded(['extension' => 'standard']));
    assert_same(true, requirements_loaded(['extension' => 'standard', 'requires' => ['standard', 'pcre']]));
    assert_same(false, requirements_loaded(['extension' => 'standard', 'requires' => ['standard', 'no_such_ext']]));
}

function test_needs_service_recognizes_connection_failures(): void
{
    assert_same(true, needs_service('warning', '', "Warning: pg_connect(): Unable to connect to PostgreSQL server: could not connect to server", ['pgsql']));
    assert_same(true, needs_service('error', '', "Fatal error: Uncaught PDOException: SQLSTATE[HY000] [2002] No such file or directory", ['pdo_mysql']));
    assert_same(true, needs_service('timeout', '', '', ['oci8']));
    assert_same(false, needs_service('timeout', '', '', ['standard']));
    assert_same(false, needs_service('error', '', 'Warning: Undefined variable $dsn', ['pdo_pgsql']));
    assert_same(false, needs_service('pass', '', 'Connection refused', ['standard']));
}

function test_reset_support_paths_restores_paths_between_examples(): void
{
    $root = sys_get_temp_dir() . '/ev-reset-' . uniqid();
    $support = load_support(dirname(__DIR__) . '/support', $root);
    reset_support_paths($support);
    assert_same(true, is_dir("$root/path/to"));
    assert_same(true, is_file("$root/usr/local/something.txt"));
    assert_same(true, is_file("$root/path/to/project/index.php"));
    file_put_contents("$root/path/to/leftover.txt", 'x');
    file_put_contents("$root/usr/local/something.txt", 'changed');
    reset_support_paths($support);
    assert_same(false, file_exists("$root/path/to/leftover.txt"));
    assert_same(file_get_contents(support_dir() . '/something.txt'), file_get_contents("$root/usr/local/something.txt"));
    remove_tree($root);
}

function test_runner_skips_output_comparison_for_similar_output(): void
{
    if (!runner_environment_supported(__FUNCTION__)) {
        return;
    }
    $dir = sys_get_temp_dir() . '/ev-runner-' . uniqid();
    mkdir($dir);
    $batch = ['tag' => 'host', 'extensions' => ['standard'], 'examples' => [
        ['page' => 'p', 'example' => 'similar', 'code' => "<?php echo time();", 'annotations' => '', 'expected' => '1234', 'similar' => true, 'extension' => 'standard'],
        ['page' => 'p', 'example' => 'exact', 'code' => "<?php echo time();", 'annotations' => '', 'expected' => '1234', 'similar' => false, 'extension' => 'standard'],
    ]];
    file_put_contents("$dir/batch.json", json_encode($batch));
    assert_same(0, run_runner_cli("run $dir/batch.json $dir/results.json"));
    assert_same(['pass', 'output-mismatch'], array_column(json_decode(file_get_contents("$dir/results.json"), true)['results'], 'status'));
}
