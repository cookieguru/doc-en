<?php

function test_parse_minimum_shapes(): void
{
    assert_same('5.3.2', parse_minimum('PHP 5 >= 5.3.2, PHP 7, PHP 8'));
    assert_same('7.0.0', parse_minimum('PHP 7, PHP 8'));
    assert_same('7.3.0', parse_minimum('PHP 7.3'));
    assert_same('5.0.0', parse_minimum('PHP 5, PHP 7 < 7.4.0'));
    assert_same('8.4.0', parse_minimum('PHP 8 >= 8.4.0'));
    assert_same('5.3.0', parse_minimum('PHP 5 >= 5.3.0, PHP 7, PHP 8, PECL intl >= 1.0.0'));
    assert_same('4.0.1', parse_minimum('PHP 4 >= 4.0.1, PHP 5'));
    assert_same(null, parse_minimum('PECL event >= 1.0.0-beta'));
}

function test_load_versions_reads_all_files_lowercased(): void
{
    $versions = load_versions(__DIR__ . '/../fixtures/reference');
    assert_same('8.4.0', $versions['dom\parentnode::append']['minimum']);
    assert_same('dom', $versions['dom\parentnode::append']['directory']);
    assert_same(null, $versions['event::add']['minimum']);
}

function test_load_versions_duplicate_name_keeps_highest_minimum(): void
{
    $versions = load_versions(__DIR__ . '/../fixtures/reference');
    assert_same('5.4.0', $versions['dup_func']['minimum']);
    assert_same('standard', $versions['dup_func']['directory']);
}
