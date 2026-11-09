<?php

const REPORT_STATUSES = [
    'image-unavailable',
    'error',
    'timeout',
    'warning',
    'output-mismatch',
    'pass',
    'skipped-extension',
];
const REPORT_DETAIL_WIDTH = 200;

function load_results(string $dir): array
{
    $paths = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (preg_match('/^results-.*\.json$/', $file->getFilename())) {
            $paths[] = $file->getPathname();
        }
    }
    sort($paths);

    $results = [];
    foreach ($paths as $path) {
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data) || !isset($data['tag'], $data['results'])) {
            throw new RuntimeException("Malformed results file: $path");
        }
        foreach ($data['results'] as $result) {
            $result['tag'] = $data['tag'];
            $results[] = $result;
        }
    }
    return $results;
}

function compare_tags(string $a, string $b): int
{
    if ($a === 'unresolved' || $b === 'unresolved') {
        return ($a === 'unresolved') <=> ($b === 'unresolved');
    }
    return version_compare($a, $b);
}

function report_text(?string $encoded): string
{
    return mb_scrub((string) base64_decode((string) $encoded), 'UTF-8');
}

function report_cell(string $text): string
{
    return str_replace(['|', "\r", "\n"], ['\|', '', ' '], $text);
}

function report_code(string $text): string
{
    if ($text === '') {
        return '';
    }
    return '`' . report_cell(str_replace('`', "'", $text)) . '`';
}

function first_nonempty_line(string $text): string
{
    foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
        if (trim($line) !== '') {
            return trim($line);
        }
    }
    return '';
}

function first_differing_line(string $expected, string $actual): string
{
    $expectedLines = explode("\n", normalize_output(mb_scrub($expected, 'UTF-8')));
    $actualLines = explode("\n", normalize_output($actual));
    $count = max(count($expectedLines), count($actualLines));
    for ($i = 0; $i < $count; $i++) {
        $expectedLine = $expectedLines[$i] ?? '';
        $actualLine = $actualLines[$i] ?? '';
        if ($expectedLine !== $actualLine) {
            return report_code(shorten($expectedLine)) . ' → ' . report_code(shorten($actualLine));
        }
    }
    return '';
}

function shorten(string $text): string
{
    return mb_strimwidth($text, 0, REPORT_DETAIL_WIDTH, '...', 'UTF-8');
}

function result_detail(array $result): string
{
    $stderrLine = first_nonempty_line(report_text($result['stderr'] ?? ''));
    if ($stderrLine !== '') {
        return report_code(shorten($stderrLine));
    }
    if ($result['status'] === 'output-mismatch') {
        return first_differing_line((string) ($result['expected'] ?? ''), report_text($result['stdout'] ?? ''));
    }
    return '';
}

function render_summary(array $results): string
{
    $counts = [];
    foreach ($results as $result) {
        $counts[$result['tag']][$result['status']] = ($counts[$result['tag']][$result['status']] ?? 0) + 1;
    }
    uksort($counts, 'compare_tags');

    $md = "## Summary\n\n| Tag | " . implode(' | ', REPORT_STATUSES) . " |\n";
    $md .= '|---|' . str_repeat('---|', count(REPORT_STATUSES)) . "\n";
    foreach ($counts as $tag => $byStatus) {
        $cells = array_map(fn($status) => (string) ($byStatus[$status] ?? 0), REPORT_STATUSES);
        $md .= '| ' . report_cell((string) $tag) . ' | ' . implode(' | ', $cells) . " |\n";
    }
    return $md;
}

function render_result_table(array $rows): string
{
    usort($rows, fn($a, $b) => [$a['page'], $a['example']] <=> [$b['page'], $b['example']]
        ?: compare_tags($a['tag'], $b['tag']));
    $md = "| Page | Example | Tag | Detail |
|---|---|---|---|
";
    foreach ($rows as $row) {
        $tag = $row['tag'] . (!empty($row['substituted']) ? ' (substituted)' : '');
        $md .= '| ' . report_cell($row['page']) . ' | ' . report_cell((string) $row['example'])
            . ' | ' . report_cell($tag) . ' | ' . result_detail($row) . " |
";
    }
    return $md;
}

function render_status_section(string $status, array $results): string
{
    $byExtension = [];
    foreach ($results as $result) {
        $byExtension[$result['extension'] ?? ''][] = $result;
    }
    ksort($byExtension);

    $md = "
## $status
";
    foreach ($byExtension as $extension => $rows) {
        $md .= "
### " . report_cell((string) $extension) . "

" . render_result_table($rows);
    }
    return $md;
}

function render_report(array $results): string
{
    $md = render_summary($results);
    foreach (REPORT_STATUSES as $status) {
        if ($status === 'pass') {
            continue;
        }
        $matching = array_filter($results, fn($result) => $result['status'] === $status);
        if ($matching) {
            $md .= render_status_section($status, $matching);
        }
    }
    return $md;
}

function truncate_summary(string $markdown, int $limitBytes): string
{
    if (strlen($markdown) <= $limitBytes) {
        return $markdown;
    }
    $head = substr($markdown, 0, $limitBytes);
    $lastBreak = strrpos($head, "\n");
    return substr($head, 0, $lastBreak === false ? 0 : $lastBreak) . "\n\n_Truncated; see the report artifact._";
}

const ISSUE_BODY_LIMIT_BYTES = 60000;
const ISSUE_LABEL = 'example-versions';
const ISSUE_OUTPUT_ONLY_LABEL = 'output-only';
const ISSUE_PASSING_STATUSES = ['pass', 'skipped-extension'];

function issue_marker(string $page): string
{
    return "<!-- example-versions:$page -->";
}

function issue_marker_page(string $body): ?string
{
    return preg_match('/<!-- example-versions:(.*?) -->/', $body, $m) ? $m[1] : null;
}

function plan_issues(array $results, array $openIssues): array
{
    $failuresByPage = [];
    foreach ($results as $result) {
        $failuresByPage[$result['page']] ??= [];
        if (!in_array($result['status'], ISSUE_PASSING_STATUSES, true)) {
            $failuresByPage[$result['page']][] = $result;
        }
    }

    $issueByPage = [];
    foreach ($openIssues as $issue) {
        $page = issue_marker_page($issue['body']);
        if ($page !== null) {
            $issueByPage[$page] = $issue['number'];
        }
    }

    $plan = [];
    foreach ($failuresByPage as $page => $failures) {
        $page = (string) $page;
        $number = $issueByPage[$page] ?? null;
        if (!$failures) {
            if ($number !== null) {
                $plan[] = ['action' => 'close', 'number' => $number, 'page' => $page];
            }
            continue;
        }

        $outputOnly = !array_filter($failures, fn($failure) => $failure['status'] !== 'output-mismatch');
        $issue = [
            'page' => $page,
            'title' => "Example fails on minimum PHP version: $page",
            'body' => truncate_summary(issue_marker($page) . "

" . render_result_table($failures), ISSUE_BODY_LIMIT_BYTES),
            'labels' => $outputOnly ? [ISSUE_LABEL, ISSUE_OUTPUT_ONLY_LABEL] : [ISSUE_LABEL],
        ];
        $plan[] = $number === null
            ? ['action' => 'create'] + $issue
            : ['action' => 'update', 'number' => $number] + $issue;
    }
    return $plan;
}
