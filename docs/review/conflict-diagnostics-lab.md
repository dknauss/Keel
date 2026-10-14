# Conflict diagnostics: lab verification

2026-10-14. Disposable WordPress Studio single-site lab, PHP 8.4. Keel was
symlinked into the plugin directory under `keel-defaults`; reflection confirmed
the new attribution, overlap and divergence files. No production site changed.

Fixtures were a regular plugin and a must-use plugin, both registering real
`xmlrpc_enabled` filters that incremented counters and returned true. Keel's
configured XML-RPC policy expected false. This is deliberately conflicting lab
code, not a recommendation for a live site.

## Outcomes

- Before the identity fix, the checkout basename `keel` disagreed with the
  installed slug `keel-defaults`, so Keel listed its own observers as rivals.
  The underlying helper body was unchanged by extraction. A separate regression
  reproduced the mismatch and failed before the fix.
- After the fix, the regular fixture was attributed as the sole structural rival.
  The must-use fixture stayed in the unconfirmed report, labelled by its file.
  Keel no longer reported itself. Regular plugin display names were preserved.
- Initial and refreshed diagnostic reports left both foreign callback counters
  unchanged. Only an actual WordPress filter run incremented them.
- That filter run returned the rival's true value and recorded the mismatch once.
  Repeated observations did not rewrite the record. Reads did not manufacture or
  execute a policy result. A real settings update cleared the stored divergence.
- The notice fingerprint changed when a new divergence appeared.
- In the browser, the dashboard named the rival and explained that sharing a
  hook is not proof of a conflict. The real nonce-bearing dismissal link hid the
  notice and persisted the user's dismissal. After recording a new divergence,
  the notice reappeared and named XML-RPC Remote Publishing as not taking effect.
- Site Health loaded with HTTP 200 and no page-level JavaScript exceptions.

The disposable lab and fixtures were stopped and removed. Temporary browser logs
were kept outside the plugin checkout and release package. This was not a live
WordPress.com, real-theme, or multisite pass; those attribution/context cases
retain their existing standalone regression coverage.
