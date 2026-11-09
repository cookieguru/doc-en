# Example version check — design

Fork-only GitHub Actions workflow for `cookieguru/doc-en`. Not intended for
upstream submission.

## Goal

Find manual examples that do not work on the minimum PHP version of the
symbol they document — e.g. a `Dom\ParentNode::append` (8.4.0) example using
`Dom\Element::$outerHTML` (8.5.0), or an example whose documented output is
wrong. Findings are triaged from a report and, optionally, tracked as issues
in the fork; fixes are submitted upstream by hand.

## Scope

In scope:

* Every `<refentry>` (functions, methods, classes) whose `versions.xml`
  minimum is PHP 5.3.0 or newer.
* Every `<example>` on those pages containing a `role="php"` program listing.

Out of scope (v1):

* Symbols with a minimum below 5.3.0, PECL-only symbols, and pages with no
  `versions.xml` entry: skipped entirely.
* Behavior that changed after introduction (e.g. a parameter added later, per
  the `changelog` section). Examples are only run on the symbol's minimum.
* Program listings outside `<example>`.
* Automated fixes or upstream PRs.

## Section 1: extraction and version matching (`extract.php`)

**Input:** `doc-base/.manual.xml`, built with the same `configure.php`
invocation as `integrate.yaml`, so all entities are resolved. Plus every
`reference/*/versions.xml` in doc-en.

**Page matching:** each `<refentry>`'s `<refname>`s, lowercased, are matched
against `versions.xml` `name` attributes. A page with several refnames uses
the highest minimum among its matches.

**Minimum from the `from` attribute:**

* Split on `,`; only `PHP …` segments count; `PECL …` segments are ignored.
* No `PHP` segment → skip.
* The minimum is the lower bound of the first PHP segment:
  * `PHP 5 >= 5.3.2` → 5.3.2
  * `PHP 7` → 7.0.0
  * `PHP 7.3` → 7.3.0
* Upper bounds (`PHP 7 < 7.4.0`) are ignored for the minimum.
* Minimum < 5.3.0 → skip.

**Image tag selection** (`php:<tag>-cli`, tags resolved once from the Docker
Hub tag list):

* `X.Y.0` → newest available `X.Y.*` patch.
* `X.Y.Z` with Z > 0 → exactly `X.Y.Z`; if no image exists, the next
  available patch in `X.Y`, recorded as a substitution.
* `8.6.0` → newest `8.6.0*` tag, including RCs, until `8.6.0` is published.

**Per-example record:**

* page id and refname
* example id, or its index on the page
* code
* `annotations` attribute
* expected `<screen>` text, if any
* extension (from the `reference/<dir>/` that held the matching
  `versions.xml`)
* minimum version
* image tag, plus whether it was substituted

**Output:** one JSON batch file per image tag, and the list of tags as the
matrix input. The `only` workflow input filters records by extension
directory or page id.

## Section 2: runner (`runner.php`)

One matrix job per image tag, `fail-fast: false`.

**Extensions:**

* `extensions.php` maps `reference/<dir>` to an extension name (e.g.
  `image` → `gd`) or marks it built-in (`strings`, `array`, `var`, `info`,
  …).
* Required extensions that are not loaded are installed with
  `mlocati/docker-php-extension-installer` while the network is still
  available.
* The runner checks `extension_loaded()`; examples whose extension is
  unavailable get `skipped-extension` and are not executed.
* "Undefined function/class" errors are never reclassified as missing
  extensions.

**Execution:**

* The container runs with `--network none` and stdin from `/dev/null`.
* The runner is valid PHP 5.3 syntax: no short arrays, no `::class`, etc.
* Each example runs in a fresh temp working directory:

  ```
  timeout 10 php -d error_reporting=-1 -d display_errors=stderr \
      -d html_errors=0 -d log_errors=0 example.php
  ```

* `annotations="non-interactive"` examples only get `php -l`.
* The runner records exit code, stdout and stderr; stdout and stderr are
  base64-encoded, because 5.3's `json_encode` fails on invalid UTF-8.

**Statuses**, most severe first:

| Status | Rule |
|---|---|
| `image-unavailable` | The tag could not be pulled; applies to the whole batch |
| `error` | Nonzero exit, or `Fatal error` / `Parse error` in stderr |
| `timeout` | Exit code 124 |
| `warning` | Exit 0 but stderr is non-empty (warnings, notices, deprecations, strict) |
| `output-mismatch` | Exit 0, empty stderr, a `<screen>` exists, not `non-interactive`, and normalized stdout ≠ normalized screen |
| `pass` | Everything else |
| `skipped-extension` | The required extension could not be loaded |

Normalization for comparison:

* CRLF → LF
* trailing whitespace stripped from each line
* leading and trailing blank lines trimmed

**Output:** artifact `results-<tag>.json`.

## Section 3: report, issues, workflow

**Files:**

* `.github/workflows/example-versions.yaml`
* `.github/example-versions/extract.php`
* `.github/example-versions/runner.php`
* `.github/example-versions/report.php`
* `.github/example-versions/extensions.php`
* `.github/example-versions/fixtures/`
* `.github/workflows/actions.lock`: regenerated with `gh actions-lock`

**Workflow:**

* **Trigger:** `workflow_dispatch` only.
* **Inputs:**
  * `open_issues`: boolean, default false.
  * `only`: optional string filter on extension directory or page id.
* **Guard:** every job has `if: github.repository == 'cookieguru/doc-en'`.
* **Actions:** pinned like the existing workflows (`actions/checkout@v7.0.1`,
  `shivammathur/setup-php@2.40.0`, `actions/upload-artifact@v7.0.2`), plus
  `actions/download-artifact`.
* **Permissions:** `contents: read` on every job; `issues: write` only on
  `report`.
* **Jobs:**
  1. `extract`: checks out doc-en and doc-base, builds `.manual.xml`, writes
     the batches, and outputs the tag matrix.
  2. `run`: matrix over tags; runs Section 2.
  3. `report`: downloads all results and runs `report.php`.

**Report:**

* `$GITHUB_STEP_SUMMARY` shows:
  * counts by status and tag
  * failing examples grouped by status, then by extension, with page,
    example, tag, any substitution, and the first stderr line or the first
    lines of the output diff
* The summary is truncated below GitHub's 1 MB limit.
* The full `report.md` and the merged `results.json` are always uploaded as
  an artifact.

**Issues** (only when `open_issues` is true):

* One issue per failing page, labeled `example-versions`, with an
  `<!-- example-versions:<page-id> -->` marker in the body for
  deduplication.
* Page fails, issue exists → the body is updated in place.
* Page fails, no issue → an issue is created.
* Page passes, issue open → a comment is added and the issue is closed.
* Pages whose only failures are `output-mismatch` also get the `output-only`
  label.

## Testing

* Fixtures:
  * a minimal `.manual.xml`
  * `versions.xml` files covering every `from` shape found in the repo:
    * `PHP N >= N[, PHP N…]`
    * bare `PHP N[, PHP N…]`
    * `PHP N.N`
    * upper bounds (`< N`)
    * mixed PHP and PECL
    * PECL-only
    * below 5.3.0
* Runner fixtures, run locally with Docker on `php:5.3-cli` and
  `php:8.4-cli`:
  * the pre-review `Dom\ParentNode::append` example (`$list->outerHTML`)
    must report `warning` on 8.4
  * the pre-review `saveHtmlFile` example (expected output "60 bytes") must
    report `output-mismatch`
  * the runner must parse and run on 5.3
