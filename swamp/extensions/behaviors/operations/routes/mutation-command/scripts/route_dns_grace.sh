#!/bin/bash
# Private DNS has a 30-second TTL. Preserve its TTL plus one second before withdrawal.
set -euo pipefail
exec timeout --verbose 35 /usr/bin/sleep 31
