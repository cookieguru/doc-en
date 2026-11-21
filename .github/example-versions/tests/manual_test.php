<?php

function test_iterate_examples_reads_ids_code_and_screen(): void
{
    $examples = iterator_to_array(iterate_examples(FIXTURES . '/manual.xml'), false);
    $append = $examples[0];
    assert_same('dom-parentnode.append', $append['page']);
    assert_same(['dom\parentnode::append'], $append['refnames']);
    assert_same('dom-parentnode.append.example.basic', $append['example']);
    assert_same(true, str_contains($append['code'], '$list->outerHTML'));
    assert_same('', $append['annotations']);
    assert_same(true, str_contains($append['expected'], '<ul id="list">'));
    assert_same('dom-htmldocument.savehtmlfile#1', $examples[1]['example']);
}

function test_iterate_examples_multiple_listings_become_non_interactive_without_screen(): void
{
    $examples = iterator_to_array(iterate_examples(FIXTURES . '/manual.xml'), false);
    $multi = array_values(array_filter($examples, fn ($e) => $e['example'] === 'function.dup-func#2'));
    assert_same(2, count($multi));
    assert_same('non-interactive', $multi[0]['annotations']);
    assert_same(null, $multi[0]['expected']);
}

function test_build_records_skips_old_unknown_and_filters_only(): void
{
    $examples = iterate_examples(FIXTURES . '/manual.xml');
    $versions = load_versions(FIXTURES . '/reference');
    $records = build_records($examples, $versions, [], null);
    $pages = array_unique(array_column($records, 'page'));
    assert_same(['dom-parentnode.append', 'dom-htmldocument.savehtmlfile', 'function.dup-func', 'function.new-in-53'], array_values($pages));
    assert_same('dom', $records[0]['extension']);
    assert_same('8.4.0', $records[0]['minimum']);

    $filtered = build_records(iterate_examples(FIXTURES . '/manual.xml'), $versions, [], ['dom-parentnode.append']);
    assert_same(1, count($filtered));
}

function test_group_into_batches_collects_extensions_and_unresolved(): void
{
    $records = [
        ['minimum' => '8.4.0', 'extension' => 'dom', 'page' => 'a'],
        ['minimum' => '8.4.0', 'extension' => 'standard', 'page' => 'b'],
        ['minimum' => '8.7.0', 'extension' => 'dom', 'page' => 'c'],
    ];
    $grouped = group_into_batches($records, ['8.4.14']);
    assert_same(['dom', 'standard'], $grouped['batches']['8.4.14']['extensions']);
    assert_same(2, count($grouped['batches']['8.4.14']['examples']));
    assert_same('c', $grouped['unresolved'][0]['page']);
}

function test_group_into_latest_batches_runs_everything_on_the_newest_tag_of_each_minor(): void
{
    $records = [
        ['minimum' => '5.3.0', 'extension' => 'standard', 'page' => 'a'],
        ['minimum' => '8.5.0', 'extension' => 'dom', 'page' => 'b'],
        ['minimum' => '8.6.0', 'extension' => 'uri', 'page' => 'c'],
    ];
    $available = ['8.5.2', '8.5.13', '8.6.0RC1', '8.4.28'];
    $grouped = group_into_latest_batches($records, $available, ['8.5', '8.6']);

    assert_same(['8.5.13', '8.6.0RC1'], array_keys($grouped['batches']));
    assert_same(['a', 'b'], array_column($grouped['batches']['8.5.13']['examples'], 'page'));
    assert_same(['dom', 'standard'], $grouped['batches']['8.5.13']['extensions']);
    assert_same(['a', 'b', 'c'], array_column($grouped['batches']['8.6.0RC1']['examples'], 'page'));
    assert_same('8.6.0RC1', $grouped['batches']['8.6.0RC1']['examples'][2]['tag']);
    assert_same(false, $grouped['batches']['8.5.13']['examples'][0]['substituted']);
    assert_same([], $grouped['unresolved']);
}

function test_group_into_latest_batches_fails_when_a_minor_has_no_tag(): void
{
    try {
        group_into_latest_batches([['minimum' => '5.3.0', 'extension' => 'standard']], ['8.5.13'], ['8.7']);
    } catch (InvalidArgumentException $e) {
        assert_same('No PHP image tag found for 8.7', $e->getMessage());
        return;
    }
    throw new RuntimeException('Expected an InvalidArgumentException');
}

function test_iterate_examples_visits_adjacent_refentries(): void
{
    $entry = fn ($id) => "<refentry xml:id=\"$id\"><refnamediv><refname>$id</refname></refnamediv>"
        . '<example><programlisting role="php">echo 1;</programlisting></example></refentry>';
    $xml = '<book xmlns="http://docbook.org/ns/docbook" xmlns:xml="http://www.w3.org/XML/1998/namespace">'
        . $entry('a') . $entry('b') . $entry('c') . $entry('d') . '</book>';
    $path = tempnam(sys_get_temp_dir(), 'manual');
    file_put_contents($path, $xml);
    $pages = array_column(iterator_to_array(iterate_examples($path), false), 'page');
    unlink($path);
    assert_same(['a', 'b', 'c', 'd'], $pages);
}

function test_iterate_examples_flags_similar_output(): void
{
    $path = sys_get_temp_dir() . '/ev-similar-' . uniqid() . '.xml';
    file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<book xml:id="manual" xmlns="http://docbook.org/ns/docbook">
 <refentry xml:id="function.time">
  <refnamediv><refname>time</refname></refnamediv>
  <example>
   <programlisting role="php"><![CDATA[<?php echo time();]]></programlisting>
   <para>The above example will output something similar to:</para>
   <screen><![CDATA[1234]]></screen>
  </example>
  <example>
   <programlisting role="php"><![CDATA[<?php echo 1;]]></programlisting>
   <simpara>The above example will output:</simpara>
   <screen><![CDATA[1]]></screen>
  </example>
 </refentry>
</book>
XML);
    $examples = iterator_to_array(iterate_examples($path), false);
    unlink($path);
    assert_same([true, false], array_column($examples, 'similar'));
}

function test_build_records_expands_implied_extensions_into_batches(): void
{
    $records = build_records(iterate_examples(FIXTURES . '/manual.xml'), load_versions(FIXTURES . '/reference'), ['dom' => ['dom', 'libxml']], ['dom']);
    assert_same('dom', $records[0]['extension']);
    assert_same(['dom', 'libxml'], $records[0]['requires']);
    $grouped = group_into_batches($records, ['8.4.14']);
    assert_same(['dom', 'libxml'], $grouped['batches']['8.4.14']['extensions']);
}
