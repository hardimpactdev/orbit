candidate=eac1f39212a60ebcefb62caa08686305b0ee5d53

Focused tests:
- cd packages/core && vendor/bin/pest --compact tests/Firewall
- bin/orbit-gateway-pest --compact tests/Unit/Services/Firewall/FirewallTargetPlatformTest.php tests/Unit/Services/Firewall/FirewallRuleShapeCanonicalizerTest.php tests/Unit/Services/Firewall/FirewallRuleProbeTest.php tests/Unit/Services/Firewall/FirewallRuleQueryTest.php tests/Unit/Services/Firewall/FirewallRuleIntentTest.php tests/Unit/Services/Convergence/UfwFirewallRuleTest.php
- bin/orbit-cli-pest --compact tests/Feature/InternalFirewallRuleCommandTest.php
- focused Mago on changed production PHP (gateway firewall/doctor/security, CLI LocalFirewallRuleAction, packages/core/src/Firewall)

Quality-gate:
- composer quality-check exit 0 for commit eac1f39212a60ebcefb62caa08686305b0ee5d53
- artifact=.orbit/quality-gates/quality-check-2026-08-23T093816Z-93680ea06bf1.json

Retained-Incus:
- host=beast topology=dev-501dc2
- instances=orbit-e2e-dev-501dc2-operator,orbit-e2e-dev-501dc2-gateway,orbit-e2e-dev-501dc2-dev
- checkout digest verified on remote /home/orbit/orbit-run against candidate worktree (not local launcher-only)
- receipt: passed - candidate=eac1f39212a60ebcefb62caa08686305b0ee5d53; venue=retained-incus; environment=dev-fixture; target=dev-501dc2; expected=allow-list-doctor-remove pass with owned comment and unrelated same-port preserved; observed=allow-list-doctor-remove passed, managed allow preceded deny, protected same-port survived; result=passed; evidence=`.orbit/evidence/firewall-retained-proof/eac1f39212a60ebcefb62caa08686305b0ee5d53.json`
- fixture retained for inspection until acceptance (not cleaned up)

Unverified boundary:
- composer test:e2e* was not run (human-only, out of scope)
