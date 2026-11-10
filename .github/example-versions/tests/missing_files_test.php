<?php

require_once dirname(__DIR__) . '/lib/missing-files.php';

function test_missing_file_path_extracts_path_from_common_errors(): void
{
    assert_same('/path/to/output.html', missing_file_path('Warning: Dom\HTMLDocument::saveHtmlFile(/path/to/output.html): Failed to open stream: No such file or directory in /tmp/ev-1-15/example.php on line 3'));
    assert_same('examples/book-simple.xml', missing_file_path('Warning: DOMDocument::load(): I/O warning : failed to load external entity "/tmp/ev-1-1/examples/book-simple.xml" in /tmp/ev-1-1/example.php on line 3'));
    assert_same(null, missing_file_path('Warning: Undefined variable $x in /tmp/ev-1-1/example.php on line 3'));
}

function test_missing_file_report_groups_pages_by_path_most_common_first(): void
{
    $failure = fn(string $page, string $stderr): array => ['page' => $page, 'status' => 'warning', 'stderr' => base64_encode($stderr), 'stdout' => ''];
    $report = missing_file_report([
        $failure('a', 'Warning: fopen(text.txt): Failed to open stream: No such file or directory'),
        $failure('b', 'Warning: fopen(/etc/x.ini): Failed to open stream: No such file or directory'),
        $failure('c', 'Warning: fopen(/etc/x.ini): Failed to open stream: No such file or directory'),
        ['page' => 'd', 'status' => 'pass', 'stderr' => base64_encode('Warning: fopen(skip.txt): Failed to open stream: No such file or directory'), 'stdout' => ''],
    ]);
    assert_same(['/etc/x.ini' => ['b', 'c'], 'text.txt' => ['a']], $report);
}
