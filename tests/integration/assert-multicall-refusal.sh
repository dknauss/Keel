#!/usr/bin/env bash
#
# Assert that a refused system.multicall is a fault a client can read.
#
# Keel refused multicall with fault code 405, and with remote publishing off
# (the default) core sends the fault code as the HTTP status. The response went
# out as HTTP 405, so WordPress.com reported "transport error - HTTP status code
# was not 200 (405)" against a connected Jetpack site instead of a fault.
#
# This sends the request that reproduced it — an unsigned system.multicall to
# xmlrpc.php?for=jetpack — and asserts the two things that have to hold with
# Keel's default settings:
#
#   1. the response is HTTP 200, not a status borrowed from the fault code;
#   2. the fault is -32601, and the request is still refused: for=jetpack is
#      not a signature, and must not be what lets a multicall through.
#
# It cannot assert the other half — that a request WordPress.com really signed
# gets through — because only WordPress.com holds the token to sign one. On a
# Jetpack-connected site, check that half from WordPress.com (the plugin list
# loads; a settings change saves) and in Site Health ("Jetpack and XML-RPC").
# tests/xmlrpc-multicall.php covers it with a stand-in for Jetpack's verifier.
#
# Usage:
#   PROBE_URL=http://127.0.0.1:9371 bash tests/integration/assert-multicall-refusal.sh
#   PROBE_URL=https://example.com   bash tests/integration/assert-multicall-refusal.sh

set -euo pipefail

: "${PROBE_URL:?Set PROBE_URL to the site root, e.g. http://127.0.0.1:9371}"

body='<?xml version="1.0"?><methodCall><methodName>system.multicall</methodName><params><param><value><array><data><value><struct><member><name>methodName</name><value><string>system.listMethods</string></value></member><member><name>params</name><value><array><data></data></array></value></member></struct></value></data></array></value></param></params></methodCall>'

out="$(mktemp)"
trap 'rm -f "$out"' EXIT

status="$(curl -sS -o "$out" -w '%{http_code}' -H 'Content-Type: text/xml' --data "$body" "${PROBE_URL%/}/xmlrpc.php?for=jetpack")"

fail=0

if [[ "$status" != "200" ]]; then
	echo "FAIL  HTTP status is ${status}, not 200: clients will report a transport error instead of a fault."
	fail=1
else
	echo "ok    HTTP 200"
fi

if grep -q '<int>-32601</int>' "$out"; then
	echo "ok    refused with fault -32601"
elif grep -q '<name>faultCode</name>' "$out"; then
	echo "FAIL  refused, but with fault code $(grep -o '<int>[-0-9]*</int>' "$out" | head -1) rather than -32601."
	fail=1
else
	echo "FAIL  an unsigned multicall was not refused. Either multicall is allowed on this site, or for=jetpack alone let it through."
	fail=1
fi

exit "$fail"
