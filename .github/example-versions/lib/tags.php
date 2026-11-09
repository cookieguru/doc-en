<?php

function resolve_tag(string $minimum, array $available): ?array
{
    $minor = implode('.', array_slice(explode('.', $minimum), 0, 2));
    $inMinor = array_values(array_filter(
        $available,
        fn(string $version): bool => str_starts_with($version, $minor . '.'),
    ));
    usort($inMinor, 'version_compare');

    if (str_ends_with($minimum, '.0')) {
        return newest_tag($inMinor);
    }

    if (in_array($minimum, $inMinor, true)) {
        return ['tag' => $minimum, 'substituted' => false];
    }

    foreach ($inMinor as $version) {
        if (version_compare($version, $minimum, '>')) {
            return ['tag' => $version, 'substituted' => true];
        }
    }

    return null;
}

function newest_tag(array $sortedVersions): ?array
{
    $releases = array_filter(
        $sortedVersions,
        fn(string $version): bool => !str_contains($version, 'RC'),
    );
    $newest = end($releases) ?: end($sortedVersions);

    return $newest === false ? null : ['tag' => $newest, 'substituted' => false];
}

function fetch_available_tags(array $minors): array
{
    $context = stream_context_create(['http' => ['header' => 'User-Agent: example-versions']]);
    $versions = [];

    foreach ($minors as $minor) {
        $url = 'https://hub.docker.com/v2/repositories/library/php/tags?page_size=100&name=' . urlencode($minor . '.');

        while ($url !== null) {
            $page = json_decode(file_get_contents($url, false, $context), true, flags: JSON_THROW_ON_ERROR);

            foreach ($page['results'] as $result) {
                if (preg_match('/^(\d+\.\d+\.\d+(?:RC\d+)?)-cli$/', $result['name'], $matches)) {
                    $versions[] = $matches[1];
                }
            }

            $url = $page['next'] ?? null;
        }
    }

    return array_values(array_unique($versions));
}
