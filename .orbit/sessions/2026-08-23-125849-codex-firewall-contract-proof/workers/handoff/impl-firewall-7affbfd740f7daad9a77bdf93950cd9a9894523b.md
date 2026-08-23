candidate=7affbfd740f7daad9a77bdf93950cd9a9894523b

Closed D1 and D2 only. POLISH P1-P8 were not implemented.

D1: SQL Ubuntu eligibility now matches PHP. Exact lowercase `ubuntu` or GLOB `ubuntu_*` (case-sensitive, literal underscore). Parity covers `ubuntu_24-04-LTS` and `ubuntu_Noble`. Uppercase prefix and hyphenated `ubuntu-24-04` stay ineligible.

D2: Removed the dead remote-HEAD identity leg and the 14-file whitelist. After forced sync the rig compares the complete synced tracked tree on `/home/orbit/orbit`, fails closed on missing/extra/mismatched paths, and records `binding=synced-tracked-tree` plus `checkout_digest` in proof state/receipt.

Focused checks:
- bin/orbit-gateway-pest --compact tests/Unit/Services/Firewall/FirewallTargetPlatformTest.php tests/Feature/E2ESupport/FirewallRetainedProofHelperTest.php
- bin/orbit-gateway-vendor-bin mago analyze app/Services/Firewall/FirewallTargetPlatform.php
- bin/orbit-gateway-vendor-bin mago lint tests/Feature/E2ESupport/FirewallRetainedProofHelperTest.php

Quality-gate:
- composer quality-check exit 0 for 7affbfd740f7daad9a77bdf93950cd9a9894523b
- artifact=.orbit/quality-gates/quality-check-2026-08-23T103658Z-ca1a143c5437.json

Retained-Incus:
- host=beast target=dev-501dc2
- instances=orbit-e2e-dev-501dc2-operator,orbit-e2e-dev-501dc2-gateway,orbit-e2e-dev-501dc2-dev
- binding=synced-tracked-tree
- checkout_digest=034a408b1c0cdd6518c9ed142c307f9753be819555813c5110dcba028607ffcb
- receipt: passed - candidate=7affbfd740f7daad9a77bdf93950cd9a9894523b; venue=retained-incus; environment=dev-fixture; target=dev-501dc2; expected=allow-list-doctor-remove pass with owned comment and unrelated same-port preserved; observed=allow-list-doctor-remove passed, managed allow preceded deny, protected same-port survived; result=passed; evidence=`.orbit/evidence/firewall-retained-proof/7affbfd740f7daad9a77bdf93950cd9a9894523b.json`

Unverified:
- composer test:e2e* not run
- shared Beast topology retained
