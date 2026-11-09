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
    return filter_by_minors(cli_versions(fetch_registry_tags()), $minors);
}

function filter_by_minors(array $versions, array $minors): array
{
    return array_values(array_filter(
        $versions,
        function (string $version) use ($minors): bool {
            foreach ($minors as $minor) {
                if (str_starts_with($version, $minor . '.')) {
                    return true;
                }
            }

            return false;
        },
    ));
}

function cli_versions(array $tags): array
{
    $versions = [];

    foreach ($tags as $tag) {
        if (preg_match('/^(\d+\.\d+\.\d+(?:RC\d+)?)-cli$/', $tag, $matches)) {
            $versions[] = $matches[1];
        }
    }

    return array_values(array_unique($versions));
}

function fetch_registry_tags(): array
{
    $registry = 'https://registry-1.docker.io';
    $token = fetch_json(
        'https://auth.docker.io/token?service=registry.docker.io&scope=repository:library/php:pull',
    )['token'];
    $path = '/v2/library/php/tags/list?n=10000';
    $tags = [];

    while ($path !== null) {
        $tags = [...$tags, ...fetch_json($registry . $path, ['Authorization: Bearer ' . $token])['tags']];
        $path = next_page_path(http_get_last_response_headers() ?? []);
    }

    return $tags;
}

function fetch_json(string $url, array $headers = []): array
{
    $context = stream_context_create(['http' => [
        'header' => implode("\r\n", ['User-Agent: example-versions', ...$headers]),
    ]]);
    $body = file_get_contents($url, false, $context);

    if ($body === false) {
        throw new RuntimeException('Failed to fetch ' . $url);
    }

    return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
}

function next_page_path(array $responseHeaders): ?string
{
    foreach ($responseHeaders as $header) {
        if (preg_match('/^Link:\s*<([^>]+)>/i', $header, $matches)) {
            return $matches[1];
        }
    }

    return null;
}
