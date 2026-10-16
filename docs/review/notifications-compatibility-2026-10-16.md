# WP Feature Notifications compatibility — 2026-10-16

Result: no Keel-specific regression found in the exercised prototype surfaces.
This is not end-to-end notification-delivery certification: the feature plugin's
notifications GET handler is still a hard-coded empty list, and persistent
notification delivery, email integration and legacy-notice migration remain
upstream work. No Keel runtime change was needed.

## Pinned inputs and environment

- WordPress 7.1.3, PHP 8.4.26, SQLite, Twenty Twenty-Five, disposable Studio site.
- WP Feature Notifications develop `171f84fe8b21aac0450b19dd1f46bdc63f971b8c`.
  No latest GitHub release was available. Built the committed npm lockfile using
  `npm ci --ignore-scripts` and `npm run build`, Node 24.21.0 / npm 11.19.0.
  Build succeeded with six dependency/Sass deprecation warnings.
- Generated build/wp-notifications.js SHA-256:
  `397b5e63b2d814445d96a315275cc36989c7d9e83a8a1af9e64bc91d8ccbb23f`.
- Keel: tagged v0.6.9 and development `f7e7c0e1a2cad0c5d3bf9b8142a864c138052ea8`.
  The subsequent #277 changes short descriptions only; it is not a new runtime
  compatibility measurement. Both Keel trees report version 0.6.9; the commit/tag,
  not that shared string, distinguishes the tested trees.
- Activation matrix: Notifications alone; Notifications + released Keel;
  Notifications + development Keel. Only one Keel tree active at a time.
- Browser: Chromium, 1200px and 390px viewports. Real Administrator, Editor and
  Subscriber accounts; a disposable local login helper issued real session cookies.
  Debug logging enabled, display disabled. No live site or external mail delivery.

The feature plugin's declared PHP 7.4 floor was not exercised on a PHP 7.4 runtime
in this pass. No MySQL, alternate-browser or complete accessibility claim is made.

## Checks and evidence

| Surface | Observation |
| --- | --- |
| Built hub, Notifications alone | Loads; explicit-adminbar client notice can be added and rendered. No uncaught console errors in the normal page load. |
| Released Keel | Hub loads, explicit synthetic notice renders and its Dismiss button removes it from the client store; authenticated notifications REST succeeds. |
| Development Keel | Same display/REST behavior; settings page retains Keel's outgoing-mail suppression notice outside the hub. |
| Network Admin | Super Admin hub loads; authenticated notifications GET succeeds, with no unexpected console errors. |
| Administrator, Editor, Subscriber | Each can load the admin hub and authenticated notifications GET returns 200 / empty list; channels exposes ten registered channels. |
| Anonymous permissions | With Notifications alone/default Keel, notifications and channels return 401; subscriptions returns 200 / empty stub. This is not evidence of access to actual subscription data. |
| Keel anonymous REST restriction enabled | Actual anonymous HTTP request returns 401 / rest_not_logged_in; authenticated browser wp.apiFetch still succeeds. |
| Admin menu width 300px | Menu computes to 300px; notifications panel remains 320px, right-aligned and within the 1200px viewport, without document overflow. |
| Mobile 390px | Open panel is 320px, bounds x=70 to x=390; no document overflow. |
| Keyboard smoke | With the bell focused, Enter opens and Escape closes the panel. This is not a complete focus-management audit. |
| Front-end toolbar hidden for non-admins | Subscriber front end has neither toolbar nor bell, as requested by Keel; Notifications JS API remains available and authenticated REST succeeds. Admin hub remains available. |
| Policy overlap report | No structural conflict attributed to Notifications. The mail-capture MU fixture appears only as unconfirmed pre_wp_mail overlap, as expected. |

### Mail boundary

A local MU capture ran after Keel's pre_wp_mail callback. It recorded whether the
final chain still permitted transport, then returned success without sending.
The first exploratory capture registered too early at the same maximum priority;
its misleading intermediate marker was discarded and the capture was moved to
wp_loaded, after Keel's registration. No such marker is treated as a product bug.

| Configuration | wp_mail result | Keel suppression action | Transport allowed to lab capture |
| --- | --- | --- | --- |
| Notifications alone, local | true | no | yes |
| Notifications + tagged Keel, local | true | yes | no |
| Notifications + development Keel, local | true | yes | no |
| Notifications + development Keel, production override | true | no | yes |

These prove Keel's existing wp_mail policy still works with Notifications active.
They do not prove a Notifications email channel, because that delivery integration
is not implemented in the tested source. No message left the lab.

## Upstream findings reported

Duplicate searches found existing work for every reproduced defect. Added evidence
to those issues instead of creating duplicates:

1. [Client store / public API, #619](https://github.com/WordPress/wp-feature-notifications/issues/619#issuecomment-6226206935).
   `wp.notifications.add()` with omitted optional context throws a TypeError;
   explicit `context: 'adminbar'` works. `get()` without a context returns null
   despite the wrapper's all-notifications description. Reproduced without Keel.
2. [PHP deprecations, #634](https://github.com/WordPress/wp-feature-notifications/issues/634#issuecomment-6226207236).
   All five JsonSerializable implementations emit return-type deprecations on
   PHP 8.4.26 too, extending the issue's PHP 8.5 observation. The examined debug
   log contained only these repeated deprecations, not PHP fatals or other warnings.
3. [Activation/migration/new sites, #616](https://github.com/WordPress/wp-feature-notifications/issues/616#issuecomment-6226207460).
   Network activation creates all three tables on existing sites. A site created
   afterwards has none of them and no notifications DB-version option. Removing
   Keel leaves the same missing state; network-reactivating Notifications creates
   the child's tables and version 1. There is no wp_initialize_site handler in
   the tested source. An empty successful notifications response hides this gap
   because its handler never reads the tables. Related design discussion: #64.

The new site's Keel settings were independently seeded (41 keys); the main site's
previously saved 40-key option remained intact and resolves the missing search
privacy setting through its schema default. No Keel migration regression found.

## Limits and follow-up

- Legacy PHP notices remain on their original screens. Notifications does not
  migrate Keel notices into the hub; that upstream capability is not implemented.
- Client dismissal is not persisted; backend delivery, polling/normalization and
  subscription writes cannot be certified from the empty GET stub.
- Do not enable or weaken an anonymous REST allowance to make an authenticated
  notifications API work; none was necessary.
- Re-run when upstream store, migration and delivery implementations land. Then
  test real notification persistence/delivery and cross-site recipient isolation.
- No production safety or security classification is inferred from empty endpoints.

The disposable site, synthetic accounts, local authentication helper and capture
were stopped/deleted after the pass. Sanitized local probe results and lab scripts
were retained under the maintainer's Codex verification directory; no credentials
or real message contents were retained in the record or issue comments.
