<?php

require __DIR__ . '/lib/runner-lib.php';
require __DIR__ . '/lib/report.php';

const ISSUE_CALL_PAUSE_SECONDS = 2;

function gh(array $args): string
{
    $process = proc_open(array_merge(['gh'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, "Cannot start gh\n");
        exit(1);
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        fwrite(STDERR, 'gh ' . implode(' ', $args) . " failed:\n$stderr");
        exit(1);
    }
    return $stdout;
}

function with_body_file(string $body, callable $use): string
{
    $path = tempnam(sys_get_temp_dir(), 'issue');
    file_put_contents($path, $body);
    try {
        return $use($path);
    } finally {
        unlink($path);
    }
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php sync-issues.php <results-dir> <repo>\n");
    exit(2);
}

[, $resultsDir, $repo] = $argv;

foreach ([ISSUE_LABEL, ISSUE_OUTPUT_ONLY_LABEL] as $label) {
    gh(['label', 'create', $label, '--force', '-R', $repo]);
}

$openIssues = json_decode(gh([
    'issue', 'list', '-R', $repo, '--label', ISSUE_LABEL, '--state', 'open', '--limit', '1000', '--json', 'number,body',
]), true);
if (!is_array($openIssues)) {
    fwrite(STDERR, "Unexpected output from gh issue list\n");
    exit(1);
}

$callsMade = 0;
foreach (plan_issues(load_results($resultsDir), $openIssues) as $step) {
    if ($callsMade++ > 0) {
        sleep(ISSUE_CALL_PAUSE_SECONDS);
    }
    switch ($step['action']) {
        case 'create':
            with_body_file($step['body'], function ($bodyFile) use ($repo, $step) {
                $args = ['issue', 'create', '-R', $repo, '--title', $step['title'], '--body-file', $bodyFile];
                foreach ($step['labels'] as $label) {
                    array_push($args, '--label', $label);
                }
                return gh($args);
            });
            echo "created issue for {$step['page']}\n";
            break;
        case 'update':
            with_body_file($step['body'], function ($bodyFile) use ($repo, $step) {
                $args = ['issue', 'edit', (string) $step['number'], '-R', $repo, '--title', $step['title'], '--body-file', $bodyFile];
                if (in_array(ISSUE_OUTPUT_ONLY_LABEL, $step['labels'], true)) {
                    array_push($args, '--add-label', ISSUE_OUTPUT_ONLY_LABEL);
                } else {
                    array_push($args, '--remove-label', ISSUE_OUTPUT_ONLY_LABEL);
                }
                return gh($args);
            });
            echo "updated issue #{$step['number']} for {$step['page']}\n";
            break;
        case 'close':
            gh(['issue', 'close', (string) $step['number'], '-R', $repo, '--comment', 'Examples now pass on their minimum version.']);
            echo "closed issue #{$step['number']} for {$step['page']}\n";
            break;
    }
}
