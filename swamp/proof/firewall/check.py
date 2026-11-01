"""Check `ufw status` on the proof target: `+row` lines exist in this top-to-bottom order, `-row` lines do not.

A row is `<to> <ALLOW|DENY> <from>`, optionally followed by ` # <comment>`; the comment is checked only when given.
"""
import os
import re
import subprocess
import sys

status = subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', 'ufw', 'status'],
                        check=True, capture_output=True, text=True, timeout=30).stdout
print(status)
rows = [re.fullmatch(r'(.+?)\s{2,}(ALLOW|DENY)(?: IN)?\s{2,}(.+?)(?:\s+#\s*(.*))?', line.strip()) for line in status.splitlines()]
rows = [(m[1], m[2], m[3].strip(), (m[4] or '').strip()) for m in rows if m]
assert status.startswith('Status: active'), 'UFW is active'
assert any(row[3] == 'proof-ssh' for row in rows), 'the unmanaged SSH allowance is kept'
last = -1
for expectation in sys.argv[1:]:
    sign, text = expectation[0], expectation[1:]
    want, _, comment = text.partition(' # ')
    to, action, source = re.fullmatch(r'(.+?) (ALLOW|DENY) (.+)', want).groups()
    found = [i for i, row in enumerate(rows) if row[:3] == (to, action, source) and (not comment or row[3] == comment)]
    if sign == '-':
        assert not found, 'absent: ' + text
        continue
    assert len(found) == 1, 'present once: ' + text
    assert found[0] > last, 'in order: ' + text
    last = found[0]
print('parity:', ' '.join(sys.argv[1:]))
