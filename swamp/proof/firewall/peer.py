"""Prove a deny on 7297 holds against a WireGuard-trusted peer while the allowed source gets in, then check order.

The target trusts 10.231.0.0/24 as `orbit:wireguard-members` and listens on 7297 and 7298. The peer 10.231.0.3
reaches 7298 through that trust, so only the deny keeps it from 7297. The arguments go to check.py.
"""
import http.client
import os
import subprocess
import sys


def reaches(source, port):
    connection = http.client.HTTPConnection('10.231.0.10', port, timeout=3, source_address=(source, 0))
    try:
        connection.request('GET', '/')
        return connection.getresponse().status == 200
    except OSError:
        return False
    finally:
        connection.close()


subprocess.run(['ip', 'address', 'replace', '10.231.0.3/24', 'dev', 'oeproof'], check=True, timeout=30)
facts = {'peer reaches 7298 through WireGuard trust': reaches('10.231.0.3', 7298),
         'peer is denied 7297': not reaches('10.231.0.3', 7297),
         'allowed source reaches 7297': reaches('10.231.0.1', 7297)}
print(facts)
assert all(facts.values()), facts
sys.exit(subprocess.run([sys.executable, '-I', os.path.join(os.path.dirname(__file__), 'check.py'), *sys.argv[1:]], check=False).returncode)
