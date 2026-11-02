# shellcheck shell=bash
# Run one Schedule command as the current user: $0 journal identifier, $1 working directory (empty: home),
# $2 non-empty for a login shell, $3 a fixed shell (empty: the user's shell). The command arrives on stdin; its output goes to the journal only.
IFS=: read -r _ _ _ _ _ home shell < <(getent passwd "$(id -un)")
shell=${3:-$shell}
cd -- "${1:-$home}" || exit 1
user=$(id -un)
# shellcheck disable=SC2016 # The inner command expands in the inner shell.
exec /usr/bin/env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin HOME="$home" SHELL="$shell" \
  USER="$user" LOGNAME="$user" /bin/bash -o pipefail -c '"$@" 2>&1 | /usr/bin/systemd-cat --identifier "$0"' \
  "$0" "$shell" ${2:+--login} -s
