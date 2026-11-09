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

function execute_example($code, $nonInteractive, $directory)
{
    mkdir($directory);
    file_put_contents($directory . '/example.php', $code);

    $flags = $nonInteractive
        ? '-l'
        : '-d error_reporting=-1 -d display_errors=stderr -d html_errors=0 -d log_errors=0';
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
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
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

function run_batch($batch)
{
    $results = array();
    $workRoot = sys_get_temp_dir() . '/ev-' . getmypid();
    foreach ($batch['examples'] as $n => $example) {
        if (!extension_loaded($example['extension'])) {
            $results[] = build_result($example, 'skipped-extension', null, '', '');
            continue;
        }
        $annotations = isset($example['annotations']) ? $example['annotations'] : '';
        $outcome = execute_example(
            $example['code'],
            strpos($annotations, 'non-interactive') !== false,
            $workRoot . '-' . $n
        );
        $status = $outcome['exit'] === null
            ? 'error'
            : classify($outcome['exit'], $outcome['stdout'], $outcome['stderr'], $example['expected'], $annotations);
        $results[] = build_result($example, $status, $outcome['exit'], $outcome['stdout'], $outcome['stderr']);
    }
    return array('tag' => $batch['tag'], 'results' => $results);
}

function mark_unavailable($batch)
{
    $results = array();
    foreach ($batch['examples'] as $example) {
        $results[] = build_result($example, 'image-unavailable', null, '', '');
    }
    return array('tag' => $batch['tag'], 'results' => $results);
}
