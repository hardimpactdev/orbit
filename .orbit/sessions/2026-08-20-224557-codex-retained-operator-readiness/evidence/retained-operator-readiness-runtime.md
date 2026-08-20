# Retained operator readiness runtime proof

- Candidate: `a6dd97cebcb83fb9998ae3d18b945a27dfc15574`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Command: `composer e2e:incus -- --start --topology=operator --checkout-roles=operator --json`
- Expected: acquire the documented one-node `operator` topology, overlay the current checkout, and return without waiting for a gateway API that cannot exist.
- Observed: command exited 0 with topology `dev-4bec46`; the manifest contained only `operator` (`orbit-e2e-dev-4bec46-operator`), no gateway instance, and no `gateway-api.ready` timing phase.
- Runtime checkout: a Solo terminal entered `/home/orbit/orbit-run` in the retained operator instance, resolved `/usr/local/bin/orbit` to `/home/orbit/orbit-run/apps/cli/orbit`, ran `orbit --version`, and listed commands. The remote command exited 0.
- Result: passed

The topology was released with `composer e2e:incus -- --stop --id=dev-4bec46 --json` after proof collection.
