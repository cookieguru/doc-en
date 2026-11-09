<?php

function test_render_report_groups_by_status_then_extension(): void
{
    $results = [
        ['tag' => '8.4.14', 'page' => 'dom-parentnode.append', 'example' => 'e1', 'extension' => 'dom', 'status' => 'warning', 'substituted' => false,
         'stdout' => base64_encode(''), 'stderr' => base64_encode("\nWarning: Undefined property: Dom\HTMLElement::\$outerHTML in x\n"), 'expected' => null],
        ['tag' => '8.4.14', 'page' => 'dom-htmldocument.savehtmlfile', 'example' => 'e2', 'extension' => 'dom', 'status' => 'output-mismatch', 'substituted' => false,
         'stdout' => base64_encode("Saved 74 bytes\n"), 'stderr' => base64_encode(''), 'expected' => "Saved 60 bytes"],
        ['tag' => '5.3.29', 'page' => 'p', 'example' => 'e3', 'extension' => 'standard', 'status' => 'pass', 'substituted' => true,
         'stdout' => base64_encode("\xff"), 'stderr' => base64_encode(''), 'expected' => null],
    ];
    $md = render_report($results);
    assert_same(true, str_contains($md, "## warning\n\n### dom\n"));
    assert_same(true, str_contains($md, 'Warning: Undefined property: Dom\HTMLElement::$outerHTML in x'));
    assert_same(true, str_contains($md, '`Saved 60 bytes` → `Saved 74 bytes`'));
    assert_same(false, str_contains($md, '## pass'));
    assert_same(true, strpos($md, '| 5.3.29 |') < strpos($md, '| 8.4.14 |'));
}

function test_truncate_summary(): void
{
    $md = str_repeat("line\n", 100);
    $cut = truncate_summary($md, 52);
    assert_same(true, strlen($cut) < 52 + 60);
    assert_same(true, str_ends_with($cut, "_Truncated; see the report artifact._"));
    assert_same($md, truncate_summary($md, 10000));
}
