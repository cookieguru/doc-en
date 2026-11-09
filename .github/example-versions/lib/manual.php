<?php

const DOCBOOK_NAMESPACE = 'http://docbook.org/ns/docbook';
const OLDEST_SUPPORTED_MINIMUM = '5.3.0';

function iterate_examples(string $manualPath): Generator
{
    $reader = new XMLReader();
    $reader->open($manualPath);

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'refentry') {
            continue;
        }
        $document = new DOMDocument();
        $document->appendChild($document->importNode($reader->expand(), true));
        yield from examples_of_refentry($document);
        $reader->next();
    }

    $reader->close();
}

function examples_of_refentry(DOMDocument $document): Generator
{
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('d', DOCBOOK_NAMESPACE);

    $page = $document->documentElement->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'id');
    $refnames = [];
    foreach ($xpath->query('//d:refname') as $refname) {
        $refnames[] = strtolower(trim($refname->textContent));
    }

    $position = 0;
    foreach ($xpath->query('//d:example') as $example) {
        $position++;
        $id = $example->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'id');
        $exampleId = $id !== '' ? $id : $page . '#' . $position;
        $listings = $xpath->query('.//d:programlisting[@role="php"]', $example);
        $screens = $xpath->query('.//d:screen', $example);

        foreach ($listings as $listing) {
            $single = $listings->length === 1;
            yield [
                'page' => $page,
                'refnames' => $refnames,
                'example' => $exampleId,
                'code' => trim($listing->textContent),
                'annotations' => $single ? $listing->getAttribute('annotations') : 'non-interactive',
                'expected' => $single && $screens->length === 1 ? trim($screens->item(0)->textContent) : null,
            ];
        }
    }
}

function build_records(iterable $examples, array $versions, array $extensionMap, ?array $only): array
{
    $records = [];

    foreach ($examples as $example) {
        $match = highest_minimum_match($example['refnames'], $versions);
        if ($match === null || version_compare($match['entry']['minimum'], OLDEST_SUPPORTED_MINIMUM, '<')) {
            continue;
        }
        $directory = $match['entry']['directory'];
        if ($only !== null && !array_intersect($only, [$directory, $example['page']])) {
            continue;
        }

        $records[] = [
            'page' => $example['page'],
            'refname' => $match['name'],
            'example' => $example['example'],
            'code' => $example['code'],
            'annotations' => $example['annotations'],
            'expected' => $example['expected'],
            'extension' => $extensionMap[$directory] ?? $directory,
            'directory' => $directory,
            'minimum' => $match['entry']['minimum'],
        ];
    }

    return $records;
}

function highest_minimum_match(array $refnames, array $versions): ?array
{
    $best = null;
    foreach ($refnames as $name) {
        $entry = $versions[$name] ?? null;
        if ($entry === null || $entry['minimum'] === null) {
            continue;
        }
        if ($best === null || version_compare($entry['minimum'], $best['entry']['minimum'], '>')) {
            $best = ['name' => $name, 'entry' => $entry];
        }
    }
    return $best;
}

function group_into_batches(array $records, array $available): array
{
    $batches = [];
    $unresolved = [];

    foreach ($records as $record) {
        $resolved = resolve_tag($record['minimum'], $available);
        if ($resolved === null) {
            $unresolved[] = $record;
            continue;
        }
        $tag = $resolved['tag'];
        $batches[$tag] ??= ['tag' => $tag, 'extensions' => [], 'examples' => []];
        $batches[$tag]['extensions'][] = $record['extension'];
        $batches[$tag]['examples'][] = $record + ['tag' => $tag, 'substituted' => $resolved['substituted']];
    }

    foreach ($batches as &$batch) {
        $batch['extensions'] = array_values(array_unique($batch['extensions']));
        sort($batch['extensions']);
    }
    unset($batch);

    return ['batches' => $batches, 'unresolved' => $unresolved];
}
