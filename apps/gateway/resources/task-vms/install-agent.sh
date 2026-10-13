#!/usr/bin/env bash
# Installs the pinned Node agent binary in a base image builder. Runs as root.
# $1 is the download URL, $2 its SHA-256. The agent itself is configured at enrollment.
set -eu
candidate=/usr/local/bin/orbit-agent.orbit-candidate
curl --fail --location --silent --show-error --connect-timeout 10 --max-time 300 --output "$candidate" -- "$1"
printf '%s  %s\n' "$2" "$candidate" | sha256sum --check --status
chown root:root -- "$candidate"
chmod 0755 -- "$candidate"
mv -fT -- "$candidate" /usr/local/bin/orbit-agent
