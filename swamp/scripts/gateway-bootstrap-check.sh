#!/bin/bash
# Reads the versions and the release state of a bootstrapped Gateway machine, one labelled line each, for the end check.
# Argument: the checkout of the release.
# Run by the step "Read the machine" of swamp/workflows/workflow-gateway-bootstrap.yaml.
set -u
checkout=$1
echo "swamp: $(/usr/local/bin/swamp --version | head -n 1)"
echo "ansible: $(/opt/orbit/ansible/venv/bin/ansible-playbook --version | head -n 1)"
echo "commit: $(git -C "$checkout" rev-parse HEAD)"
echo "php: $(php --version | head -n 1)"
status=$(php "$checkout/apps/gateway/artisan" migrate:status --no-interaction)
if grep -q 'Ran' <<<"$status" && ! grep -q 'Pending' <<<"$status"; then
  echo "migrations: ok"
else
  echo "migrations: not ok"
fi
