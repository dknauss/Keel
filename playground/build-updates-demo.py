#!/usr/bin/env python3
"""Regenerate the self-contained hosted blueprint from the reviewed MU-plugin."""
import json
from pathlib import Path

root = Path(__file__).resolve().parent
blueprint = {
    "$schema": "https://playground.wordpress.net/blueprint-schema.json",
    "meta": {"title": "Keel Updates — Synthetic verification fixtures", "author": "dknauss", "description": "Unreleased branch preview. All evidence is synthetic; real downloads and installs are blocked. Toggle recent/stale attempted checks, inspect partial schedule Unknown and plugin/theme decisions changed by code."},
    "landingPage": "/wp-admin/update-core.php",
    "preferredVersions": {"php": "8.3", "wp": "latest"},
    "features": {"networking": False},
    "constants": {"DISABLE_WP_CRON": True},
    "login": True,
    "steps": [
        {"step": "installPlugin", "pluginData": {"resource": "url", "url": "https://wordpress-playground-cors-proxy.net/?https://github.com/dknauss/Keel/archive/refs/heads/codex/updates-screen-verification.zip"}, "options": {"activate": True, "targetFolderName": "keel-defaults"}, "progress": {"caption": "Installing unreleased Updates-screen branch"}},
        {"step": "mkdir", "path": "/wordpress/wp-content/mu-plugins"},
        {"step": "mkdir", "path": "/wordpress/wp-content/plugins/synthetic-keel-demo"},
        {"step": "writeFile", "path": "/wordpress/wp-content/plugins/synthetic-keel-demo/synthetic-keel-demo.php", "data": "<?php\n/* Plugin Name: Synthetic fixture plugin — no real updates */\n"},
        {"step": "mkdir", "path": "/wordpress/wp-content/themes/synthetic-keel-demo"},
        {"step": "writeFile", "path": "/wordpress/wp-content/themes/synthetic-keel-demo/style.css", "data": "/*\nTheme Name: Synthetic fixture theme — no real updates\nVersion: 0.0.0\n*/\n"},
        {"step": "writeFile", "path": "/wordpress/wp-content/themes/synthetic-keel-demo/index.php", "data": "<?php // Inactive synthetic fixture theme.\n"},
        {"step": "writeFile", "path": "/wordpress/wp-content/mu-plugins/keel-updates-demo.php", "data": (root / "updates-screen-fixtures.php").read_text()},
    ],
}
(root / "blueprint-updates-screen.json").write_text(json.dumps(blueprint, indent=2, ensure_ascii=False) + "\n")
