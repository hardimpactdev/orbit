#!/bin/bash
# Private DNS has a 30-second TTL. Preserve its TTL plus one second before withdrawal.
set -euo pipefail
grace_status=0
trap 'grace_status=$?; if [ "$grace_status" -eq 124 ]; then printf "route-dns-grace timed out after 35 seconds\n" >&2; fi' EXIT
timeout 35 /usr/bin/sleep 31
