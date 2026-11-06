#!/bin/bash
# Reads the versions and the release state of a bootstrapped Gateway machine, one labelled line each, for the end check.
# Run by the step "Read the machine" of swamp/workflows/workflow-gateway-bootstrap.yaml.
set -u
echo "swamp: $(/usr/local/bin/swamp --version | head -n 1)"
echo "ansible: $(/opt/orbit/ansible/venv/bin/ansible-playbook --version | head -n 1)"
echo "commit: $(git -C "$HOME/orbit" rev-parse HEAD)"
echo "php: $(php --version | head -n 1)"
status=$(php "$HOME/orbit/apps/gateway/artisan" migrate:status --no-interaction)
if grep -q 'Ran' <<<"$status" && ! grep -q 'Pending' <<<"$status"; then
  echo "migrations: ok"
else
  echo "migrations: not ok"
fi
