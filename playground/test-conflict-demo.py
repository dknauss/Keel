#!/usr/bin/env python3
"""Check blueprint-conflict-diagnostics.json, then optionally run it on a local WordPress.

Always: validates against the official Blueprint schema (or BLUEPRINT_SCHEMA, a
local copy, where playground.wordpress.net is unreachable) and checks that the
embedded PHP matches the readable files beside it.

With KEEL_DEMO_WP=/path/to/wordpress: runs the blueprint's two runPHP steps and
verify-conflict-demo.php against that install. It must already have Keel as
keel-defaults, Disable Comments, Classic Editor and Disable XML-RPC active,
host-revision-limit.php in mu-plugins, and one published post — the state the
blueprint's install steps leave behind. Requires Python jsonschema.
"""
import json
import os
import subprocess
import tempfile
import urllib.request
from pathlib import Path

import jsonschema

root = Path(__file__).resolve().parent
blueprint = json.loads((root / 'blueprint-conflict-diagnostics.json').read_text())

if os.environ.get('BLUEPRINT_SCHEMA'):
    schema = json.loads(Path(os.environ['BLUEPRINT_SCHEMA']).read_text())
else:
    request = urllib.request.Request('https://playground.wordpress.net/blueprint-schema.json', headers={'User-Agent': 'Keel-Blueprint-Validator/1.0'})
    with urllib.request.urlopen(request, timeout=30) as response:
        schema = json.load(response)
jsonschema.Draft202012Validator(schema).validate(blueprint)
print('Blueprint: schema validation passed.')

steps = blueprint['steps']
php_steps = [s['code'] for s in steps if s['step'] == 'runPHP']
assert php_steps == [(root / 'conflict-demo-configure.php').read_text(), (root / 'conflict-demo-observe.php').read_text()], 'Regenerate with build-conflict-demo.py'
assert any(s['step'] == 'writeFile' and s['data'] == (root / 'conflict-demo-host-revisions.php').read_text() for s in steps), 'Regenerate with build-conflict-demo.py'
assert steps[-1]['step'] == 'runPHP', 'The observe step must run last: the divergence record is keyed to the active plugin set.'
print('Blueprint: embedded PHP matches the source files.')

wp = os.environ.get('KEEL_DEMO_WP')
if wp:
    with tempfile.TemporaryDirectory(prefix='keel-conflict-demo-') as directory:
        for name in ('conflict-demo-configure.php', 'conflict-demo-observe.php', 'verify-conflict-demo.php'):
            script = Path(directory) / name
            script.write_text((root / name).read_text().replace('/wordpress/', wp.rstrip('/') + '/'))
            subprocess.run(['php', str(script)], check=True, timeout=120)
