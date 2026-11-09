<?php

function parse_minimum(string $from): ?string
{
    foreach (explode(',', $from) as $segment) {
        $segment = trim($segment);
        if (preg_match('/^PHP\b\s*(\d+(?:\.\d+)*)?\s*(?:>=\s*(\d+(?:\.\d+)*))?/', $segment, $matches)) {
            $lowerBound = ($matches[2] ?? '') !== '' ? $matches[2] : ($matches[1] ?? '');
            if ($lowerBound === '') {
                continue;
            }
            $parts = array_pad(explode('.', $lowerBound), 3, '0');
            return implode('.', array_slice($parts, 0, 3));
        }
    }
    return null;
}

function load_versions(string $referenceDir): array
{
    $versions = [];
    foreach (glob($referenceDir . '/*/versions.xml') as $file) {
        $directory = basename(dirname($file));
        $document = new DOMDocument();
        $document->load($file);
        foreach ($document->getElementsByTagName('function') as $function) {
            $from = $function->getAttribute('from');
            $name = strtolower($function->getAttribute('name'));
            $entry = ['from' => $from, 'minimum' => parse_minimum($from), 'directory' => $directory];
            if (!isset($versions[$name]) || is_higher_minimum($entry['minimum'], $versions[$name]['minimum'])) {
                $versions[$name] = $entry;
            }
        }
    }
    return $versions;
}

function is_higher_minimum(?string $candidate, ?string $current): bool
{
    if ($candidate === null) {
        return false;
    }
    return $current === null || version_compare($candidate, $current, '>');
}
