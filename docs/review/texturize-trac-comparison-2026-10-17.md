# Texturize proposals compared — 2026-10-17

## Recommendation

Use an improved version of the incremental cross-token design in Dan's #18549
submission for inline quote classification. Reconcile it with the independently
submitted opening-tag fix rather than installing both state machines unchanged.
Neither current patch is a final, locale-complete solution.

Treat HTML tokenization/source preservation, nested quotation and punctuation
preferences as separate work. In core, the strongest long-term architecture is a
standards-aware HTML token stream with explicit quote context and opaque code/math
regions. That is an architectural recommendation, not a ready, benchmarked patch.
For Keel, defer an engine fork and a default-on repair bundle until the agreed
behavior corpus and compatibility/performance checks pass. Keep a raw-punctuation
preference independent and opt-in. No typography runtime change is made here.

## Sources and comparison

GitHub PR states and heads were read directly on 2026-10-17. Except #5697, the
listed PRs were open, not merged. Trac fetching was intermittent (403s); ticket
search results, accessible discussion and linked PR diffs were cross-checked.
An open PR or a patch's claimed test result is not evidence of acceptance or of
passing today's full suite.

| Proposal | What it does | Assessment against Dan's patch |
| --- | --- | --- |
| [#18549 / Dan's #12249](https://github.com/WordPress/wordpress-develop/pull/12249) | Keeps core's tokenizer and carries context across closing inline tags; handles apostrophes and double quotes plus historical quote-around-link cases. | Best starting shape for this defect family: limited scope and no replacement engine. However, its context helper recognizes English numeric closing-quote entities, so locale-aware replacement values alone do not make every context locale-correct. |
| [#43810 / #12645](https://github.com/WordPress/wordpress-develop/pull/12645) | Carries preceding-word state across opening inline tags, primarily for contractions. | Complementary, not a replacement. ASCII-only trailing-word regex, use of closing-single-quote instead of apostrophe, and case/whitespace-sensitive tag extraction need reconciliation. Its inline allowlist also differs. |
| [#18549 historical replacement engine](https://core.trac.wordpress.org/ticket/18549) | The `18549_wptexturize*.diff` series strips markup/shortcodes, formats remaining text, then reinserts at tracked offsets; global bookkeeping and altered prime logic. | Broader and more invasive than needed for inline context. Preserve its useful fixtures; do not port its architecture into Keel. Earlier local research records historical performance objections; no fresh engine benchmark was run here. |
| [#29882 nested-quote proposals](https://core.trac.wordpress.org/ticket/29882) | Separate nested-quotation tests and an extra-pass attachment. Ticket discussion records an ambiguity/incompatibility in the proposed pass. | Outside Dan's closing-tag scope. Keep as a distinct specification with locale-specific nesting and apostrophe tests. The attachment was not executed in this comparison. |
| [#63997, #63426, #57381, #43785 / #12403](https://github.com/WordPress/wordpress-develop/pull/12403) | Quote-aware shared HTML splitting, reuse of the shared regex, and preservation of ampersands in quoted attributes. | Addresses tokenizer correctness rather than quote classification. Useful complementary work, but touches shared HTML splitting and changes attribute serialization; validate callers beyond `wptexturize`. Its current diff is broader than its older description. |
| [#57381 / closed #5697](https://github.com/WordPress/wordpress-develop/pull/5697) | Earlier shared-regex repair for valid HTML attributes/Tailwind selectors. | Closed without merge. Discussion points toward HTML API work, not proof that the problem is solved. [HTML API roadmap](https://github.com/WordPress/gutenberg/issues/60397). |
| [#43785 / #12694](https://github.com/WordPress/wordpress-develop/pull/12694) | Avoids ampersand rewriting while the no-texturize stack is active. | Narrow source-preservation mitigation, independent of Dan's fix. Repairs tested script/code ampersands but not the swallowed closing-script boundary in the trailing-prose probe below. |
| [#61817 / #7134](https://github.com/WordPress/wordpress-develop/pull/7134) | Adds `mark` to the no-texturize list to avoid a hyphen conversion. | Too broad for the proposed correctness policy: ordinary highlighted prose loses quote, ellipsis and dash formatting together. Prefer context-aware dash rules; do not treat all inline formatting as code. |
| [#66153 / #13647 and #13757](https://core.trac.wordpress.org/ticket/66153) | Adds `math` to no-texturize regions; preserves MathML/LaTeX notation and annotations. | Strong narrow boundary fix, unlike blanket exclusion of `mark`. Complementary and plugin-hook-compatible through the existing exclusion filter, but still requires compatibility/retirement checks and explicit scope approval. Two alternatives exist for the same ticket; do not count them as two fixes. |
| [#63192 / #8610](https://core.trac.wordpress.org/ticket/63192) | Proposes granular replacement disabling. | Product preference, not a quote-context repair. Reviewers question hardcoded reverse mappings; the current dash-disable probe changes ` -- ` to ` --- ` and ` - ` to ` -- `. Do not import it as exact punctuation preservation. |
| [#22823](https://core.trac.wordpress.org/ticket/22823) and [#19602](https://core.trac.wordpress.org/ticket/19602) | Already-fixed apostrophe/prime ordering and locale support. | Regression constraints, not pending patches to install. A generic digit-apostrophe-digit rule can conflict with quoted numbers or numeric separators such as Swiss `10'000`. The local feet/inches prototype needs its own specification. |

This is a focused inventory of directly related proposals, not every formatting
ticket. Duplicate reports are not independent algorithms. The opening-tag PR is
by irozum; it was absent from the earlier author-only search and makes the earlier
“local prototype only” description incomplete.

## Fresh source probes

All seven runtime diffs below applied individually, without conflict, to the same
trunk formatting source, revision `8ce87974d83c72d62d338852d55a86b91874de15`.
No combined implementation was created. Each arm and native trunk was evaluated
in a separate PHP process: 21 inputs under two translation mappings plus one
dash-disable check, 43 evaluations per arm, 344 total. PHP 8.4.26.

`_x()` and `apply_filters()` were small stubs; shortcode registration was empty.
The alternate mapping used German-style opening/closing quotes and a distinct
apostrophe. It was deliberately **not** an installed-language-pack test. This
isolates classification assumptions and does not certify full internationalization.
No real site, database, browser, full core suite or performance benchmark was run.
Raw source copies, diffs, `probe.php` and JSONL outputs are retained locally under
`~/.codex/verification/keel-texturize-research-2026-10-17/`.

| Arm | Pinned head |
| --- | --- |
| #12249 | `0efc177c44d39f2f13a9f3a40ee1e18097545583` |
| #12645 | `8ec201be7a6ece589fe137ec677fd216bbb4f37b` |
| #12403 | `276f842cb03b34d27d5d2fd42c531d1c0ff35bf2` |
| #12694 | `fcc0f498d2335a1588b1b1c0b4c50b0452e7a70b` |
| #7134 | `23285b41c2bc795d55b45fda7c967e8c2f95a2bc` |
| #8610 | `69f79f8ad8c5e04107ab9eb15e04be9155fa8536` |
| #13757 | `55597bd4911efeb2032afd487784459552cff10d` |

### Findings that change the plan

1. **Dan's patch needs locale-context hardening.** English
   `<em>"John"</em>'s book.` becomes the correct apostrophe under #12249. With
   `_x()` returning German-style `&#8222;` / `&#8220;` double quotes, its output is
   `<em>&#8222;John&#8220;</em>&#8218;s book.`; the possessive still gets the opening
   single quote, instead of the configured apostrophe `&#8217;`. The helper only
   recognizes `&#8217;` / `&#8221;` as closing-quote tails. Locale values must
   participate in context recognition, not only final substitution. The earlier
   plan's unqualified “locale-aware core fix” description was too strong.
2. **The other opening-tag patch also needs work.** #12645 corrects
   `I<strong>'ve been</strong>` in English. It leaves the tested Unicode `é` ending,
   uppercase tag and tab-separated attribute cases unfixed. Under the alternate
   mapping, it emits `&#8216;` (closing single quote), not `&#8217;` (apostrophe).
   Use an apostrophe-specific decision and shared Unicode-aware context; do not
   simply add its current code beside #12249's state.
3. **A stack guard is not full HTML tokenization repair.** For
   `<script>if(a<b)window&&document</script>It's -- fine.`, #12694 keeps `&&` intact
   but leaves the following prose untexturized. #12403 preserves the script and
   texturizes the following prose. This favors a tokenizer-level boundary solution
   for that family, while leaving its broader shared-regex compatibility review open.
4. **Inline exclusion changes editorial typography.** #7134 preserves the hyphen
   by also preserving all punctuation in `<mark>He's -- here ...</mark>`. Excluding
   a semantic notation region (`math`) has a different justification and scope.
5. **A reverse mapping does not preserve source.** Under #8610's dash-disable
   option, `A -- B; A--B; A - B; A --- B` becomes
   `A --- B; A--B; A -- B; A --- B`. Disabling a transformation should skip its rule,
   rather than infer the original spelling from a replacement glyph.

## Proposed design direction, subject to review

- Preserve the existing formatting pipeline and locale mappings. For the quote
  family, carry a small explicit context record across permitted inline boundaries;
  classify word continuation, whitespace and already classified closing quotes
  separately. Opening boundaries need a stricter continuation predicate than closing
  boundaries. Reconcile allowlists and recognize valid HTML casing/whitespace.
- Keep the decision about quote kind separate from how a locale renders that kind.
  Cover entity and literal representations, quote-wrapped names, Unicode endings,
  and locales whose closing quote is an English opening glyph. Space/block/comment/
  shortcode/no-texturize boundaries must reset or preserve state deliberately.
- Keep source-preservation patches separate from quote correctness. Review shared
  splitter changes with all callers, malformed markup, comments, RAWTEXT and
  shortcode behavior; benchmark parser cost and pathological input, not just quotes.
- Favor HTML API tokenization for a future core refactor, then preserve shortcode
  and typography semantics explicitly. HTML parsing alone does not solve quote
  nesting or ambiguity. Do not bundle that refactor into a Keel stopgap. All-token
  traversal arrived in [WordPress 6.5](https://make.wordpress.org/core/2024/05/07/progress-report-html-api-2/),
  after Keel's 6.4 floor; check exact APIs and capability boundaries before adoption.
- Decide nested quotations and feet/inches as independent linguistic policies. Test
  locale nesting conventions, measurements, abbreviated years, quoted numbers,
  numeric grouping and genuine no-space opening quotes. Do not promise certainty
  where plain ASCII source is ambiguous.
- After maintainer review, the next evidence step is a differential test corpus and
  a focused core patch revision, followed by full core suites, real locale/site
  checks and the existing performance budget. Keel integration is a later decision:
  no blanket engine copy, new default or silent core-file patch in this research.
