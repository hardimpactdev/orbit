#!/usr/bin/env bash
# Creates the workspace template checkout $1 with the Project repository $2 as origin. The Gateway sends its branch.
# Runs as the managed user in a workspace template builder.
set -eu
git init --quiet -- "$1"
git -C "$1" remote add origin "$2"
