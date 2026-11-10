<?php

function normalize_output($text)
{
    $text = str_replace("\r\n", "\n", $text);
    $text = preg_replace('/[ \t]+$/m', '', $text);
    return trim($text, "\n");
}

function classify($exitCode, $stdout, $stderr, $expected, $annotations)
{
    if ($exitCode === 124) {
        return 'timeout';
    }
    if ($exitCode !== 0 || preg_match('/(Fatal|Parse) error/', $stderr)) {
        return 'error';
    }
    if (trim($stderr) !== '') {
        return 'warning';
    }
    if (strpos($annotations, 'non-interactive') !== false || $expected === null) {
        return 'pass';
    }
    return normalize_output($stdout) === normalize_output($expected) ? 'pass' : 'output-mismatch';
}

function remove_tree($path)
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                remove_tree($path . '/' . $entry);
            }
        }
        @chmod($path, 0777);
        rmdir($path);
    } else {
        unlink($path);
    }
}

define('MAX_STREAM_BYTES', 1048576);
define('TRUNCATION_MARKER', "\n[output truncated]");

function drain_pipes($streams)
{
    $buffers = array();
    $truncated = array();
    foreach ($streams as $key => $stream) {
        stream_set_blocking($stream, 0);
        $buffers[$key] = '';
        $truncated[$key] = false;
    }
    while (count($streams) > 0) {
        $read = $streams;
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 1) === false) {
            break;
        }
        foreach ($read as $stream) {
            $key = array_search($stream, $streams);
            $chunk = fread($stream, 8192);
            if ($chunk !== false && $chunk !== '') {
                $room = MAX_STREAM_BYTES - strlen($buffers[$key]);
                if (strlen($chunk) > $room) {
                    $truncated[$key] = true;
                    $chunk = substr($chunk, 0, $room);
                }
                $buffers[$key] .= $chunk;
            }
            if (feof($stream)) {
                fclose($stream);
                unset($streams[$key]);
            }
        }
    }
    foreach ($streams as $stream) {
        fclose($stream);
    }
    foreach ($truncated as $key => $wasTruncated) {
        if ($wasTruncated) {
            $buffers[$key] .= TRUNCATION_MARKER;
        }
    }
    return $buffers;
}

function extension_is_loaded($extension)
{
    $aliases = array('opcache' => 'Zend OPcache');
    return extension_loaded($extension)
        || (isset($aliases[$extension]) && extension_loaded($aliases[$extension]));
}

function copy_tree($source, $target)
{
    if (!is_dir($source)) {
        copy($source, $target);
        return;
    }
    mkdir($target);
    foreach (scandir($source) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            copy_tree($source . '/' . $entry, $target . '/' . $entry);
        }
    }
}

function support_entries_for($code, $supportDir)
{
    $entries = array();
    if (!is_dir($supportDir)) {
        return $entries;
    }
    foreach (scandir($supportDir) as $entry) {
        if ($entry !== '.' && $entry !== '..' && strpos($code, $entry) !== false) {
            $entries[] = $entry;
        }
    }
    return $entries;
}

function execute_example($code, $nonInteractive, $directory, $supportDir)
{
    mkdir($directory);
    foreach (support_entries_for($code, $supportDir) as $entry) {
        copy_tree($supportDir . '/' . $entry, $directory . '/' . $entry);
    }
    file_put_contents($directory . '/example.php', $code);

    $flags = $nonInteractive
        ? '-d date.timezone=UTC -l'
        : '-d date.timezone=UTC -d phar.readonly=0 -d error_reporting=-1 -d display_errors=stderr -d html_errors=0 -d log_errors=0';
    $descriptors = array(
        0 => array('file', '/dev/null', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );
    $process = proc_open("timeout 10 php $flags example.php", $descriptors, $pipes, $directory);
    if (!is_resource($process)) {
        remove_tree($directory);
        return array('exit' => null, 'stdout' => '', 'stderr' => 'proc_open failed');
    }
    $captured = drain_pipes(array(1 => $pipes[1], 2 => $pipes[2]));
    $stdout = $captured[1];
    $stderr = $captured[2];
    $exit = proc_close($process);
    remove_tree($directory);

    return array('exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr);
}

function build_result($example, $status, $exit, $stdout, $stderr)
{
    unset($example['code']);
    $example['status'] = $status;
    $example['exit'] = $exit;
    $example['stdout'] = base64_encode($stdout);
    $example['stderr'] = base64_encode($stderr);
    return $example;
}

function run_example($example, $directory, $supportDir)
{
    if (!extension_is_loaded($example['extension'])) {
        return build_result($example, 'skipped-extension', null, '', '');
    }
    $annotations = isset($example['annotations']) ? $example['annotations'] : '';
    $outcome = execute_example(
        $example['code'],
        strpos($annotations, 'non-interactive') !== false,
        $directory,
        $supportDir
    );
    $status = $outcome['exit'] === null
        ? 'error'
        : classify($outcome['exit'], $outcome['stdout'], $outcome['stderr'], $example['expected'], $annotations);
    return build_result($example, $status, $outcome['exit'], $outcome['stdout'], $outcome['stderr']);
}

function run_batch($batch, $supportDir)
{
    $results = array();
    $workRoot = sys_get_temp_dir() . '/ev-' . getmypid();
    foreach ($batch['examples'] as $n => $example) {
        $results[] = run_example($example, $workRoot . '-' . $n, $supportDir);
    }
    return array('tag' => $batch['tag'], 'results' => $results);
}

function rerun_skipped($batch, $previous, $fallbackTag, $supportDir)
{
    $workRoot = sys_get_temp_dir() . '/ev-' . getmypid();
    foreach ($previous['results'] as $n => $result) {
        $example = $batch['examples'][$n];
        if ($result['status'] !== 'skipped-extension' || !extension_is_loaded($example['extension'])) {
            continue;
        }
        $rerun = run_example($example, $workRoot . '-' . $n, $supportDir);
        $rerun['fallback_tag'] = $fallbackTag;
        $previous['results'][$n] = $rerun;
    }
    return $previous;
}

function mark_unavailable($batch)
{
    $results = array();
    foreach ($batch['examples'] as $example) {
        $results[] = build_result($example, 'image-unavailable', null, '', '');
    }
    return array('tag' => $batch['tag'], 'results' => $results);
}
