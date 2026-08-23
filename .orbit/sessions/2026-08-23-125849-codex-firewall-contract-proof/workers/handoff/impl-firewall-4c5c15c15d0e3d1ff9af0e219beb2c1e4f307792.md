candidate=4c5c15c15d0e3d1ff9af0e219beb2c1e4f307792

Retained proof rerun for the exact format-corrected SHA. The 9caca04b receipt is not this candidate.

Focused checks:
- bin/orbit-gateway-pest --compact tests/Unit/Services/Firewall/FirewallTargetPlatformTest.php tests/Feature/E2ESupport/FirewallRetainedProofHelperTest.php tests/Unit/Services/Firewall tests/Unit/Services/Convergence/UfwFirewallRuleTest.php
- cd packages/core && vendor/bin/pest --compact tests/Firewall
- bin/orbit-cli-pest --compact tests/Feature/InternalFirewallRuleCommandTest.php
- bin/orbit-gateway-vendor-bin mago analyze app/Services/Firewall/FirewallTargetPlatform.php

Quality-gate (kept, not rerun):
- composer quality-check exit 0 for 4c5c15c15d0e3d1ff9af0e219beb2c1e4f307792
- artifact=.orbit/quality-gates/quality-check-2026-08-23T100434Z-ec8d03340554.json

Retained-Incus (rerun for 4c5c15c):
- host=beast target=dev-501dc2
- instances=orbit-e2e-dev-501dc2-operator,orbit-e2e-dev-501dc2-gateway,orbit-e2e-dev-501dc2-dev
- checkout_digest=ae830c07f68e3303a1af820142eed42baac0f30f4d244390a9c125d033259595
- receipt: passed - candidate=4c5c15c15d0e3d1ff9af0e219beb2c1e4f307792; venue=retained-incus; environment=dev-fixture; target=dev-501dc2; expected=allow-list-doctor-remove pass with owned comment and unrelated same-port preserved; observed=allow-list-doctor-remove passed, managed allow preceded deny, protected same-port survived; result=passed; evidence=`.orbit/evidence/firewall-retained-proof/4c5c15c15d0e3d1ff9af0e219beb2c1e4f307792.json`

Unverified:
- composer test:e2e* not run
- shared Beast topology retained
