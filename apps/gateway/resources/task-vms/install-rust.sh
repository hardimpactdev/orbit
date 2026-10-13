#!/usr/bin/env bash
# Installs the stable Rust toolchain for the managed user with rustup's minimal profile, plus clippy and rustfmt, as
# CI checks the Node agent. Runs as the managed user in a base image builder.
#
# rustup adds ~/.cargo/bin only to the login shell's PATH, so each of its tools also gets a link in /usr/local/bin,
# which every shell finds: an agent's commands and a check's non-login shells too. Each link runs the managed user's
# toolchain. Each image build takes the stable release of its day.
set -eu
installer=$(mktemp)
trap 'rm -f -- "$installer"' EXIT
curl --proto '=https' --tlsv1.2 --fail --location --silent --show-error --connect-timeout 10 --max-time 300 --output "$installer" -- https://sh.rustup.rs
sh "$installer" -y -q --profile minimal --component clippy,rustfmt
"$HOME/.cargo/bin/cargo" --version
for tool in "$HOME"/.cargo/bin/*; do
    sudo ln -sfn -- "$tool" "/usr/local/bin/${tool##*/}"
done
