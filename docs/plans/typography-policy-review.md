# Typography policy and core fixes — review draft

Prepared 2026-10-16. No typography behavior is being implemented by this document.
The maintainer asked to review the plan and the submitted core fixes first.

## Recommendation for discussion

Evaluate the comprehensive, locale-aware core fix as the correctness baseline.
Keep the preference to disable automatic punctuation separate. Most sites wanting
correct quotes should not have to surrender the rest of WordPress typography.

Before choosing a Keel implementation, run a differential compatibility spike:
compare native core, the submitted patch and the two standalone builds on identical
fixtures. Bring back the parity, coverage, performance and maintenance results.
Do not import an engine fork or enable a workaround by default before that review.
A small correction can be preferable, but only if its narrower behavior is explicit.

## Submitted work and related investigations

| Work | Evidence and status | Decision relevance |
| --- | --- | --- |
| Comprehensive closing-inline-tag fix | [Trac #18549](https://core.trac.wordpress.org/ticket/18549#comment:37), [core PR #12249](https://github.com/WordPress/wordpress-develop/pull/12249), open when checked; head `0efc177c44d39f2f13a9f3a40ee1e18097545583` | Corrects the historical quote-around-inline-markup family, not just one apostrophe example. |
| Minimal apostrophe patch | Local Dirtbag research retains a narrower fallback | Compare for understanding; do not substitute it silently for the submitted comprehensive patch. |
| Standalone builds | [Inline Quote Fix repository](https://github.com/dknauss/wp-texturize-inline-quote-fix) | Lightweight output repair and a faithful WordPress-7.0-based engine fork have different contracts. |
| Opening-inline-tag context | [Trac #43810](https://core.trac.wordpress.org/ticket/43810); local prototype notes | Candidate extension requiring separate full-suite validation; do not label the local prototype submitted or accepted. |
| Feet/inches without a space | Local `wptexturize_primes()` investigation | Separate behavior change needing an agreed specification and upstream status; preserve quoted numbers and apostrophes. |
| Nested quotation marks | [Trac #29882](https://core.trac.wordpress.org/ticket/29882) | Explicitly outside the submitted closing-tag patch. Do not claim every longstanding texturize issue is solved. |

This inventory is provisional pending any additional submitted ticket/patch links.
GitHub author search found one texturize PR; it cannot establish that there are no
other Trac attachments or submissions under another account.

The checked trunk revision was `b036c94744c63fb58772f2e27627070bd80c75c4`;
its formatting source did not contain the submitted patch's cross-token helpers.
The earlier local research records a passing 361-test/469-assertion core class,
not a new test run against today's trunk. Repeat it before porting.

## Two separate product decisions

**Correct typography.** Preserve normal texturization while carrying selected fixes
until core supplies them. Determine the exact supported cases and a retirement
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

1. Freeze the desired outputs from the submitted comprehensive fixtures and ordinary
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
- Is exact comprehensive-patch parity required, or is a documented narrower,
  locale-correct bridge acceptable?
- Should a validated correction be default on, or opt-in until field experience?
- Which rendered surfaces should the preference cover? A global request switch
  and selected display filters are different policies.

The next authorized step is review and refinement of this plan, including additional
submitted patches. Runtime implementation waits for the maintainer's direction.
