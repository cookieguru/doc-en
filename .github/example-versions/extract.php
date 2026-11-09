<?php

require_once __DIR__ . '/lib/versions.php';
require_once __DIR__ . '/lib/tags.php';
require_once __DIR__ . '/lib/manual.php';

const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

function write_json(string $path, mixed $data): void
{
    file_put_contents($path, json_encode($data, JSON_FLAGS) . "\n");
}

function parse_only(?string $value): ?array
{
    $names = array_filter(array_map('trim', explode(',', $value ?? '')), fn (string $name): bool => $name !== '');
    return $names === [] ? null : array_values($names);
}

function minor_of(string $minimum): string
{
    return implode('.', array_slice(explode('.', $minimum), 0, 2));
}

function unresolved_result(array $record): array
{
    unset($record['code']);
    return $record + [
        'tag' => 'unresolved',
        'substituted' => false,
        'status' => 'image-unavailable',
        'exit' => null,
        'stdout' => '',
        'stderr' => '',
    ];
}

function main(array $options): void
{
    foreach (['manual', 'reference', 'out'] as $required) {
        if (!isset($options[$required])) {
            fwrite(STDERR, "Missing --$required\n");
            exit(2);
        }
    }

    $records = build_records(
        iterate_examples($options['manual']),
        load_versions($options['reference']),
        require __DIR__ . '/extensions.php',
        parse_only($options['only'] ?? null),
    );

    $available = isset($options['tags-file'])
        ? json_decode(file_get_contents($options['tags-file']), true, flags: JSON_THROW_ON_ERROR)
        : fetch_available_tags(array_values(array_unique(array_map(fn (array $record): string => minor_of($record['minimum']), $records))));

    $grouped = group_into_batches($records, $available);

    if (!is_dir($options['out'])) {
        mkdir($options['out'], 0777, true);
    }
    foreach ($grouped['batches'] as $tag => $batch) {
        write_json("{$options['out']}/batch-$tag.json", $batch);
    }
    if ($grouped['unresolved'] !== []) {
        write_json("{$options['out']}/results-unresolved.json", [
            'tag' => 'unresolved',
            'results' => array_map('unresolved_result', $grouped['unresolved']),
        ]);
    }
    write_json("{$options['out']}/tags.json", array_map('strval', array_keys($grouped['batches'])));

    printf("%d examples, %d tags, %d unresolved\n", count($records), count($grouped['batches']), count($grouped['unresolved']));
}

main(getopt('', ['manual:', 'reference:', 'out:', 'only::', 'tags-file:']));
