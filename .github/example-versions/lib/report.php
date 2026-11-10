<?php

const REPORT_STATUSES = [
    'image-unavailable',
    'error',
    'timeout',
    'warning',
    'output-mismatch',
    'needs-service',
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
        $tag = $row['tag'] . (!empty($row['substituted']) ? ' (substituted)' : '')
            . (isset($row['fallback_tag']) ? ' (ran on ' . $row['fallback_tag'] . ')' : '');
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

function missing_tags(array $expectedTags, array $results): array
{
    $present = array_unique(array_column($results, 'tag'));
    $missing = array_values(array_diff(array_map('strval', $expectedTags), array_map('strval', $present)));
    usort($missing, 'compare_tags');
    return $missing;
}

function render_missing_tags(array $missingTags): string
{
    if (!$missingTags) {
        return '';
    }
    return "\n## Missing results\n\n" . implode('', array_map(fn($tag) => '* ' . report_cell($tag) . "\n", $missingTags));
}

function render_report(array $results, array $missingTags = []): string
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
    return $md . render_missing_tags($missingTags);
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
