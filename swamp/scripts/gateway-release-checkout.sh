#!/usr/bin/env bash
# Copy the admitted commit and its history to a fresh release without copying the mirror's remotes, credentials or other refs.
set -euo pipefail
mirror=$1 sha=$2 directory=$3
shift 3
[[ $sha =~ ^[a-f0-9]{40}$ ]] || exit 64
source_git=(git --no-replace-objects -c safe.directory="$mirror" -C "$mirror")
[[ $( "${source_git[@]}" rev-parse --is-shallow-repository ) == false ]] || {
  echo 'The Gateway source mirror must contain complete history for fleet CLI release numbering.' >&2
  exit 1
}
# The remaining arguments enter the preparer as orbit. A zero-argument receiver runs locally.
# shellcheck disable=SC2016
"${source_git[@]}" pack-objects --stdout --revs <<< "$sha" | "$@" sh -eu -c '
  cd "$1"
  git init --quiet --template=
  git index-pack --stdin >/dev/null
  git -c advice.detachedHead=false checkout --quiet --detach "$2"
' checkout "$directory" "$sha"
