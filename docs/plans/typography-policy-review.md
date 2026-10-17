# Typography policy and core fixes — review draft

Prepared 2026-10-16; research updated 2026-10-17. This document makes no
typography behavior change.
The maintainer asked to review the plan and the submitted core fixes first.

## Recommendation for discussion

Use a strengthened incremental cross-token design as the leading candidate for
inline quote correctness, rather than treating any submitted patch as final.
The [Trac comparison and fresh source probes](../review/texturize-trac-comparison-2026-10-17.md)
found a locale-context gap in Dan's patch and separate gaps in the opening-tag
proposal. Reconcile their behavior and share context classification; do not merge
two state machines blindly. Keep HTML boundary/source preservation and nested
quotation policies separate.

The strongest long-term core direction is standards-aware HTML tokenization plus
locale-aware quote context. That is not a finished patch or a ready Keel backport.
For Keel, review the corpus and maintenance tradeoffs before choosing a bridge.
Do not copy a whole engine or enable a repair bundle by default at this stage.
The optional preference to leave punctuation as entered remains a separate decision.

## Submitted work and related investigations

| Work | Evidence and status | Decision relevance |
| --- | --- | --- |
| Comprehensive closing-inline-tag fix | [Trac #18549](https://core.trac.wordpress.org/ticket/18549#comment:37), [core PR #12249](https://github.com/WordPress/wordpress-develop/pull/12249), open when checked; head `0efc177c44d39f2f13a9f3a40ee1e18097545583` | Corrects historical inline cases; fresh probes found English-specific closing-entity context, so it needs locale hardening. |
| Minimal apostrophe patch | Local Dirtbag research retains a narrower fallback | Compare for understanding; do not substitute it silently for the submitted comprehensive patch. |
| Standalone builds | [Inline Quote Fix repository](https://github.com/dknauss/wp-texturize-inline-quote-fix) | Lightweight output repair and a faithful WordPress-7.0-based engine fork have different contracts. |
| Opening-inline-tag context | [Trac #43810](https://core.trac.wordpress.org/ticket/43810), [irozum’s PR #12645](https://github.com/WordPress/wordpress-develop/pull/12645), plus local prototype notes | Another contributor has submitted an opening-tag fix. Reconcile Unicode, apostrophe kind, tag parsing and allowlists; do not treat the local prototype as final. |
| Feet/inches without a space | Local `wptexturize_primes()` investigation | Separate behavior change needing an agreed specification and upstream status; preserve quoted numbers and apostrophes. |
| Nested quotation marks | [Trac #29882](https://core.trac.wordpress.org/ticket/29882) | Explicitly outside the submitted closing-tag patch. Do not claim every longstanding texturize issue is solved. |

The earlier author-only search missed complementary submissions. The expanded
[comparison](../review/texturize-trac-comparison-2026-10-17.md) covers the historical
#18549 engine, #29882, #12403/#63997 and related HTML-split tickets, #43785/#12694,
#61817/#7134, #66153 MathML proposals and #63192 granular controls. Already-landed
prime-ordering and locale changes are regression constraints, not new patches.
No current proposal should be presumed best or final because of its authorship.

The renewed source comparison used trunk
`8ce87974d83c72d62d338852d55a86b91874de15`; its formatting source did not
contain the submitted patch's cross-token helpers. Seven proposal diffs applied
individually to that baseline for isolated probes; this is not a combined patch.
The earlier local research records a passing 361-test/469-assertion core class,
not a new test run against today's trunk. Repeat it before porting.

## Two separate product decisions

**Correct typography.** A possible policy preserves normal texturization while
carrying selected fixes until core supplies them. Determine the exact supported cases and a retirement
mechanism before promising parity. Proposed copy: “Correct quotes across inline
formatting.” Final name, default and eligibility remain for review.

**Preserve punctuation as entered.** Optional, default off. Proposed copy:
“Leave typographic punctuation as typed.” Explain that this affects quotes,
apostrophes, ellipses, hyphens/dashes, primes, trademark and multiplication
substitutions, not merely curly quotes. It neither converts existing curly glyphs
back to ASCII nor undoes Gutenberg's own typing transforms. Stored content stays
unchanged. An off switch for core formatting is a preference, not a bug fix.

If both policies exist, raw punctuation takes precedence on a surface where it is
selected. Correctness code must stand down there. Do not texturize a second time,
remove intentional author-entered typography, or stack two replacement engines.

## Implementation choices to compare

| Approach | Benefit | Cost or gap |
| --- | --- | --- |
| Native core plus separate opt-in disable control | Smallest Keel change | Does not correct existing core defects; point users to the upstream work. |
| Lightweight post-processing bridge | Small implementation, easy removal | Existing build hardcodes English entities, has documented parity differences and covers only selected filters. Audit literal/code boundaries and make locale handling explicit before inclusion. |
| Faithful engine bridge | Best candidate for submitted-patch parity on its supported core baseline | Forked function must track core; replacing filter callbacks misses direct calls. Existing build targets WordPress 7.0, not Keel's entire supported core range. |
| Broad historical replacement engine | Reconstructs older attempts | Not proposed: replaced tokenizer, offset bookkeeping and drift are unnecessary for the submitted fix. |

Do not copy the old mark/unmark prototype: local research demonstrated apostrophe
loss when a filter removes its marker comment. That prototype differs from the
current standalone lightweight post-processor. Neither is equivalent to the
locale-aware in-engine patch. Keep core files and Keel's security-update installer
out of this feature; a plugin compatibility bridge must not patch installed core.

## Scope and architecture questions to settle

The simple global `run_wptexturize` filter is evaluated on the function's first
call, then cached for the request ([core hook documentation](https://developer.wordpress.org/reference/hooks/run_wptexturize/)).
It must be installed early enough and is not a per-render switch. Its state also
crosses `switch_to_blog()` within a request. It would affect direct calls and any
plugin calling core, including administrative rendering. Do not describe that as
merely a front-end content setting.

The alternative is replacing selected core filter callbacks with a policy wrapper
that consults the current site's effective setting on each invocation. This makes
scope and blog-switch behavior controllable, while direct `wptexturize()` calls
remain outside the promise. It also requires an audited callback inventory and
preservation of priorities, argument counts and third-party filter ordering.

Proposed initial scope to evaluate, rather than a committed scope:

- Post content, titles, explicit/generated excerpts, embed excerpts and feeds.
- Comments, term descriptions, captions, document/menu/widget titles and text widgets:
  enumerate individually before calling the behavior site-wide.
- REST rendered fields and server-rendered previews: verify their actual filter
  paths; raw/edit-context values and editor typing must stay untouched.
- Network policy: site settings must resolve correctly after blog switches and
  locale switches; disabling one policy must not overwrite another site's value.

## Acceptance evidence before implementation approval

1. Agree on outputs independently of any candidate patch. Require the newly found
   German-style quote-tail, Unicode opening-tag, tag-casing/whitespace, script-with-
   following-prose and granular dash-preservation regressions from the comparison.
   Include numeric grouping (`10'000`) when evaluating a feet/inches rule.
   Freeze the desired outputs from the submitted comprehensive fixtures and ordinary
   parity corpus. Include quotes around links, quote-wrapped names plus possessives,
   punctuation/entities, whitespace, Unicode letters, nested inline tags and closing
   block boundaries. State which opening-tag, prime and nested-quote cases are excluded.
2. Test non-English quote conventions using actual locale mappings, not English
   `_x()` stubs alone. Cover code/pre/kbd/script/style, shortcodes, attributes,
   comments and literal examples; preserve existing core shortcode fixtures.
   Check filter ordering with Keel shortcode cleanup and the installed standalone
   fixes enabled/disabled, including shortcode-generated output.
3. Compare exact-patch and lightweight outputs, including intentionally authored
   opening quotes next to inline tags. Publish every mismatch and its chosen policy.
4. Run the full core formatting class on the proposed patch and each supported
   baseline, plus real WordPress single-site/multisite and rendered surface checks.
   Preserve PHP 7.4; do not assume a fork from one core version covers all versions.
5. Reuse the performance budget: paired samples, five warm-ups and at least 30
   samples, prose/HTML-heavy/quote-dense fixtures, median and p95, peak allocation,
   query/write/outbound counts. No persistent diagnostic or frontend assets needed.
6. Define detection of an already-fixed core and of installed standalone fixes.
   Avoid duplicate application, fail unchanged on unsupported versions, record the
   source revision/license, and test retirement when core gains the correction.
7. Review the setting names, scope, default and help copy before coding. Add precise
   contracts and behavioral fixtures to the implementation, not a copied engine
   whose only evidence is an old test count.

## Decisions for the maintainer

- Prioritize the targeted correctness bridge, the raw-punctuation option, or both?
- After correcting the identified gaps, is in-engine coverage required, or is a
  documented narrower, locale-correct bridge acceptable? Existing bugs are not
  parity targets.
- Should a validated correction be default on, or opt-in until field experience?
- Which rendered surfaces should the preference cover? A global request switch
  and selected display filters are different policies.

The next step is review and refinement of this plan using the expanded comparison
and proposed behavior corpus. Runtime implementation waits for the maintainer's
direction.
