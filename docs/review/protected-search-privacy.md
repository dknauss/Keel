# Protected-post search privacy

Implemented for the next release, after 0.6.9. Not published by this change.

## Scope and history

`protect_search` defaults on and is independent of `disable_post_passwords`.
The development schema has 41 settings; the released 0.6.9 schema remains at 40.
Missing keys on existing installs resolve through the schema default without
rewriting saved preferences. Site and network controls use the existing renderer.

This is a stricter optional search policy, not a newly introduced Keel regression
or a claim that WordPress promises to hide the existence of protected posts.
The candidate entered the roadmap on August 11 (`ede79b7`); the August 25 decision
(`c3d3199`) corrected an overly broad anonymous-exposure claim after measurement.
It was subsequently queued first for 0.7.0 and remained unimplemented in 0.6.9.
[Scott Reilly's historical plugin](https://coffee2code.com/wp-plugins/omit-passworded-posts-from-search/)
explains the search-term inference risk and notes its retirement after core 2.8
incorporated the anonymous restriction. It is historical prior art, not a current
maintained alternative for the logged-in exception.

[Core r11106](https://core.trac.wordpress.org/changeset/11106), April 2009,
explicitly fixed #9559 by excluding protected results only for logged-out searches.
[#38276](https://core.trac.wordpress.org/ticket/38276) distinguishes protected
content from post visibility. No active ticket specifically fixing the Subscriber
search exception was located in the October 15 review; this is a search finding,
not a claim that no such ticket exists.

An ordinary Subscriber can receive a protected result's title and stored excerpt
data. Standard `get_the_excerpt()` masks a locked excerpt; actual excerpt exposure
depends on the consumer. The policy also prevents body-only search terms revealing
which protected posts match. This does not reveal passwords, bypass the body password
form or disclose user-account information. Direct links and other archives remain
outside this policy.

## Implementation

A late `posts_clauses` callback retains WordPress's search parsing, joins,
constraints, grouping, ordering and pagination. For logged-in non-admin searches,
it reads matching protected rows and primes their post cache together. Each is
allowed only by `current_user_can( 'edit_post', $id )` or a false result from
`post_password_required()`. That honors custom object/CPT capabilities, password
cookies and password-access filters, without treating published-post `read_post`
as a sufficient grant: core gives that capability to ordinary Subscribers too.

An SQL predicate restricts the final result before counting and pagination;
its explicit allowed IDs also distinguish permission-dependent query-cache keys.
Permission decisions are not cached separately. A failed preflight read excludes
protected results. Anonymous searches retain core's exclusion with no extra read.
Admin/non-search queries are untouched. Queries with `suppress_filters`, custom
SQL, external indexes and plugins that replace queries are outside this filter.

## Verification

On a disposable Studio WordPress 7.1.3 / PHP 8.4.26 / SQLite site, the integration
fixture first failed on the Subscriber assertion against unmodified 0.6.9 code.
After implementation, `tests/integration/protected-search.php` passed 24 assertions:

- Anonymous/Subscriber exclusion, author own-post access and editor access.
- Per-post meta-cap grant and denial, including denial to an editor.
- Custom post type edit capabilities; authorship alone grants no exception.
- Valid/invalid password cookies and plugin password-access grants.
- Body-only search-term inference and the standard excerpt masking behavior.
- Result counts, filled second page, ID-only queries, explicit protected queries
  and core negative-term parsing.
- Unchanged admin/non-search queries and explicit filter suppression.
- Removing the callback restores the baseline Subscriber disclosure.

A separate fresh WordPress load with the saved setting off confirmed no callback
was registered. Missing-key default-on behavior was exercised before adding the
new key to the lab's stored settings. The fixture also passed on a network-activated
local multisite. Across fresh loads, a network `no` overrode saved site `yes`, and
removing that override restored the site policy. The standalone test covers clause guards,
bulk priming, failure behavior and preservation of unrelated clauses. The real
fixture is also wired into every live matrix row, including MySQL/PHP 7.4.

Local full standalone suite, WPCS, PHP 7.4 compatibility analysis, existing level-6
PHPStan scope and its 43 invalid contract cases / 46 diagnostics pass. POT was
regenerated and the active en_CA source entry updated. No PHP floor or release
version change is made.

## Targeted performance check

Same disposable site, 250 matching protected posts, default object cache. Each
mode had five warm-ups followed by 30 interleaved samples, alternating order.
WP_Query result/meta/term caching was disabled for both modes. This measures query
work within one WordPress process, not full HTTP requests or production latency.

| Visitor | Policy off median / p95 ms | Policy on median / p95 ms | Median SQL queries off / on |
| --- | ---: | ---: | ---: |
| Anonymous | 2.798 / 3.095 | 2.977 / 3.080 | 1 / 1 |
| Subscriber | 4.056 / 4.269 | 11.147 / 11.417 | 2 / 2 |

Logged-in search adds a preflight read and access work proportional to matching
protected rows. In this fixture the now-empty result avoids core's count query,
so the total query count happens to stay equal; that is not a general zero-query
claim. The measured incremental median is 7.091 ms for 250 protected matches.
It is deliberate feature cost, not a refactor-equivalence claim against the
[earlier budget](performance-budget.md). Ordinary non-search routes add no query
work. Larger protected collections, costly access filters, persistent caches and
MySQL timing have not been benchmarked here.

## Release and issue triage

#275 merged as `15e5c25` after all checks passed. Its initial counterpart check
failed solely for missing PR-description metadata; the Keel-only release-record
explanation corrected it without code changes.

At issue review, #265 was the sole open GitHub issue: optional list-table compaction.
The helper-column code is unchanged between v0.6.8 and v0.6.9. No issue establishes
a newly shipped regression; this does not rule out unreported faults. The search
policy is separate work. Next implementation is shortcode hiding paired with
Site Health evidence, as scoped in TODO and ROADMAP.

## Final CI evidence and upstream classification

[CI on f0c1a17](https://github.com/dknauss/Keel/actions/runs/38836801058)
passed lint/unit and PHP 7.4–8.5. The [final live matrix](https://github.com/dknauss/Keel/actions/runs/38836840613)
passed all six rows, each reporting all 24 search assertions, including the
PHP 7.4 / WordPress 6.4 branch and the real MySQL multisite row. The same runtime
also passed the earlier 20-assertion matrix. The local package contains 41 runtime
files, is 232031 bytes, and excludes tests, dependencies and review docs. The
disposable Studio site was stopped and deleted after verification.

The historical plugin code excluded protected results in all front-end searches
and disabled itself once core incorporated the anonymous restriction. Its author
explicitly documented search-term inference; no evidence was found establishing
his awareness of the remaining logged-in exception. Authenticated inference about
password-protected body text could warrant an information-disclosure classification;
no core security-team determination or severity rating is claimed here.
Following [WordPress's reporting policy](https://make.wordpress.org/core/handbook/testing/reporting-security-vulnerabilities/),
the recommendation is private security-team assessment before filing a new public
Trac report on that concern. No upstream report or message has been sent.
