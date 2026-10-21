# Keel 0.7.0 candidate verification — 2026-10-20

Candidate preparation only. No release tag, GitHub Release, WordPress.org SVN
commit or production deployment has been made.

## Scope and notes

#284 is merged. The candidate includes search privacy and shortcode cleanup with
passive Site Health reporting, both default-on for existing sites without saved
choices; it also includes lock markers, linked lock summaries and accessible names
for network value controls. Typography remains outside Keel's scope.

Version header, asset cache-buster, stable tag, status documents and POT metadata
all state 0.7.0. The changelog is finalized and the upgrade notice is 296 characters
(the directory limit is 300). PHP minimum stays 7.4; WordPress minimum stays 6.4.

## Local release gates

Full PHP tests, PHPCS, PHPCompatibility, scoped PHPStan, 43 negative static
contract cases / 46 diagnostics, and PHP syntax checks pass. The screenshot check
is current: all five images were reviewed, with settings screenshots 4–5 retaken
for #284. The package builds with runtime PHP, CSS/JS, POT, readme, LICENSE,
CONTRIBUTING and SECURITY; tests, development dependencies and listing assets
are excluded.

## Candidate verification in progress

Fresh package installation on disposable Studio WordPress 7.1.3 / PHP 8.4 / SQLite
reports version 0.7.0, 42 persisted defaults, and both new policies enabled.
Behavior probes, Plugin Check/readme validation, upgrade simulation, candidate CI
and the six-row live core backport matrix are being recorded before PR completion.

## Remaining publication steps

Review and merge the release PR only after its checks pass. Run the counterpart
sweep again before tagging. Then create the release tag and use the protected
release/deployment workflow. Verify the published GitHub ZIP, WordPress.org
version, SVN tag and listing assets afterwards. This record does not authorize or
claim publication.
