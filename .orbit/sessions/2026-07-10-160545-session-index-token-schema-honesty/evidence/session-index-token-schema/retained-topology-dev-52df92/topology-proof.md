# Retained Topology Proof

## Topology

- Id: `dev-52df92`
- Kind/provider/host: `operator_gateway` / `incus` / `beast`
- Checkout role: `operator`
- Inspected instance: `orbit-e2e-dev-52df92-operator`
- Supporting gateway instance: `orbit-e2e-dev-52df92-gateway`
- Source-mounted execution checkout: `/home/orbit/orbit-run`
- Solo validation terminal: process `1014`, `session-index-token-schema-topology-dev-52df92`
- Acquisition command: `composer e2e:incus -- --start --topology=operator_gateway --checkout-roles=operator --json`
- Acquisition result: exit `0`

## Exact Source Identity

- Feature commit: `66dda9f88211b524018fc434540f8e26b63c9108`
- Local `bin/orbit-session-index` SHA-256: `208d4a4e2b0431111a68f283bb7c7ececfb31bd86e03cdc7d09895ab2af46dfb`
- Operator-VM `bin/orbit-session-index` SHA-256: `208d4a4e2b0431111a68f283bb7c7ececfb31bd86e03cdc7d09895ab2af46dfb`
- Identity result: exact match.

## Bounded Fixture Corpus

From the attached VM shell at `/home/orbit/orbit-run`, terminal 1014 ran:

```text
bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/SessionIndexTest.php
```

Result: exit `0`; 9 tests passed with 312 assertions in 6.91 seconds. The focused suite creates isolated archives and runs the real `bin/orbit-session-index` process across unavailable, consistent, partial, invalid, inconsistent, precedence, object-shape, and aggregate-manifest boundaries. Pest emitted a non-fatal cache-directory permission warning from the hydrated read-only runtime; the test result and command exit remained successful.

## Runtime Overlay Boundary

A preliminary `bin/orbit-session-index --check` reported the index missing because the retained source overlay does not hydrate active `.orbit/sessions/index.json`. This was not treated as product evidence or an IX failure. The authoritative 88-archive corpus check already passed locally at the exact feature commit; the retained VM assertion is the self-contained focused fixture corpus with matching helper identity.

## Evidence

- Solo terminal: process `1014`, preserved through feature completion.
- Rendered terminal output: `.orbit/evidence/session-index-token-schema/retained-topology-dev-52df92/terminal-1014.txt`
- Quality artifact for the same commit: `.orbit/quality-gates/quality-check-2026-07-10T134421Z-648b3c0880b5.json`

## Cleanup

After fresh-analyzer and merge completion, the explicit release command `composer e2e:incus -- --stop --id=dev-52df92 --json` returned `not_found`, showing the retained-topology registry entry was already absent. The direct host proof `ssh beast 'incus list "orbit-e2e-dev-52df92*" --format csv -c n'` then exited `0` with empty output: neither owned instance remained. Cleanup result: no `dev-52df92` topology resources remain on `beast`.

## Result

`Retained topology proof: passed`
