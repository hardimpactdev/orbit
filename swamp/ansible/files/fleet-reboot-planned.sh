#!/bin/sh
# The planned reboot of the swamp Node in a fleet.reboot pass (ADR 0251). A path unit starts it once the pass run has ended and
# created the marker in $1. It drains Swamp with Swamp's own stop: serve finishes its runs for up to 5 minutes, and each worker
# finishes its active steps within the drain budget in $2 seconds. Then the Node reboots; the shutdown guard of the units
# (ShutdownFastStop) ends what is left at once.
set -u
rm -f -- "$1"
timeout 330 systemctl stop orbit-swamp-serve.service || :
timeout "$2" systemctl stop 'orbit-swamp-worker@*.service' || :
exec systemctl reboot
