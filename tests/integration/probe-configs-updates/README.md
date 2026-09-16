# probe-configs-updates

Configuration for the update-policy comparison, kept separate from
`probe-configs/` because the same plugin can need opposite settings for the two
questions. Keel appears in both: the teardown run wants its XML-RPC endpoint
block *off* so the per-method rows mean something, and this run wants its core
update policy set to `minor` so it is answering the same question as everybody
else.

**Every file here sets the plugin to its own nearest equivalent of "install
automatic security and maintenance releases, do not install a new major".** That
is Keel's `core_update_policy = minor`, and it is the setting an operator
reaching for this category most often wants. Comparing a plugin configured to
"disable everything" against one configured to "minor only" measures the
configuration, not the plugin.

Where a plugin already ships that policy — Companion Auto Update seeds its table
with `minor = on, major = off`, Update Control and WP Auto Updater both default
to `core => minor`, Webcraftic falls through to `allow_minor_auto_core_updates`
when its option is unset — the file writes the same values anyway, explicitly.
A default that the next release changes would otherwise silently re-point the
comparison, and a config file that asserts what it expects is the cheapest place
to catch that.

`<slug>.post.php` is the same thing run **after** activation, for a plugin whose
storage does not exist before it. Companion Auto Update is the one here: its
settings are rows in a table of its own, and its deactivation hook drops that
table, so between runs there is nothing to configure and a pre-activation file
can only report it missing.

Run through `probe-plugin.sh` with both variables set:

```bash
PROBE_URL=http://127.0.0.1:9315 PROBE_PATH=/tmp/probe-wp \
PROBE_CONFIG_DIR="$PWD/tests/integration/probe-configs-updates" \
PROBE_SCRIPT=probe-updates.sh \
  bash tests/integration/probe-plugin.sh update-control "Update Control 1.5.1 (4k)"
```
