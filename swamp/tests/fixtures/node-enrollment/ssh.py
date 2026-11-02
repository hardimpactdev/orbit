#!/usr/bin/python3
"""Harmless binary seam; every unexpected Node command fails the test."""
import os
import sys

if '-O' in sys.argv or '-N' in sys.argv or '-MNf' in sys.argv:
    sys.exit(0)
command = sys.argv[-1]
if 'uname' in command:
    print(os.environ['FIXTURE_PLATFORM'])
elif 'ifconfig' in command:
    print('utun3: flags=8051<UP,POINTOPOINT,RUNNING,MULTICAST>')
    print('    inet ' + os.environ['FIXTURE_ADDRESS'] + ' --> 10.44.0.1 netmask 0xffffff00')
elif 'getent' in command:
    print('mac:x:1000:1000::/srv/mac:/bin/bash')
elif 'cat ' in command:
    print(os.environ['FIXTURE_PUBLIC_KEY'])
else:
    raise AssertionError('Unexpected enrollment command: ' + command)
