"""Temporary recording SSH constants for analytics_disable_native_test.py; Route cases now run in Deno."""

SSH = r"""
import json, shlex, sys
args = sys.argv[1:]
if '-M' in args or '-O' in args:
    raise SystemExit(0)
with open(LOG, 'a') as log:
    log.write(json.dumps(shlex.split(args[-1])) + '\n')
raise SystemExit(CODE)
"""
PIN = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"
