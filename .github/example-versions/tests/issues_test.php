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
