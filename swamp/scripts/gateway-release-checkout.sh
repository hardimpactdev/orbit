#!/usr/bin/env bash
# Advance the permanent preparer's checkout from the host mirror without exposing other refs or credentials.
set -euo pipefail
mirror=$1 sha=$2 directory=$3
shift 3
[[ $sha =~ ^[a-f0-9]{40}$ ]] || exit 64
source_git=(git --no-replace-objects -c safe.directory="$mirror" -C "$mirror")
[[ $( "${source_git[@]}" rev-parse --is-shallow-repository ) == false ]] || {
  echo 'The Gateway source mirror must contain complete history for fleet CLI release numbering.' >&2
  exit 1
}

# REVISION is published only after Composer, configuration and the web build succeed.
# Consume it before touching the checkout so a killed build cannot pass partial dependencies to its retry.
previous=$("$@" sh -eu -c '
  test ! -L "$1"
  mkdir -p -- "$1"
  cd "$1"
  # One-time compatibility with interrupted deployments made by the old tree-rotation script.
  rm -rf -- ../.gateway-next ../.gateway-previous
  if test -d .git && test ! -L .git && test -f REVISION && test ! -L REVISION; then
    head=$(git rev-parse --verify HEAD)
    if test "$(cat REVISION)" = "$head"; then printf "%s" "$head"; fi
  fi
  rm -f -- REVISION
' checkout "$directory")
revisions=("$sha")
if [[ $previous =~ ^[a-f0-9]{40}$ ]] && "${source_git[@]}" merge-base --is-ancestor "$previous" "$sha"; then
  revisions+=("^$previous")
else
  # Bootstrap, interrupted build, or a different history. Keep the worktree path but
  # discard incomplete packages and Git objects outside the newly admitted history.
  previous=''
fi
printf '%s\n' "${revisions[@]}" | "${source_git[@]}" pack-objects --stdout --revs | "$@" bash -euo pipefail -c '
  cd "$1"
  keep=()
  if [[ -n $3 ]]; then
    keep=(-e /apps/cli/vendor/ -e /apps/gateway/vendor/)
  else
    rm -rf -- .git
    git init --quiet --template=
  fi
  git index-pack --stdin >/dev/null
  git -c advice.detachedHead=false checkout --quiet --force --detach "$2"
  git clean -ffdxq "${keep[@]}"
' checkout "$directory" "$sha" "$previous"
