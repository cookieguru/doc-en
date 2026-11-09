<?php

function test_resolve_tag_dot_zero_uses_newest_patch(): void
{
    $available = ['8.4.0', '8.4.13', '8.4.14', '8.5.1'];
    assert_same(['tag' => '8.4.14', 'substituted' => false], resolve_tag('8.4.0', $available));
}

function test_resolve_tag_patch_minimum_uses_exact_patch(): void
{
    assert_same(['tag' => '8.2.1', 'substituted' => false], resolve_tag('8.2.1', ['8.2.0', '8.2.1', '8.2.29']));
}

function test_resolve_tag_missing_patch_uses_next_patch_in_line(): void
{
    assert_same(['tag' => '5.3.29', 'substituted' => true], resolve_tag('5.3.27', ['5.3.29']));
}

function test_resolve_tag_rc_only_minor_uses_newest_rc(): void
{
    assert_same(['tag' => '8.6.0RC4', 'substituted' => false], resolve_tag('8.6.0', ['8.6.0RC3', '8.6.0RC4']));
}

function test_resolve_tag_prefers_release_over_rc(): void
{
    assert_same(['tag' => '8.6.0', 'substituted' => false], resolve_tag('8.6.0', ['8.6.0RC4', '8.6.0']));
}

function test_resolve_tag_no_images_for_minor_returns_null(): void
{
    assert_same(null, resolve_tag('8.7.0', ['8.6.0']));
}

function test_cli_versions_keeps_only_plain_cli_tags(): void
{
    assert_same(
        ['8.4.8', '8.6.0RC4'],
        cli_versions(['8.4.8-cli', '8.4.8-cli-alpine', '8.4-cli', '8.4.8-zts', '8.6.0RC4-cli', '8.4.8-cli']),
    );
}

function test_filter_by_minors_does_not_match_longer_minor(): void
{
    assert_same(['8.1.2', '5.3.29'], filter_by_minors(['8.1.2', '8.10.0', '5.3.29', '7.4.1'], ['8.1', '5.3']));
}

function test_next_page_path_reads_link_header(): void
{
    assert_same(
        '/v2/library/php/tags/list?last=8.4&n=10000',
        next_page_path(['HTTP/1.1 200 OK', 'link: </v2/library/php/tags/list?last=8.4&n=10000>; rel="next"']),
    );
    assert_same(null, next_page_path(['HTTP/1.1 200 OK']));
}
