#!/usr/bin/env python3
"""Regenerate blueprint-conflict-diagnostics.json from the readable PHP beside it."""
import json
from pathlib import Path

root = Path(__file__).resolve().parent


def wporg(slug, caption):
    return {"step": "installPlugin", "pluginData": {"resource": "wordpress.org/plugins", "slug": slug}, "options": {"activate": True}, "progress": {"caption": caption}}


blueprint = {
    "$schema": "https://playground.wordpress.net/blueprint-schema.json",
    "meta": {
        "title": "Keel — conflict diagnostics demo",
        "author": "dknauss",
        "description": "Keel's rolling main build beside Disable Comments, Classic Editor and Disable XML-RPC from wordpress.org, plus one stand-in host must-use plugin. Opens on the Dashboard notice; Site Health names each overlap and the setting that is not taking effect.",
    },
    "landingPage": "/wp-admin/",
    "preferredVersions": {"php": "8.3", "wp": "latest"},
    "login": True,
    "steps": [
        {"step": "installPlugin", "pluginData": {"resource": "url", "url": "https://wordpress-playground-cors-proxy.net/?https://github.com/dknauss/keel/releases/download/latest/keel.zip"}, "options": {"activate": True, "targetFolderName": "keel-defaults"}, "progress": {"caption": "Installing Keel (main build)"}},
        wporg("disable-comments", "Installing Disable Comments"),
        wporg("classic-editor", "Installing Classic Editor"),
        wporg("disable-xml-rpc", "Installing Disable XML-RPC"),
        {"step": "mkdir", "path": "/wordpress/wp-content/mu-plugins"},
        {"step": "writeFile", "path": "/wordpress/wp-content/mu-plugins/host-revision-limit.php", "data": (root / "conflict-demo-host-revisions.php").read_text()},
        {"step": "runPHP", "code": (root / "conflict-demo-configure.php").read_text()},
        {"step": "wp-cli", "command": "wp post create --post_type=post --post_title='A post to try the defaults against' --post_status=publish"},
        {"step": "runPHP", "code": (root / "conflict-demo-observe.php").read_text()},
    ],
}
(root / "blueprint-conflict-diagnostics.json").write_text(json.dumps(blueprint, indent=2, ensure_ascii=False) + "\n")
