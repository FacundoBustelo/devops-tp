#!/usr/bin/env bash
set -euo pipefail
if [[ ! ${1:-} =~ ^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]]; then
    echo 'Release requires a stable vMAJOR.MINOR.PATCH tag without leading zeros.' >&2
    exit 1
fi
