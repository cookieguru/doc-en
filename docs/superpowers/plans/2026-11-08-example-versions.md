# Example Version Check Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A fork-only, manually dispatched GitHub Actions workflow that runs
every manual example on the minimum PHP version of the symbol it documents
and reports (and optionally files issues for) the failures.

**Architecture:** `extract.php` streams the built `.manual.xml`, matches
refentries to `versions.xml`, and writes one JSON batch per Docker image tag.
A matrix job per tag installs extensions, then runs `runner.php` (PHP 5.3
syntax) inside `php:<tag>-cli` with no network. `report.php` merges results
into a Markdown summary and artifact; `sync-issues.php` reconciles issues.

**Tech Stack:** PHP 8.5 on the host (`shivammathur/setup-php`), PHP 5.3+
syntax in the container, `XMLReader` + DOM, Docker official `php` images,
`mlocati/docker-php-extension-installer`, `gh` CLI, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-11-08-example-versions-design.md`

## Global Constraints

* Fork-only. Every job has `if: github.repository == 'cookieguru/doc-en'`.
* Trigger is `workflow_dispatch` only.
* Symbols with a minimum below 5.3.0, PECL-only symbols, and pages with no
  `versions.xml` entry are skipped.
* Container-side code (`runner.php`, `lib/runner-lib.php`) must be valid
  PHP 5.3 syntax: `array()` only, no `[]`, no `::class`,
  no closures using `$this`, no variadics, no scalar type declarations, no
  return types, no `??`, no `finally`, no `yield`, no `PHP_BINARY`.
* Host-side code targets PHP 8.5 (matches `docbook-cs.yaml`).
* Actions are pinned to exact versions (`actions/checkout@v7.0.1`,
  `shivammathur/setup-php@2.40.0`, `actions/upload-artifact@v7.0.2`;
  `actions/download-artifact` pinned to its latest release tag), and the
  workflow is added to `.github/workflows/actions.lock`.
* Execution command:
  `timeout 10 php -d error_reporting=-1 -d display_errors=stderr -d html_errors=0 -d log_errors=0 example.php`.
* Statuses (exact strings): `image-unavailable`, `error`, `timeout`,
  `warning`, `output-mismatch`, `pass`, `skipped-extension`.
* Issue label `example-versions`; extra label `output-only`; body marker
  `<!-- example-versions:<page-id> -->`.
* Code style: self-documenting code; minimal comments.

## Review Focus

1. **Huge `.manual.xml`.** The built manual is hundreds of MB; loading it with
   `DOMDocument::load` would exhaust memory. Expect streaming via
   `XMLReader`, one refentry expanded at a time. (Task 3 test streams a
   fixture through the same iterator.)
2. **Non-UTF-8 or binary example output** (e.g. `gd`, `random_bytes`). Expect
   base64 transport and a report that does not crash rendering it. (Task 4
   and Task 5 tests.)
3. **An extension that cannot install on an old image** (5.3/5.4 images have
   dead apt mirrors). Expect `skipped-extension` for those examples and the
   rest of the batch to run. (Task 4 test; Task 7 install loop is per
   extension.)
4. **Docker Hub has no tag at all for a minor** (e.g. 8.6 before any RC).
   Expect those examples reported as `image-unavailable`, not dropped
   silently. (Task 2 and Task 3 tests.)
5. **Example that waits on stdin or loops forever.** Expect `timeout`, not a
   hung job. (Task 4 test with `while (true) {}`.)

## File Structure

All under `.github/example-versions/` unless noted.

| File | Responsibility |
|---|---|
| `lib/versions.php` | Parse `from` attributes; load all `versions.xml` into a name → entry map |
| `lib/tags.php` | Choose an image tag for a minimum version; fetch tags from Docker Hub |
| `lib/manual.php` | Stream `.manual.xml`; yield example records per refentry |
| `lib/runner-lib.php` | PHP 5.3: output normalization and status classification |
| `lib/report.php` | Render Markdown report; plan issue actions |
| `extensions.php` | `reference/<dir>` → PHP extension name map |
| `extract.php` | CLI: build batches + unresolved results |
| `runner.php` | CLI (PHP 5.3): run a batch, or mark it unavailable |
| `report.php` | CLI: merge results, write summary/report/artifacts |
| `sync-issues.php` | CLI: apply the issue plan with `gh` |
| `tests/run.php` | Minimal test harness |
| `tests/*_test.php` | Tests per lib file |
| `fixtures/` | Fixture manual, versions files, and end-to-end examples |
| `../workflows/example-versions.yaml` | The workflow |

### Data formats (shared by all tasks)

Example record (array):

```
page        string  refentry xml:id
refname     string  refname that set the minimum
example     string  example xml:id, or "<page>#<n>" (1-based)
code        string  program listing text
annotations string  programlisting annotations attribute ("" if none)
expected    ?string screen text, or null
extension   string  PHP extension name from extensions.php
directory   string  reference/<dir> name
minimum     string  "X.Y.Z"
tag         string  e.g. "8.4.14" or "8.6.0RC4" (image is "php:<tag>-cli")
substituted bool
```

Batch file `batch-<tag>.json`:
`{"tag": "<tag>", "extensions": [<ext names, sorted, unique>], "examples": [<records>]}`

Results file `results-<tag>.json`:
`{"tag": "<tag>", "results": [<record without "code", plus "status", "exit" (int|null), "stdout" (base64), "stderr" (base64)>]}`

`extract.php` writes unresolvable examples to `results-unresolved.json` with
`tag` = `"unresolved"` and every result `status` = `image-unavailable`.

---

### Task 1: Test harness and `versions.xml` parsing

**Files:**
- Create: `.github/example-versions/tests/run.php`
- Create: `.github/example-versions/lib/versions.php`
- Create: `.github/example-versions/fixtures/reference/dom/versions.xml`
- Create: `.github/example-versions/fixtures/reference/standard/versions.xml`
- Test: `.github/example-versions/tests/versions_test.php`

**Interfaces:**
- Produces:
  - `tests/run.php`: requires every `tests/*_test.php`, calls every
    function named `test_*`, prints `N passed, M failed`, exits 1 on any
    failure. Helpers: `assert_same(mixed $expected, mixed $actual, string $message = ''): void`
    (throws on mismatch, message shows both via `var_export`).
  - `parse_minimum(string $from): ?string` returns `"X.Y.Z"` or null (no
    PHP segment).
  - `load_versions(string $referenceDir): array<string, array{from: string, minimum: ?string, directory: string}>`
    keyed by lowercased `name`; when a name appears in several files, keep
    the entry with the highest minimum.

- [ ] **Step 1: Write the harness and failing tests**

`tests/versions_test.php`:

```php
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
```

Fixture `dom/versions.xml` contains `Dom\ParentNode::append` (`PHP 8 &gt;= 8.4.0`),
`event::add` (`PECL event &gt;= 1.0.0`), `dup_func` (`PHP 5 &gt;= 5.3.0, PHP 7`).
Fixture `standard/versions.xml` contains `dup_func` (`PHP 5 &gt;= 5.4.0, PHP 7`).
Fixture directories are named after real extensions so the end-to-end
fixture run in Task 7 installs and checks real extensions.
Both use the real file's `<versions>` root and `<function name from>` children.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php .github/example-versions/tests/run.php`
Expected: FAIL with "Call to undefined function parse_minimum()"

- [ ] **Step 3: Implement `lib/versions.php`**

Take only `PHP …` segments after splitting on `,`; the first one's lower
bound is `>= X.Y.Z` if present, else its bare `PHP N` or `PHP N.N`, padded
to three parts. Load files with `DOMDocument` (they have no external
entities). `tests/run.php` requires every `lib/*.php` except
`runner-lib.php`, which it also requires (it is plain functions).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php .github/example-versions/tests/run.php`
Expected: `3 passed, 0 failed`

- [ ] **Step 5: Commit**

```bash
git add .github/example-versions
git commit -m "Add versions.xml parsing for example version check"
```

---

### Task 2: Image tag resolution

**Files:**
- Create: `.github/example-versions/lib/tags.php`
- Test: `.github/example-versions/tests/tags_test.php`

**Interfaces:**
- Produces:
  - `resolve_tag(string $minimum, array $available): ?array` where
    `$available` is a list of version strings (`"8.4.14"`, `"8.6.0RC4"`)
    and the return is `['tag' => string, 'substituted' => bool]` or null.
  - `fetch_available_tags(array $minors): array` takes `["8.4", "5.3", …]`
    and returns the version list for `resolve_tag`, from
    `https://hub.docker.com/v2/repositories/library/php/tags?page_size=100&name=<X.Y.>`
    following `next`, keeping names matching
    `/^(\d+\.\d+\.\d+(?:RC\d+)?)-cli$/` and returning capture group 1.

- [ ] **Step 1: Write failing tests**

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php .github/example-versions/tests/run.php`
Expected: FAIL with "Call to undefined function resolve_tag()"

- [ ] **Step 3: Implement `lib/tags.php`**

Filter `$available` to the same `X.Y`; compare with `version_compare`
(which orders `8.6.0RC4` < `8.6.0`). For `X.Y.0`: newest non-RC, else
newest RC. For `X.Y.Z` (Z > 0): exact, else the lowest version greater than
`X.Y.Z` with `substituted` true. `fetch_available_tags` uses
`file_get_contents` with a `User-Agent: example-versions` stream context; it
is not unit tested.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php .github/example-versions/tests/run.php`
Expected: `9 passed, 0 failed`

- [ ] **Step 5: Commit**

```bash
git add .github/example-versions
git commit -m "Add Docker image tag resolution for example version check"
```

---

### Task 3: Manual streaming, extension map, and `extract.php`

**Files:**
- Create: `.github/example-versions/lib/manual.php`
- Create: `.github/example-versions/extensions.php`
- Create: `.github/example-versions/extract.php`
- Create: `.github/example-versions/fixtures/manual.xml`
- Test: `.github/example-versions/tests/manual_test.php`

**Interfaces:**
- Consumes: `load_versions()`, `resolve_tag()`, `fetch_available_tags()`.
- Produces:
  - `iterate_examples(string $manualPath): Generator` yielding
    `['page', 'refnames' (list, lowercased), 'example', 'code', 'annotations', 'expected']`.
  - `build_records(iterable $examples, array $versions, array $extensionMap, ?array $only): array`
    returning a list of records (data format above, without `tag` and
    `substituted`). `$only` is a list of directory names or page ids, or
    null for no filter.
  - `group_into_batches(array $records, array $available): array` returning
    `['batches' => array<string tag, batch>, 'unresolved' => list<record>]`.
  - `extensions.php` returns `array<string directory, string extension>`.
  - CLI: `php extract.php --manual=<path> --reference=<dir> --out=<dir> [--only=a,b] [--tags-file=<json list>]`
    writes `batch-<tag>.json`, `results-unresolved.json` (only if any), and
    `tags.json` (JSON list of tags, for the matrix). `--tags-file` replaces
    the Docker Hub fetch.

- [ ] **Step 1: Build fixture manual**

`fixtures/manual.xml`: a DocBook-namespaced root (`xmlns="http://docbook.org/ns/docbook"`)
containing refentries:

* `dom-parentnode.append`: refname `Dom\ParentNode::append`; one example
  with `xml:id="dom-parentnode.append.example.basic"` holding the
  **pre-review** code (`echo $list->outerHTML, "\n";`) and its screen.
* `dom-htmldocument.savehtmlfile`: refname
  `Dom\HTMLDocument::saveHtmlFile`; one example without xml:id holding the
  pre-review code and the "Saved 60 bytes / int(60)" screen.
* `function.dup-func`: refname `dup_func`; two examples:
  * one `annotations="non-interactive"`, no screen
  * one with two `role="php"` program listings
* `function.new-in-53`: refname `new_in_53`; one example `<?php echo strlen('abc');`
  with screen `3`.
* `function.old-func`: refname `old_func` (minimum below 5.3.0).
* `function.no-versions`: refname `nowhere`.

Add `dom\htmldocument::savehtmlfile` (`PHP 8 &gt;= 8.4.0`) to
`fixtures/reference/dom/versions.xml`, and `new_in_53` (`PHP 5 &gt;= 5.3.0, PHP 7`)
and `old_func` (`PHP 4 &gt;= 4.0.1, PHP 5`) to
`fixtures/reference/standard/versions.xml`.

- [ ] **Step 2: Write failing tests**

```php
function test_iterate_examples_reads_ids_code_and_screen(): void
{
    $examples = iterator_to_array(iterate_examples(FIXTURES . '/manual.xml'), false);
    $append = $examples[0];
    assert_same('dom-parentnode.append', $append['page']);
    assert_same(['dom\parentnode::append'], $append['refnames']);
    assert_same('dom-parentnode.append.example.basic', $append['example']);
    assert_same(true, str_contains($append['code'], '$list->outerHTML'));
    assert_same('', $append['annotations']);
    assert_same(true, str_contains($append['expected'], '<ul>'));
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
```

`FIXTURES` is defined in `tests/run.php` as the absolute fixtures path.

- [ ] **Step 3: Run tests to verify they fail**

Run: `php .github/example-versions/tests/run.php`
Expected: FAIL with "Call to undefined function iterate_examples()"

- [ ] **Step 4: Implement `lib/manual.php`**

Stream with `XMLReader`; on each `refentry` element, `expand()` into a
`DOMDocument` and query with `DOMXPath` (register the DocBook namespace).
An example's php listings are its descendant `programlisting[@role="php"]`.
With exactly one listing: one record, `expected` is the text of the
example's first `screen` (null if none or more than one). With several
listings: one record per listing, all sharing the example id, with
`annotations` forced to `non-interactive` and `expected` null. Records with
the same example id are fine; the runner keys by position.

`build_records`: a page's minimum is the highest `minimum` among its matched
refnames; skip if no refname matches, if that minimum is null, or if it is
below `5.3.0`. `extension` is `$extensionMap[$directory] ?? $directory`.

- [ ] **Step 5: Implement `extensions.php`**

Generate the map once with a throwaway script (not committed):

1. For each `reference/<dir>/versions.xml`, take its first `function` name.
2. Ask local PHP for the owning extension with
   `ReflectionFunction`/`ReflectionClass`/`ReflectionMethod` and
   `getExtensionName()`, lowercased.

Fill unresolvable dirs by hand: the dir name if it is a PECL extension the
installer supports, otherwise the extension named in that dir's
`book.xml`/`setup.xml`. Commit the result as a sorted, literal `return [...]`.
Known anchors: `image` → `gd`, `strings` → `standard`, `dom` → `dom`.

- [ ] **Step 6: Implement `extract.php`**

Parse options with `getopt`; collect the `X.Y` of every record's minimum for
`fetch_available_tags`, unless `--tags-file` is given. Write the files
listed in the Interfaces block with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`.
Unresolved records go into `results-unresolved.json` with status
`image-unavailable`, `exit` null, and empty base64 `stdout`/`stderr`.
Print one summary line: `<n> examples, <m> tags, <u> unresolved`.

- [ ] **Step 7: Run tests to verify they pass, then smoke the CLI**

Run: `php .github/example-versions/tests/run.php`
Expected: `13 passed, 0 failed`

Run:
```bash
echo '["8.4.14"]' > "$TMPDIR/tags.json"
php .github/example-versions/extract.php --manual=.github/example-versions/fixtures/manual.xml \
  --reference=.github/example-versions/fixtures/reference --out="$TMPDIR/out" --tags-file="$TMPDIR/tags.json"
```
Expected: `6 examples, 1 tags, 4 unresolved`. The 4 unresolved are
`dup_func`'s three records at 5.4.0 and `new_in_53` at 5.3.0, none of which
has an 8.4 tag. You should also see a `batch-8.4.14.json` and a `tags.json`
containing `["8.4.14"]`.

- [ ] **Step 8: Commit**

```bash
git add .github/example-versions
git commit -m "Add example extraction for example version check"
```

---

### Task 4: Runner (PHP 5.3 syntax)

**Files:**
- Create: `.github/example-versions/lib/runner-lib.php`
- Create: `.github/example-versions/runner.php`
- Test: `.github/example-versions/tests/runner_test.php`

**Interfaces:**
- Consumes: batch file format.
- Produces:
  - `normalize_output($text)` returns string. Applies CRLF → LF, strips
    trailing whitespace on each line, and trims leading and trailing blank
    lines.
  - `classify($exitCode, $stdout, $stderr, $expected, $annotations)`
    returns a status string. `$exitCode` is int; `$expected` is a string or
    null.
  - CLI:
    * `php runner.php run <batch.json> <results.json>`
    * `php runner.php unavailable <batch.json> <results.json>`: marks every
      example `image-unavailable`.

- [ ] **Step 1: Write failing tests**

```php
function test_classify_statuses(): void
{
    assert_same('error', classify(255, '', "PHP Fatal error:  Uncaught Error", null, ''));
    assert_same('error', classify(0, '', "Parse error: syntax error", null, ''));
    assert_same('timeout', classify(124, '', '', null, ''));
    assert_same('warning', classify(0, "x\n", "Warning: Undefined property: Dom\\HTMLElement::\$outerHTML", "x", ''));
    assert_same('output-mismatch', classify(0, "Saved 74 bytes\nint(74)\n", '', "Saved 60 bytes\nint(60)", ''));
    assert_same('pass', classify(0, "a  \r\nb\r\n\r\n", '', "\na\nb", ''));
    assert_same('pass', classify(0, "anything", '', "different", 'non-interactive'));
    assert_same('pass', classify(0, "anything", '', null, ''));
}

function test_runner_cli_end_to_end_on_host_php(): void
{
    $dir = sys_get_temp_dir() . '/ev-runner-' . uniqid();
    mkdir($dir);
    $batch = ['tag' => 'host', 'extensions' => ['standard', 'no_such_ext'], 'examples' => [
        ['page' => 'p', 'example' => 'ok', 'code' => "<?php echo 'hi';", 'annotations' => '', 'expected' => 'hi', 'extension' => 'standard'],
        ['page' => 'p', 'example' => 'loop', 'code' => "<?php while (true) {}", 'annotations' => '', 'expected' => null, 'extension' => 'standard'],
        ['page' => 'p', 'example' => 'bin', 'code' => "<?php echo \"\\xff\\xfe\";", 'annotations' => '', 'expected' => null, 'extension' => 'standard'],
        ['page' => 'p', 'example' => 'lint', 'code' => "<?php exit(3);", 'annotations' => 'non-interactive', 'expected' => null, 'extension' => 'standard'],
        ['page' => 'p', 'example' => 'missing', 'code' => "<?php", 'annotations' => '', 'expected' => null, 'extension' => 'no_such_ext'],
    ]];
    file_put_contents("$dir/batch.json", json_encode($batch));
    exec(PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__) . '/runner.php') . " run $dir/batch.json $dir/results.json", $out, $code);
    assert_same(0, $code);
    $results = json_decode(file_get_contents("$dir/results.json"), true)['results'];
    assert_same(['pass', 'timeout', 'pass', 'pass', 'skipped-extension'], array_column($results, 'status'));
    assert_same("\xff\xfe", base64_decode($results[2]['stdout']));
    assert_same(false, array_key_exists('code', $results[0]));
}
```

The end-to-end test needs the `timeout` command. Skip it with a printed
`SKIP` if `timeout --version` fails, as it does on Windows without
coreutils on PATH. Git Bash provides `timeout`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php .github/example-versions/tests/run.php`
Expected: FAIL with "Call to undefined function classify()"

- [ ] **Step 3: Implement `lib/runner-lib.php` and `runner.php`**

**Constraint:** PHP 5.3 syntax (see Global Constraints).

`classify`, in order:
1. If `$exitCode` is 124, return `timeout`.
2. If `$exitCode` is nonzero, or stderr matches `/(Fatal|Parse) error/`,
   return `error`.
3. If trimmed stderr isn't empty, return `warning`.
4. If `$annotations` contains `non-interactive`, or `$expected` is null,
   return `pass`.
5. Otherwise compare the normalized output with the normalized expected
   text.

`runner.php run`, per example:
* If `!extension_loaded($e['extension'])`, the status is
  `skipped-extension`.
* Otherwise write the code to `<tmp>/ev-<n>/example.php` and set the
  working directory to that folder.
* Run the exact command from Global Constraints with `proc_open`, or use
  `php -l example.php` for `non-interactive` examples. Pass
  `php` from PATH. stdin is `/dev/null`. Read
  stdout and stderr to EOF; a pipe deadlock isn't a concern because
  `timeout` bounds the run.
* Capture the exit code from `proc_close`.
* Store stdout and stderr base64-encoded.

`timeout` makes `proc_close` return 124. Drop `code` from each result.
Write the results with `json_encode` (no flags, since 5.3 lacks most of
them).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php .github/example-versions/tests/run.php`
Expected: `15 passed, 0 failed`

- [ ] **Step 5: Commit**

```bash
git add .github/example-versions
git commit -m "Add PHP 5.3-compatible runner for example version check"
```

---

### Task 5: Report rendering

**Files:**
- Create: `.github/example-versions/lib/report.php`
- Create: `.github/example-versions/report.php`
- Test: `.github/example-versions/tests/report_test.php`

**Interfaces:**
- Consumes: results file format.
- Produces:
  - `load_results(string $dir): list<result>` merges every
    `results-*.json` file found recursively, since downloaded artifacts
    land in subdirectories, and adds `tag` to each result.
  - `render_report(array $results): string` returns Markdown with:
    1. `## Summary`: a status × tag count table. Statuses run as columns in
       the severity order from Global Constraints; tags are rows sorted
       with `version_compare`.
    2. A `## <status>` section for each non-`pass` status present, in
       severity order. Inside each, a `### <extension>` subsection holds a
       table: `| Page | Example | Tag | Detail |`. The Detail cell is:
       * the first nonempty stderr line, or
       * for `output-mismatch`, the first differing line as
         `` `expected` → `actual` ``, or
       * empty.
       When the tag was substituted, the Tag cell shows `<tag> (substituted)`.
  - `truncate_summary(string $markdown, int $limitBytes): string` cuts at
    the last line break before the limit and appends
    `\n\n_Truncated; see the report artifact._`.
  - CLI: `php report.php <results-dir> <out-dir>` writes
    `<out-dir>/report.md` and `<out-dir>/results.json`. If
    `GITHUB_STEP_SUMMARY` is set, it appends
    `truncate_summary($md, 900000)` to that file.

- [ ] **Step 1: Write failing tests**

```php
function test_render_report_groups_by_status_then_extension(): void
{
    $results = [
        ['tag' => '8.4.14', 'page' => 'dom-parentnode.append', 'example' => 'e1', 'extension' => 'dom', 'status' => 'warning', 'substituted' => false,
         'stdout' => base64_encode(''), 'stderr' => base64_encode("\nWarning: Undefined property: Dom\\HTMLElement::\$outerHTML in x\n"), 'expected' => null],
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php .github/example-versions/tests/run.php`
Expected: FAIL with "Call to undefined function render_report()"

- [ ] **Step 3: Implement `lib/report.php` and `report.php`**

Escape `|` in table cells and wrap Detail in code spans. Treat every
decoded stdout and stderr as opaque bytes: run them through
`mb_scrub($s, 'UTF-8')` before rendering, so invalid UTF-8 can't break
`json_encode` or Markdown.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php .github/example-versions/tests/run.php`
Expected: `17 passed, 0 failed`

- [ ] **Step 5: Commit**

```bash
git add .github/example-versions
git commit -m "Add report rendering for example version check"
```

---

### Task 6: Issue planning and sync

**Files:**
- Modify: `.github/example-versions/lib/report.php`
- Create: `.github/example-versions/sync-issues.php`
- Test: `.github/example-versions/tests/issues_test.php`

**Interfaces:**
- Consumes: `load_results()`, the results format.
- Produces:
  - `plan_issues(array $results, array $openIssues): list<array>`.
    * `$openIssues` is a list of `['number' => int, 'body' => string]`.
    * Each returned action is one of:
      * `['action' => 'create', 'page', 'title', 'body', 'labels']`
      * `['action' => 'update', 'number', 'page', 'title', 'body', 'labels']`
      * `['action' => 'close', 'number', 'page']`
    * A page fails if any of its results is something other than `pass` or
      `skipped-extension`.
    * Title: `Example fails on minimum PHP version: <page>`.
    * The body starts with `<!-- example-versions:<page> -->`, followed by
      that page's failing rows in the `render_report` table format.
    * Labels are `['example-versions']`. Add `'output-only'` when every
      failure on the page is `output-mismatch`.
    * Open issues whose marker page has results but no failures get
      `close`. Pages absent from the results, for example because of an
      `only` filter, are left alone.
  - CLI: `php sync-issues.php <results-dir> <repo>`. It:
    1. Runs `gh label create <name> --force -R <repo>` for both labels.
    2. Lists open issues with
       `gh issue list -R <repo> --label example-versions --state open --limit 1000 --json number,body`.
    3. Applies the plan:
       * create: `gh issue create`
       * update: `gh issue edit --title --body-file --add-label --remove-label output-only` as needed
       * close: `gh issue close --comment "Examples now pass on their minimum version."`
    4. Prints one line per action.

- [ ] **Step 1: Write failing tests**

```php
function test_plan_issues_create_update_close_and_leave_alone(): void
{
    $fail = fn ($page, $status) => ['tag' => '8.4.14', 'page' => $page, 'example' => 'e', 'extension' => 'dom',
        'status' => $status, 'substituted' => false, 'stdout' => '', 'stderr' => base64_encode('Warning: x'), 'expected' => null];
    $results = [$fail('new-page', 'warning'), $fail('existing-page', 'output-mismatch'), $fail('fixed-page', 'pass'), $fail('skip-page', 'skipped-extension')];
    $open = [
        ['number' => 10, 'body' => "<!-- example-versions:existing-page -->\nold"],
        ['number' => 11, 'body' => "<!-- example-versions:fixed-page -->\nold"],
        ['number' => 12, 'body' => "<!-- example-versions:not-in-this-run -->\nold"],
    ];
    $plan = plan_issues($results, $open);
    $byPage = array_column($plan, null, 'page');

    assert_same('create', $byPage['new-page']['action']);
    assert_same('Example fails on minimum PHP version: new-page', $byPage['new-page']['title']);
    assert_same(true, str_starts_with($byPage['new-page']['body'], '<!-- example-versions:new-page -->'));
    assert_same(['example-versions'], $byPage['new-page']['labels']);

    assert_same('update', $byPage['existing-page']['action']);
    assert_same(10, $byPage['existing-page']['number']);
    assert_same(['example-versions', 'output-only'], $byPage['existing-page']['labels']);

    assert_same('close', $byPage['fixed-page']['action']);
    assert_same(false, isset($byPage['not-in-this-run']));
    assert_same(false, isset($byPage['skip-page']));
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php .github/example-versions/tests/run.php`
Expected: FAIL with "Call to undefined function plan_issues()"

- [ ] **Step 3: Implement `plan_issues` and `sync-issues.php`**

Run `gh` through `proc_open` with an argument array (no shell). Pass bodies
via a temp file with `--body-file`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php .github/example-versions/tests/run.php`
Expected: `18 passed, 0 failed`

- [ ] **Step 5: Commit**

```bash
git add .github/example-versions
git commit -m "Add issue sync for example version check"
```

---

### Task 7: Workflow, lock file, and end-to-end fixture run

**Files:**
- Create: `.github/workflows/example-versions.yaml`
- Modify: `.github/workflows/actions.lock`

**Interfaces:**
- Consumes the CLIs from Tasks 3–6.

**Prerequisite (needs the user):** `workflow_dispatch` only works for
workflows on the repository's default branch. The fork's default branch is
`master`, which is the head branch of PR #6042. Before Step 4, ask the user
how to get the workflow onto the default branch. Do not push to `master`.

- [ ] **Step 1: Write the workflow**

The workflow is named `"Example Versions"`.

**Inputs:**
* `open_issues`: boolean, default false.
* `only`: string, default `""`.
* `fixtures_only`: boolean, default false. When true, it uses
  `fixtures/manual.xml` and `fixtures/reference` and skips the manual
  build.

**Permissions:** top-level `contents: read`. The workflow uses checkout,
setup-php and the artifact actions pinned as in the existing workflows.

**Jobs.** Each job has `if: github.repository == 'cookieguru/doc-en'`.

1. **`extract`** (`ubuntu-latest`):
   1. Check out this repo into `en` and `php/doc-base` into `doc-base`.
   2. Set up PHP 8.5 with `dom, libxml, simplexml, xmlreader, mbstring`.
   3. Unless `fixtures_only` is set, run the same `configure.php` command
      as `docbook-cs.yaml`.
   4. Run `php en/.github/example-versions/extract.php --manual=doc-base/.manual.xml --reference=en/reference --out=out --only="${{ inputs.only }}"`.
      Use the fixture paths instead when `fixtures_only` is set.
   5. Upload `out/` as the artifact `batches`.
   6. Set output `tags` to the contents of `out/tags.json`.
2. **`run`**:
   * `needs: extract`.
   * `if: needs.extract.outputs.tags != '[]'`.
   * Matrix `tag: ${{ fromJSON(needs.extract.outputs.tags) }}`.
   * `fail-fast: false`, `max-parallel: 20`, `timeout-minutes: 120`.

   Steps:
   1. Check out into `en`.
   2. Download `batches`.
   3. Pull the image:
      ```
      if docker pull php:${{ matrix.tag }}-cli; then echo ok=1 >> "$GITHUB_OUTPUT"; fi
      ```
   4. If the pull succeeded, start a container `ev` from the image with
      network access, `sleep infinity`, and the extension installer
      mounted. Pin the installer to its latest release by tag, downloaded
      from `releases/download/<tag>/install-php-extensions`. For each name
      in the batch's `extensions`, run
      `docker exec ev install-php-extensions <name> || echo "::warning::could not install <name> on ${{ matrix.tag }}"`.
      Then `docker commit ev ev-image` and `docker rm -f ev`.
   5. Run
      `docker run --rm --network none -v "$PWD/en/.github/example-versions:/ev:ro" -v "$PWD/batches:/batches:ro" -v "$PWD/results:/results" ev-image php /ev/runner.php run /batches/batch-${{ matrix.tag }}.json /results/results-${{ matrix.tag }}.json`.
   6. If the pull failed, set up PHP 8.5 instead and run
      `php en/.github/example-versions/runner.php unavailable …` on the
      host.
   7. Upload `results/` as `results-${{ matrix.tag }}`.
3. **`report`**:
   * `needs: [extract, run]`.
   * `if: always()` combined with the repository guard.

   Steps:
   1. Check out into `en` and set up PHP 8.5.
   2. Download all artifacts matching `results-*`, plus `batches` for
      `results-unresolved.json`, into `all/`.
   3. Run `php en/.github/example-versions/report.php all report`.
   4. Upload `report/` as `report`.
   5. When `inputs.open_issues` is true, run
      `php en/.github/example-versions/sync-issues.php all ${{ github.repository }}`
      with `GH_TOKEN: ${{ github.token }}`.

   This job alone gets `permissions: contents: read, issues: write`.

- [ ] **Step 2: Update the lock file**

Run: `gh actions-lock`
Expected: `.github/workflows/actions.lock` gains an
`example-versions.yaml` entry and a `download-artifact` dependency. If
`gh actions-lock` isn't installed (it isn't on this machine as of planning),
stop and ask the user to install it or to run it themselves.

- [ ] **Step 3: Run the unit tests once more and commit**

Run: `php .github/example-versions/tests/run.php`
Expected: `18 passed, 0 failed`

```bash
git add .github/workflows/example-versions.yaml .github/workflows/actions.lock
git commit -m "Add example version check workflow"
```

- [ ] **Step 4: End-to-end fixture run (after the user resolves the Prerequisite)**

Run: `gh workflow run example-versions.yaml -R cookieguru/doc-en --ref versions -f fixtures_only=true`
then `gh run watch -R cookieguru/doc-en <id>`.

Expected in the `report` job summary:
* `dom-parentnode.append` under `## warning` / `### dom` with an
  "Undefined property … outerHTML" detail on an `8.4.*` tag.
* `dom-htmldocument.savehtmlfile` under `## output-mismatch`.
* `function.new-in-53` passes on a `5.3.*` tag and `dup_func` runs on a
  `5.4.*` tag. This proves the runner parses and runs on old PHP. If a
  `parse error` on `runner.php` appears for any tag, the 5.3-syntax
  constraint was broken.
* No issues created.

- [ ] **Step 5: Real run, scoped**

Run: `gh workflow run example-versions.yaml -R cookieguru/doc-en --ref versions -f only=dom`
Expected: the run completes. The summary lists DOM pages, and any real
failures are plausible on inspection. Report the counts to the user before
attempting a full or `open_issues` run.
