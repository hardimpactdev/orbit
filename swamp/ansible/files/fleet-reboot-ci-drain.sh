#!/bin/sh
# One round of the CI drain of a fleet.reboot pass (ADR 0251). Each running runner container of the Incus project in $1 is
# frozen while the host reads its processes, so its listener cannot start a job between the read and the stop. A container
# without a `Runner.Worker` process is stopped; one with a job runs on. Autostart brings the stopped runners back at boot.
# Exit 0 when no runner runs a job any more, 3 while one does. A job on a runner of the host itself also counts as busy.
# ORBIT_PROC names the process folder, /proc unless a test sets it.
set -eu
project=$1
proc=${ORBIT_PROC:-/proc}
busy=0
paused=
# A failed command after a pause leaves no container frozen.
trap 'if [ -n "$paused" ]; then incus start --project "$project" "$paused" || :; fi' EXIT
job_in() {
  for pid in $(pgrep -f Runner.Worker || :); do
    if [ -n "$1" ]; then
      grep -qsE "lxc\.payload\.${project}_$1(/|$)" "$proc/$pid/cgroup" && return 0
    else
      grep -qs 'lxc\.payload\.' "$proc/$pid/cgroup" || return 0
    fi
  done
  return 1
}
for name in $(incus list --project "$project" --format csv --columns ns | awk -F, '$2 == "RUNNING" {print $1}'); do
  incus pause --project "$project" "$name"
  paused=$name
  if job_in "$name"; then
    incus start --project "$project" "$name"
    busy=1
  else
    incus stop --project "$project" --force "$name"
    echo "stopped $name"
  fi
  paused=
done
if job_in ''; then
  busy=1
fi
[ "$busy" -eq 0 ] || exit 3
