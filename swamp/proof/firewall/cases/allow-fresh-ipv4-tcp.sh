#!/usr/bin/env bash
# S1 case: one real `orbit firewall:allow` (new, source-restricted, IPv4 TCP) changes real traffic.
# Contract: swamp/proof/firewall/allow.cases.md, row "New restricted allow".
# Interface: same as swamp/proof/reset/cases/smoke.sh (PROOF_RESULT, PROOF_SOURCE, PROOF_EVIDENCE, PROOF_GENERATION).
# Optional: PROOF_RECEIPTS (receipt root), PROOF_GROUP (default 3187), PROOF_TOPOLOGY (label recorded in the receipt).
# Exit 0 = PASS, 1 = FAIL (assertion, cleanup, or the case could not be shown to fail), 2 = UNRUN (setup precondition not met).
# The case changes no product code. Test-owned state: one deny comment "proof:test-deny", two listeners. All removed in cleanup.
set -uo pipefail
exec python3 "$(dirname "${BASH_SOURCE[0]}")/allow_fresh_ipv4_tcp.py"
