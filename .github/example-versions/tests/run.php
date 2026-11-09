<?php

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message === '' ? '' : $message . ': ')
            . 'expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}

foreach (glob(__DIR__ . '/../lib/*.php') as $file) {
    require_once $file;
}
foreach (glob(__DIR__ . '/*_test.php') as $file) {
    require_once $file;
}

$passed = 0;
$failed = 0;
foreach (get_defined_functions()['user'] as $function) {
    if (!str_starts_with($function, 'test_')) {
        continue;
    }
    try {
        $function();
        $passed++;
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "FAIL $function: {$e->getMessage()}\n");
    }
}

echo "$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
