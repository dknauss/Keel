# Login Answers lab (#378)

HTTP probes for `docs/review/probes/378-uniform-login.md`, on throwaway sites.
Lab-only: nothing here ships (`/tests` is in `.distignore`). The PHP
instruments (a mail and `wp_login_failed` recorder, a lockout switch, an
`allow_password_reset` refusal for one account, a settings helper that saves
through Keel's own sanitizer, and a `php -S` router for subdirectory
multisite) are written by `setup.sh` into the lab directory.

Needs: MariaDB server binaries, PHP with mysqli and pdo_sqlite, WP-CLI, Python 3,
a WordPress git clone with the tags you test, and for the SQLite cell a clone of
`WordPress/sqlite-database-integration`.

```sh
export KEEL_LA_LAB=/tmp/keel-login-answers-lab   # scratch; deleted at the end
export KEEL_LA_WP_GIT=/path/to/WordPress          # git clone https://github.com/WordPress/WordPress.git (fetch tags 6.4.7, 7.1.3)
export KEEL_LA_SQLITE_GIT=/path/to/sqlite-database-integration
export KEEL_LA_WPCLI=wp
export KEEL_LA_KEEL_092=/path/to/keel-0.9.2       # git worktree add <dir> v0.9.2, for P2
export KEEL_LA_WOO=/path/to/woocommerce           # unzipped plugin folder, optional, for P25
mkdir -p "$KEEL_LA_LAB/state"; openssl rand -hex 12 > "$KEEL_LA_LAB/state/.pw"

H=tests/integration/login-answers-lab
$H/setup.sh "s71 7.1.3 mysql 0" "s64 6.4.7 mysql 0" "sq 7.1.3 sqlite 0" "ms 7.1.3 mysql 1"
$H/run.sh s71 -- single s71      # P3, P5-P23, P32 (also s64, sq)
$H/run.sh s71 -- fresh s71       # P1, P31, P33
$H/run.sh s64 -- upgrade s64     # P2 (0.9.2 -> this branch, no save)
$H/run.sh s71 -- editor s71      # P30 single site
$H/run.sh ms -- multisite        # P26-P30 multisite
$H/run.sh s71 -- woo s71         # P25
$H/run.sh s71 -- timing s71      # P24 (also sq)
```

Each run starts MariaDB and `php -S` inside the one command and stops both on
exit. Results land in `$KEEL_LA_LAB/results/<phase>-<site>.json`; a line per
probe is printed as it runs. `.pw` holds the fixture password for the lab's
lifetime; delete `$KEEL_LA_LAB` afterwards.
