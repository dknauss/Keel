#!/bin/bash
# run.sh <sites to serve> -- <probes.py args...>
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/env.sh"
export WP_CLI_PHP_ARGS="-d memory_limit=512M"
sites=(); while [ "$1" != "--" ]; do sites+=("$1"); shift; done; shift
db_start; web_start "${sites[@]}"
trap 'web_stop; db_stop' EXIT
for i in $(seq 1 30); do curl -s -o /dev/null --connect-timeout 1 http://127.0.0.1:$(port_of ${sites[0]})/ && break; done
python3 -u "$HERE/probes.py" "$@"
