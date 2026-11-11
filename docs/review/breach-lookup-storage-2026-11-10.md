# Breach-lookup storage, allowance and settings ownership — 2026-11-10

Status: in review, unreleased. This record replaces the cache parts of the
[bounded HIBP budget design](../superpowers/specs/2026-10-23-hibp-budget-design.md)
and its [verification record](bounded-hibp-budget-2026-10-23.md). The allowance,
leases, diagnostic sampling and scope rules described there still stand.

## What changed

**No breach-lookup reply is kept.** A reply answers the check that asked for it
and is then discarded. Nothing about a password, its hash, its hash prefix or the
reply is written to the database or to a cache.

- The allowance is one non-autoloaded row per site, or per network on multisite:
  `keel_hibp_v2_<network>_state`. It holds the credit, up to two leases, the last
  sampled failure and the time of the last good reply.
- The 64 `slot_NN` rows are gone, and with them the claim and publish steps. A
  lookup is admitted with one compare-and-swap on the state row and finished in
  one short transaction. No lock spans the HTTP request.
- A definite answer is remembered for the rest of the PHP request that asked, and
  nowhere else, because the REST users route validates a password twice. It is
  bound to that request and to the scope. Unknown is never remembered.
- Rows written by earlier versions are deleted by name when the data version
  moves to 2, and again on WordPress's daily `delete_expired_transients` event.
  The daily pass covers a request that was still running the earlier code during
  the update, a rolling deploy, and a downgrade followed by an upgrade. A delete
  the database refuses leaves the data version where it was, so the next request
  tries again.
- Uninstall still deletes all 65 names for each network.

**Half the allowance is kept for privileged accounts.** With the default policy
that is 5 of 10. A check for anybody else is refused, and reported as an
exhausted allowance, once only the kept half is left. A check counts as
privileged when the person making the request, or the stored account whose
password is being set or used, holds one of the capabilities that already decide
which roles may be exempted from the password rules. Three cases never count:

- anything that is not a stored account, so a request cannot claim the status by
  describing a user;
- an account whose only role is the one registration hands out, while
  registration is open;
- on a network where visitors may create sites, a role on any site but the main
  one. A Super Admin always counts.

The half is derived from the policy's capacity and is not stored, so the
`keel_hibp_budget_policy` filter needs no new key.

**The login re-check screens the password as typed.** WordPress hands the login
form's password to plugins in slashed form. The re-check now removes that
slashing before it hashes, and leaves a password alone when it arrives unslashed.

**Settings every site on a network shares belong to whoever manages the
network.** On the main site, the three update policies, the translation update
setting and Public Names are locked for anyone who cannot manage network
options. The settings screen shows them as locked, and a save keeps the stored
values. Super Admins and single sites are unaffected.

## Trade-offs

- Checking the same password prefix twice in separate requests now spends
  allowance twice. Before, the second check within 12 hours read a stored reply.
- A site with one credit of capacity keeps nothing back: half of one rounds down
  to zero.
- The kept half protects checks for privileged accounts only. Other checks share
  what is left and return unknown when it is used up. Unknown still does not
  block a password change, and Site Health still samples it.

## Verification

Unit tests, run as standalone scripts with the rest of `tests/`:

- `tests/hibp.php`: one stored row, nothing password-derived in it, same-request
  reuse, a new request asks again, credit accounting, the two lookup lanes,
  lapsed leases, the kept half, deletion of earlier rows on upgrade and daily, a
  refused delete retried.
- `tests/password-privileged-allowance.php`: which accounts count as privileged
  on single site and multisite, and password changes with the allowance at the
  line.
- `tests/login-breach-screening.php`: the password is hashed as typed, with and
  without slashing, and the kept half at login.
- `tests/network-policy.php`: the shared settings are locked on the main site for
  an administrator who does not manage the network, and stay editable for one
  who does.

Database lab (`tests/integration/hibp-budget-runner.py`), run locally on MariaDB
10.11.14 with WordPress 6.4.3 and PHP 8.3, single site, independent PHP worker
processes: all 17 cases passed.

| Case | Shows |
| --- | --- |
| `adapter`, `scope` | Transactions, byte-exact compare-and-swap, and the right options table |
| `initialization`, `exact_burst`, `last_credit` | Racing workers never spend more than the allowance |
| `cap_two`, `lanes` | At most two lookups at once, each in its own lane |
| `reserve` | Ordinary checks stop at the kept half; privileged checks can use it |
| `stale_reservation`, `stale_http`, `stale_finish` | A worker whose lease lapsed cannot finish or record success |
| `kill_reservation`, `kill_http`, `finish_rollback` | A killed worker refunds nothing and leaves no half-written state |
| `no_retention` | After breached and clean lookups, the allowance row is the only row, and a new request asks again |
| `storage` | 256 distinct lookups, and a reply near the size cap, leave one row |
| `legacy_rows` | 64 earlier rows are deleted by the daily pass and by the upgrade, and neighbouring options are untouched |

The `connection_loss` and `uninstall` cases run only in the
`HIBP budget database lab` workflow, which also covers MySQL 8, WordPress 6.4
with PHP 7.4, two networks and Redis.

Each rule was also broken on purpose to confirm a case notices: admitting an
ordinary check into the kept half (`reserve`), keeping a reply (`no_retention`),
handing out one lane twice (`lanes`), and treating a lapsed lease as still held
(`stale_finish`).

The HTTP authorization lab gains a multisite case for the shared settings: a
main-site administrator's save leaves them unchanged and still saves that
administrator's own site-level setting, and a Super Admin's save changes them.
Run locally over real HTTP on WordPress 6.4.3 with MariaDB 10.11.14 and PHP 8.3,
the lab passed all 137 cases, two more than before. With the lock switched off
on purpose, the new case fails on the first shared setting.

The local WordPress tree came from a distribution package that defines
`WP_AUTO_UPDATE_CORE` inside `get_core_updates()`. That locks the core update
setting for everybody, so the package's change was removed from the disposable
copies before the run. The pull request's workflows use WordPress from
wordpress.org.
