#!/usr/bin/env python3
"""Validate schema, run real WordPress fixture assertions using the Playground CLI.

Uses local code mounted in the disposable WASM filesystem; the hosted branch URL
is deliberately preserved in the committed blueprint for the parent's browser pass.
Requires npx and Python jsonschema (pip install jsonschema).
"""
import json
import subprocess
import tempfile
import urllib.request
from pathlib import Path

import jsonschema

root = Path(__file__).resolve().parent
blueprint = json.loads((root / 'blueprint-updates-screen.json').read_text())
with urllib.request.urlopen('https://playground.wordpress.net/blueprint-schema.json', timeout=30) as response:
    schema = json.load(response)
jsonschema.Draft202012Validator(schema).validate(blueprint)
print('Hosted blueprint: official JSON schema validation passed.', flush=True)
assert blueprint['steps'][-1]['data'] == (root / 'updates-screen-fixtures.php').read_text(), 'Regenerate the embedded fixture with build-updates-demo.py'
blueprint['steps'][0] = {'step': 'activatePlugin', 'pluginPath': 'keel-defaults/keel.php'}
blueprint['steps'].append({'step': 'runPHP', 'code': (root / 'verify-updates-demo.php').read_text()})
with tempfile.TemporaryDirectory(prefix='keel-demo-check-') as directory:
    path = Path(directory) / 'blueprint.json'
    path.write_text(json.dumps(blueprint))
    subprocess.run(['npx', '--yes', '@wp-playground/cli@latest', 'run-blueprint', '--blueprint=' + str(path), '--mount=' + str(root.parent) + ':/wordpress/wp-content/plugins/keel-defaults', '--verbosity=debug'], check=True, timeout=240)
