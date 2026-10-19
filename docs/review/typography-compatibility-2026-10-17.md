# Typography compatibility validation — 2026-10-17

## Follow-up — 2026-10-19

The maintainer authorized the [standalone feature-plugin implementation](https://github.com/dknauss/wp-texturize-inline-quote-fix/pull/2).
Core revision `b290bb5f1b` adds automatic locale table refresh and lazy context /
classified-apostrophe fast paths. The repeated 10,260 comparisons still match,
including actual network locale restoration. Added ~40 KB function costs now fall
about 66% for HTML and 55% for quote-heavy HTML versus the previous patch; plugin
and core implementations measure essentially alike. The earlier gaps and timings
below describe the old narrow bridge and pre-optimization revision, not the new
feature build. Keel typography remains unchanged. Review/release are still pending.

## Decision for review

Keep Keel's typography unchanged. Advance the corrected inline-context work in
core, and prefer a dedicated, explicitly enabled compatibility plugin if a
stopgap is needed before core adoption. Do not ship the current lightweight
bridge as a locale-correct replacement for the core patch.

A separate plugin makes the rendering scope, supported core versions, locale
limitations and eventual retirement independently reviewable. It also serves
sites that do not use Keel. This is a maintenance and product-scope judgment;
putting identical code in a separate plugin does not make it faster or safer.
The current standalone bridge needs correction before a new release. No bridge,
engine fork, new default or punctuation preference is implemented by this pass.

## Tested implementations and method

- Native released core, unchanged in the disposable site's files.
- Narrow bridge: `dknauss/wp-texturize-inline-quote-fix` at
  `1c8af5d524c406afc33c936b05c029ea35ce8c53`, file
  `wp-texturize-inline-quote-fix.php`, version 0.1.0. This is the two-regex
  post-processor, not the discarded mark/unmark prototype or the faithful fork.
- Core patch: [PR #12249](https://github.com/WordPress/wordpress-develop/pull/12249)
  at `1f07246ffe33a9d288c17070c856fd56989cbb62`. Its exact formatting diff
  against upstream trunk applied cleanly to every tested release source. Extracted
  patched functions were renamed and loaded beside real core; existing native
  tokenizer, prime and shortcode helpers were retained. This compares the patch
  on each release without replacing installed core or installing a Keel feature.

A disposable Studio site uses PHP 8.4.26, SQLite and Twenty Twenty-Four. The
single-site pass is followed by conversion to a real three-site subdirectory
network. Versions are switched on the same disposable database; this tests
rendering compatibility, not fresh installation, schema migration or downgrade
support. Twenty Twenty-Five initially prevented a 6.4 bootstrap because it calls
an API introduced in 6.5; changing the lab to Twenty Twenty-Four resolved that
unrelated setup failure. No production sites were changed.

The matrix includes the exact 6.4 floor plus the latest security release of each
supported minor line offered by WordPress.org on the test date: **6.4, 6.4.13,
6.5.13, 6.6.10, 6.7.10, 6.8.11, 6.9.10, 7.0.7 and 7.1.3**. This is not every
historical patch release. The actual packages, source hashes and effective quote
mappings are retained with the evidence.

Installed catalogs are **en_US, de_DE, fr_FR, ru_RU, ar and ja**. Non-English
core language packs were uninstalled/reinstalled for each recorded version;
`switch_to_locale()` loads them through WordPress. Catalog mappings, rather than
synthetic translation filters, supply the quote values. Isolated comparisons
explicitly reset both functions after switching locale; a separate test omits
the reset to expose request-cache behavior.

Each version/mode has 47 direct formatting inputs and 48 filter-pipeline inputs
per locale. Pipelines cover twelve hooks with four inputs each. Reference callback
substitution retains the original priority and ordering in the final harness.
Across the 108 version/mode/locale environments this produces **10,260 comparison
rows**, with identical outputs across versions and modes. There are **221 distinct
bridge/patch mismatch rows** across the six-locale direct/filter corpus; all are
in the CSV. Eighteen repeats of those rows account for 3,978 matrix mismatches.
These are comparison rows, not 10,260 passing assertions.

A preliminary harness moved the replacement callback after other priority-10
callbacks. Full-output comparison exposed that setup error. The harness now
substitutes the callback in place, and all five affected single-site runs were
repeated; their preliminary results were overwritten and excluded.

The corpus deliberately includes failures and opaque examples; mismatch counts
are not a population failure rate or proof of linguistic correctness.

## Expected behavior and language boundaries

The correction carries word/quote context across a bounded allowlist of ordinary
inline tags. For example, `<strong>He</strong>'s` receives an apostrophe, and
`I<strong>'ve</strong>` continues a word. A quote closing a quotation around inline
markup receives the locale's closing quote. Apostrophes and closing single quotes
remain different semantic decisions even when English renders both as `’`.

Recognize Unicode letters/numbers and trailing combining marks, valid tag casing
and attribute whitespace, nested inline tags, configured literal/entity quote
tails and multi-character translated quote strings. Whitespace, block/comment/
shortcode boundaries and opaque regions constrain context. Preserve intentionally
authored typographic glyphs/entities and existing code/pre/kbd exclusions. Keep
normal core dash, ellipsis, prime and other substitutions in operation.

This does **not** identify the language of a sentence. The active WordPress locale
supplies punctuation; mixed-language content does not automatically get different
conventions per span or `lang` attribute. A Unicode letter predicate is not a
language grammar. Most contraction fixtures are deliberately English even under
other locales: they test quote mapping, not every language's apostrophe grammar.
Cyrillic, Arabic and Japanese word endings exercise Unicode mechanics. Arabic and Japanese catalogs tested here retain core's English
curly quote mappings: successful execution does not establish Arabic typesetting,
Japanese corner-bracket conversion, bidirectional layout or native-speaker review.

The patch does not implement nested quotation grammar, feet/inches rules, Swiss
numeric grouping, general French spacing, MathML protection, a new HTML parser,
editor keystroke transforms, font/layout changes or an option to disable all
punctuation conversions. Those are separate policies. Ambiguous straight-ASCII
quotes immediately adjacent to a word remain governed by the stated heuristic;
perfect recovery of author intent is not promised.

Rendering changes leave stored source untouched. In core the correction reaches
direct `wptexturize()` calls and every existing caller. A filter bridge only
reaches its named callbacks; no claim of site-wide equivalence follows from
correcting post content.

## Gaps in the narrow bridge

The complete distinct mismatch rows, including exact native/bridge/patched
outputs, are recorded in the [companion CSV](data/typography-mismatches-2026-10-17.csv). No mismatch is silently excluded.

| Locale | Direct bridge/patch mismatches out of 47 | Important distinction |
| --- | ---: | --- |
| en_US | 16 | Opening tags, closing-tag whitespace, empty/punctuation context and authored entities |
| de_DE | 14 | Opening single `‚`, closing single `‘` and apostrophe `’` differ |
| fr_FR | 25 | Named entities `&lsquo;` / `&rsquo;`; spaced guillemets for double quotes |
| ru_RU | 17 | Guillemets use different entities than the bridge's hardcoded English double quotes |
| ar | 16 | Catalog uses core curly mappings; this is not a full RTL typography audit |
| ja | 16 | Catalog uses core curly mappings; this is not Japanese punctuation localization |

The bridge has no opening-tag correction and no preceding-word context. It
matches only `</tag>`, missing permitted whitespace before `>`. Its English
numeric-entity replacements cannot express the configured locale contract.
It also scans the entire rendered string without honoring opaque regions:
`<code><em>x</em>&#8216;s</code>` becomes `…&#8217;s…`, while both native core and
the patch preserve it. Authored opening quote entities following an inline tag
can be changed even though neither core nor the patch changes them.

The callback is registered at priority 12 on only seven hooks: `comment_text`,
`term_description`, `the_content`, `the_excerpt`, `the_excerpt_embed`, `the_title`
and `widget_text`. The real callback inventory also contains unhandled title,
term/link, menu, caption and `widget_text_content` paths. Direct calls are outside
its reach. Feeds and REST must be traced through their actual callers: they can
inherit `the_content`/`the_title`, so absence of a direct feed hook does not imply
all feed output is uncorrected.

The shortcode probe demonstrates another difference: a handler at priority 11
can emit quote entities which the priority-12 bridge changes after core has
finished. The in-core correction does not texturize generated shortcode output
a second time. Changing those entities is not parity with the patch.

Actual post title/content/excerpt, RSS content and REST-rendered field probes at
6.4 and 7.1.3 returned HTTP 200 from the internal REST dispatcher and retained
identical stored content. These use real rendering APIs, not browser screenshots.

Keel `b6e5098bb3a5236735d193cac41a6b64139771d8` was also network activated at
both endpoints. An additional 1,140 comparison rows matched the no-Keel results.
Separate real-post probes toggled Keel's priority-10 shortcode cleanup while
running native/bridge/patched arms: 288 combinations confirmed removal of strong
missing-shortcode syntax, preservation of registered shortcode output and opaque
examples by cleanup, and the bridge's independent post-shortcode/entity changes.
Keel's cleanup does not cure the bridge's quote-classification gaps. Observation
transient writes from cleanup are outside the formatter microbenchmark.

## Locale switching and multisite

A network contains English, German and French sites. The probe switches blogs,
then locales, records output before resetting, resets both engines and records
again, and restores the original blog and locale. `switch_to_blog()` alone leaves
the loaded locale unchanged. `switch_to_locale()` loads translations but does not
invalidate either function's static replacement tables.

For example, after German formatting has initialized those tables, switching to
English still yields German `‚Hello‘` rather than English `‘Hello’` until reset.
This is shared with native core; the patch's new closing-glyph context does not
resolve the broader existing cache contract. Resetting after the explicit locale
switch gives the appropriate tested output. The bridge cannot recover the lost
semantic distinction from already formatted text.

A future plugin must define and test its locale-entry/restore handling and any
site policy resolution rather than silently inheriting the last site's rules.
Do not turn a test-only `$reset = true` into an undocumented production integration.
Network activation, blog switching and locale switching are separate dimensions.

## Performance interpretation

Benchmarks run in the real WordPress bootstrap. Each fixture/locale has five
warm-up rounds and thirty measured rounds with reproducibly shuffled native,
native-plus-bridge and patched arms. Ten calls form a short-fixture sample;
large-fixture sampling is documented with its results. Timings exclude bootstrap,
SQL setup, translation loading and locale resets. Raw samples retain medians,
nearest-rank p95 and coarse allocated peak memory.

The earlier patch-only benchmark compared against the *previous closing-tag
patch*. This pass compares against **unpatched released core**, so a larger delta
is expected and must not be conflated with the earlier incremental result.

Representative WordPress 7.1.3 multisite / en_US timings, **median / p95 ms
per call**, against unpatched released core:

| Fixture | Native | Native + bridge | Core patch |
| --- | ---: | ---: | ---: |
| prose (3,600 B) | 0.012 / 0.012 | 0.016 / 0.017 | 0.012 / 0.014 |
| html (4,040 B) | 0.267 / 0.271 | 0.275 / 0.279 | 0.433 / 0.437 |
| quotes (3,950 B) | 0.632 / 0.668 | 0.660 / 0.676 | 0.900 / 0.937 |
| Long prose (36,000 B) | 0.096 / 0.097 | 0.135 / 0.135 | 0.096 / 0.098 |
| Long html (40,400 B) | 2.682 / 3.023 | 2.766 / 3.052 | 4.323 / 4.779 |
| Long quotes (39,500 B) | 6.807 / 11.388 | 6.921 / 16.256 | 9.322 / 20.467 |

Short fixtures use ten calls per sample; long fixtures repeat the source ten
times and use three calls per sample, still five warm-up and thirty measured
rounds. Both endpoints (6.4 and 7.1.3), both site modes for short fixtures, and
all six locales were measured. Long fixtures were measured on multisite at both
endpoints. The condensed JSON retains every locale's median/p95 and allocation.

The patch adds about 0.16 ms for the short HTML fixture, 0.27 ms for the short
quote-heavy fixture, 1.64 ms for the long HTML fixture and 2.52 ms for the long
quote-heavy fixture in this representative run. The latter is roughly 37% extra
formatting time. The analogous long quote result at 6.4 adds 2.64 ms, confirming
that the median increase is not confined to one run. The 7.1.3 long-quote p95
values are noisy (11.4 / 16.3 / 20.5 ms); do not treat them as a stable production
tail-latency prediction. A controlled tail-cost rerun belongs with profiling before
shipping. The simple bridge's lower CPU cost is
not behavioral parity.

All measured formatting batches have **zero additional queries, writes or
attempted HTTP calls**. Coarse peak allocation is equal between arms within each
fixture; the 2 MiB allocator steps and preloaded reference code cannot establish
zero fine-grained memory or plugin-bootstrap overhead. An initial network pipeline
probe observed two writes outside these isolated batches; a warm repeat did not.
Their exact source was not traced before the repeat. Do not attribute them to a formatter
or claim that the entire test/provisioning workflow is write-free.

This is a profiling signal before adoption: the long quote workload exceeds the
2 ms absolute investigation threshold used in Keel's refactor budget, when used
as a function-level comparison. That budget formally measures paired **routes**;
this is not proof of a page-route budget breach or pass. Profile context bookkeeping
and verify the improvement against the frozen corpus before shipping a bridge.
No optimization was implemented during this validation-only pass.

These are function costs, not page TTFB, browser paint or production throughput.
Repeated calls on many fields accumulate their costs; post length matters more
than whether the same implementation is packaged inside Keel or separately.

## Recommendation and remaining gates

Prefer upstream adoption. If a temporary plugin is needed, harden the dedicated
plugin first and keep Keel independent. Require locale-correct classification,
opaque/literal preservation, a precise rendered-surface contract, an explicit
locale-switch policy and detection/retirement when core supplies the correction.
Avoid shipping both engines or a whole copied function without version gates.

A separate “leave punctuation as typed” preference remains opt-in and independent;
it disables more than curly quotes and should not be presented as this bug fix.
Do not combine that product decision with a compatibility repair release.

Browser/editor typing, multilingual plugins, native-speaker linguistic review,
MySQL/Redis, PHP versions other than 8.4 in this pass, page TTFB and every historical
core patch release are outside this matrix. The core PR already has separate
PHP 7.4/8.x, multisite and browser CI; that evidence does not extend this plugin's
contract. No faithful-fork plugin equivalence is asserted here.

Full local inputs, patched reference functions, catalog/source hashes and raw
outputs are under `~/.codex/verification/keel-typography-compatibility-2026-10-17/`.
The [condensed evidence](data/typography-validation-2026-10-17.json) and all distinct
mismatches accompany this record. The disposable site was deleted after retaining
the harness and results; no live typography settings or runtime files changed.

The retained local evidence archive is `typography-evidence.zip`, SHA-256
`b85504eeda0f5c70ed3db2d9093735a88b590b6d1cde087f1a4d69202350c476`. It contains harnesses, source diffs and raw outputs, without
site configuration, database files or credentials.
