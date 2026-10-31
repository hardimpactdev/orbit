#!/usr/bin/python3
"""Harmless remote-worker host-key scanner fixture."""
import base64
import sys

assert sys.argv[1:] == ['-T', '10', '-p', '2222', 'dev.example']
print('dev.example ssh-ed25519 ' + base64.b64encode(b'public-host-key').decode())
