<?php

function test_plan_issues_create_update_close_and_leave_alone(): void
{
    $fail = fn ($page, $status) => ['tag' => '8.4.14', 'page' => $page, 'example' => 'e', 'extension' => 'dom',
        'status' => $status, 'substituted' => false, 'stdout' => '', 'stderr' => base64_encode('Warning: x'), 'expected' => null];
    $results = [$fail('new-page', 'warning'), $fail('existing-page', 'output-mismatch'), $fail('fixed-page', 'pass'), $fail('skip-page', 'skipped-extension')];
    $open = [
        ['number' => 10, 'body' => "<!-- example-versions:existing-page -->\nold"],
        ['number' => 11, 'body' => "<!-- example-versions:fixed-page -->\nold"],
        ['number' => 12, 'body' => "<!-- example-versions:not-in-this-run -->\nold"],
    ];
    $plan = plan_issues($results, $open);
    $byPage = array_column($plan, null, 'page');

    assert_same('create', $byPage['new-page']['action']);
    assert_same('Example fails on minimum PHP version: new-page', $byPage['new-page']['title']);
    assert_same(true, str_starts_with($byPage['new-page']['body'], '<!-- example-versions:new-page -->'));
    assert_same(true, str_contains($byPage['new-page']['body'], '| Page | Example | Tag | Detail |'));
    assert_same(['example-versions'], $byPage['new-page']['labels']);

    assert_same('update', $byPage['existing-page']['action']);
    assert_same(10, $byPage['existing-page']['number']);
    assert_same(['example-versions', 'output-only'], $byPage['existing-page']['labels']);

    assert_same('close', $byPage['fixed-page']['action']);
    assert_same(false, isset($byPage['not-in-this-run']));
    assert_same(false, isset($byPage['skip-page']));
}

function test_plan_issues_caps_body_size(): void
{
    $results = [];
    for ($i = 0; $i < 2000; $i++) {
        $results[] = ['tag' => '8.4.14', 'page' => 'big-page', 'example' => "example-$i", 'extension' => 'dom',
            'status' => 'warning', 'substituted' => false, 'stdout' => '',
            'stderr' => base64_encode(str_repeat('Warning: long message ', 10)), 'expected' => null];
    }
    $body = plan_issues($results, [])[0]['body'];

    assert_same(true, strlen($body) < 65536);
    assert_same(true, str_starts_with($body, '<!-- example-versions:big-page -->'));
    assert_same(true, str_ends_with($body, '_Truncated; see the report artifact._'));
}

function issue_test_result(string $page, string $status): array
{
    return ['tag' => '8.4.14', 'page' => $page, 'example' => 'e', 'extension' => 'dom',
        'status' => $status, 'substituted' => false, 'stdout' => '', 'stderr' => '', 'expected' => null];
}

function test_plan_issues_unavailable_page_without_issue_creates_nothing(): void
{
    assert_same([], plan_issues([issue_test_result('gone', 'image-unavailable')], []));
}

function test_plan_issues_unavailable_page_keeps_open_issue(): void
{
    $open = [['number' => 20, 'body' => "<!-- example-versions:gone -->\nold"]];
    assert_same([], plan_issues([issue_test_result('gone', 'image-unavailable')], $open));
}

function test_plan_issues_skipped_page_keeps_open_issue(): void
{
    $open = [['number' => 21, 'body' => "<!-- example-versions:skipped -->\nold"]];
    assert_same([], plan_issues([issue_test_result('skipped', 'skipped-extension')], $open));
}

function test_plan_issues_closes_only_when_something_passed(): void
{
    $open = [['number' => 22, 'body' => "<!-- example-versions:mixed -->\nold"]];
    $plan = plan_issues([issue_test_result('mixed', 'pass'), issue_test_result('mixed', 'image-unavailable')], $open);
    assert_same('close', $plan[0]['action']);
}
