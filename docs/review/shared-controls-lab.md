# Shared settings controls: lab verification

2026-10-14, PR #250. Disposable WordPress Studio lab, PHP 8.4, converted
with WordPress's multisite installer; two sites; Keel network-activated from a
symlink to the branch. Reflection confirmed the new settings-controls.php loaded.
No production site was changed.

## Browser and persisted-state checks

- Super Admin opened Network Policy: menu width was a select and the Subscriber
  role label rendered. Saved managed width 240px and Subscriber exemption through
  the real nonce-bearing form; WordPress persisted exactly those network keys.
- Site settings showed the width slider and role checkbox as aria-disabled, with
  the lock/help description IDs. A forged options.php submission attempted to
  change the managed width and remove exempt roles. WordPress retained the stored
  width 200px and Subscriber choice; effective network width stayed 240px.
- Unticked both managed keys using the network form. Site controls unlocked and
  returned to the 200px preference. The slider announced 300px after changing it;
  saving through options.php persisted 300px on the primary site only.
- The second site's stored width stayed 200px. In its actual WordPress blog
  context, a forged save through the registered sanitizer retained 200px under a
  240px network lock; removing the network key restored effective 200px.
- A real site Administrator could open the site settings and its range control,
  but Network Policy returned HTTP 403. This user was not a Super Admin.

Studio's server did not route /second/wp-admin/ to the subsite's admin (404).
Consequently the second site's browser page was not tested; its settings and save
checks used real WordPress multisite APIs and the plugin's sanitizer. The browser
checks above used the primary site's admin and Network Admin. These are real
multisite checks, not stubbed tests, but not a MySQL or cross-browser matrix.

CI on the original PR head passed all checks, including PHP 7.4–8.5. No runtime
change was necessary after the lab pass. Temporary browser logs stay outside the
plugin checkout and release package.
