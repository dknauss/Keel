# Shortcode residue and Site Health — 2026-10-16

Merged together in #279 for 0.7.0, pending release. `hide_shortcode_residue`
is an independent, default-on Content toggle. Development has 42 settings; the
released 0.6.9 package is unchanged. PHP 7.4 remains the floor.

## Recognition and boundaries

An unknown `[word]` is not enough evidence to remove prose. Recognizable candidates
have a matching closing tag, a self-closing slash, or a named attribute. Site owners
can explicitly identify missing bare tags through `keel_shortcode_residue_tags`.
Strong syntax is still a heuristic: intentional raw examples should use double-
bracket escaping or code markup, or the policy should be disabled. The parser uses [core's six-group shortcode expression](https://developer.wordpress.org/reference/functions/get_shortcode_regex/)
only after candidate selection. It never invokes or registers shortcode callbacks.
[Hide Broken Shortcodes](https://wordpress.org/plugins/hide-broken-shortcodes/) is
prior art for presentation cleanup; this implementation adds conservative recognition
and the paired, passive diagnostic required by Keel's roadmap.

The priority-10 `the_content` filter runs after core block/text formatting, before
priority-11 shortcode execution. Already registered handlers and their input stay
unchanged, including enclosed content. Escaped examples, HTML attributes/comments,
code/pre/script/style/textarea regions and backtick examples stay literal. Missing
paired wrappers lose their delimiters, preserving enclosed text and markup.
At most 100 opening candidates and eight nesting levels are processed; oversized
or failed parses preserve remaining markup. Tags use a bounded ASCII identifier.

Readers receive cleaned content. `current_user_can( 'edit_post', $id )` retains
residue for anyone entitled to edit that specific post, including custom grants.
No stored content changes. Admin, REST, standalone excerpts/widgets, locked password
content and content without a current post are outside this filter. It does not
repair missing output or provide access control. Plugins registering shortcodes
after this filter and content generated later are outside the observation point.
Caches must preserve the usual authenticated/editor separation.

## Evidence and storage

Site Health reads a per-site transient, never scans posts or renders callbacks.
The bounded record contains only post IDs, tag names and observation timestamps:
20 posts, ten distinct tags per post, one hour per observation. At capacity,
additional posts wait for expiry. Identical observations neither write nor refresh
the timestamp. A new observation cannot prolong another post's stale evidence.
Healthy renders touch no diagnostic storage. Deletion removes this transient on
each site through the existing multisite uninstall path.

The report offers edit links only for posts the current user can edit and omits
missing/trashed posts. It describes recent observations, not a current exhaustive
inventory or attribution to a particular plugin. Fixed/restored handlers may remain
listed until the evidence expires. Disabled cleanup does not present old evidence
as an active cleanup diagnosis.

## Verification

Disposable Studio site; WordPress 7.1.3, PHP 8.4.26, SQLite, Twenty Twenty-Five;
then converted to a two-site subdirectory network with Keel network-active.

| Check | Result |
| --- | --- |
| Parser contracts | Prose/citations, empty and nested pairs, self-closing/attribute syntax, escapes, registered/namespaced/numeric wrappers, code/HTML boundaries, token collision and oversized fail-open pass. |
| Persistence contracts | Dedupe, identical-answer write suppression, 20-post/ten-tag caps, independent expiry and malformed-record guards pass. |
| Real roles | Anonymous/Subscriber see cleanup; Administrator/Editor/own Author retain residue. A Subscriber granted per-post editing through `map_meta_cap` retains it too. |
| Actual HTTP | Singular page and RSS respond 200 with residue hidden; anonymous REST returns 200 and retains it. Registered shortcode output remains present. |
| Storage | Original post content is byte-identical; captured record contains no attributes or enclosed text. Repeated identical rendering writes once. |
| Site Health | Recommended result identifies tag names and the post edit link. Chromium confirms the rendered report and link; inaccessible evidence is omitted in contract fixtures. |
| Browser content | Chromium DOM confirms reader cleanup, preserved prose/code/text, normal registered output and editor-visible residue. No console errors on tested screens. |
| Multisite | Newly created child gets all 42 settings, starts without parent evidence and stores independently. Blog switches preserve parent evidence. Network disabling applies on both sites; an actual disabled-policy HTTP request keeps residue, and the diagnostic is unregistered. Removing policy restores defaults. |

The committed `tests/integration/shortcode-residue-probe.php` reproduces the real
content/role/HTTP contracts on a disposable localhost install. Sanitized performance
and network probes are retained in the maintainer's local verification directory.
PHP debug output contained automatic-update progress only before the probes; a
fixture typo in the first network probe was corrected before the successful rerun.
The final content/HTTP/network rerun after clearing that fixture log produced no
PHP debug entries.
No production site, real mail, MySQL or full browser/accessibility matrix was used.

## Performance

The first large-citation probe exposed needless enclosing-tag searches. Added a
strong-syntax gate, selected candidates and bounded the full parser before accepting
the implementation. Ordinary citations avoid the enclosing-tag regex entirely.

Content-filter microbenchmark: same real WordPress process, cleanup removed/added,
five warm-up samples and 30 measured samples per arm, three renders per sample.
This measures filter work, not HTTP TTFB or the whole release performance matrix.

| Content | Median before / after | Added median |
| --- | --- | --- |
| 3.4 KB ordinary prose | 0.146 / 0.150 ms | 0.004 ms |
| 3.3 KB prose with citations | 0.217 / 0.229 ms | 0.012 ms |
| 100 KB prose with citations | 4.373 / 4.557 ms | 0.184 ms |

The corresponding p95 increases were 0.002 ms, 0.048 ms and none respectively,
within the existing investigation thresholds. Warm cleanup arms added no queries;
contract fixtures confirm no diagnostic reads/writes for healthy content. No new
frontend JS/CSS, remote requests, persistent caches beyond the bounded diagnostic,
or dependencies were added. This is not a cold-cache or peak-memory certification.

Final local checks: full standalone suite, WPCS, PHPCompatibility for 7.4, syntax,
the existing nineteen-module PHPStan pilot and 43 negative contract fixtures pass.
An additional level-6 analysis of the new shortcode module passes without changing
the pilot scope. Package build, readme, settings/schema counts, i18n and paired
backlog/roadmap guards pass. Chromium confirms the new checked setting and its
Site Health explanation on the real settings screen.

The disposable lab and its authentication/mail fixtures were stopped and deleted
after verification; no credentials were retained in the public record.

## PR review hardening

Review found that the candidate-count check followed `preg_match_all()`, allowing
large temporary allocations before refusing an oversized workload. A 100,000-tag
fixture exhausted a 16 MiB process limit before the fix. Count opening brackets in
the masked text before collecting matches (at most 200 for 100 escaped candidates);
the same fixture now preserves its input and records nothing within that limit.
This bounds candidate-array allocation, not arbitrary HTML input size. Excessively
bracketed malformed text also fails open. No new strings, settings or hooks.
