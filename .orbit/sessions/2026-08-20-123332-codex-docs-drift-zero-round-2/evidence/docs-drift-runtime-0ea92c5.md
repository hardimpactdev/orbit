# Round 2 retained-topology runtime proof

- Candidate: `5a9a9fb1a8ad5f04c247ac06ea0b9e04350bfcdf`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Topology: `dev-53da11` on `beast`
- Runtime checkout: `/home/orbit/orbit-run`
- Runtime launcher: `/home/orbit/orbit-run/apps/cli/orbit`

The gateway fixture contained app `docs` with `development` and `staging`
instances on `app-dev-1`. The operator fixture contained
`/tmp/orbit-docs-r2/.orbit/config` with selector `docs.development`.

## Candidate identity

The worktree and synchronized runtime checkout produced the same SHA-256 values:

- `CloudflareZoneResolver.php`: `d3688aecf74f71fa5a894575bfbae6a85bac8902894385a31e3d23b00c60223e`
- `AppShowCommand.php`: `5b9173799ee10a5a999b6f166ab6b8ec045e16eb4c9762f187aab8d7b8bdb83b`

## Observations

1. From the operator fixture, `ORBIT_HOST_CWD=/tmp/orbit-docs-r2/storage/cache ./apps/cli/orbit app:show --json` exited successfully and resolved app `docs` from the nearest Orbit marker. Its payload contained both instances.
2. A real-PTY capture of the same human command exited 0 in 1.239 seconds with a 0.010-second maximum output gap. It rendered the documented app summary and flat `NAME`, `DRIVER`, `NODE`, `URL`, and `APP DEPS` table for `development` and `staging`.
3. In the gateway fixture, `.orbit/evidence/cloudflare-runtime-success.php` bootstrapped the synchronized gateway application, installed an isolated three-response Cloudflare HTTP fixture, and called `CloudflareManager::addCacheRule('docs.staging')`.
4. That final hop completed successfully. It selected app `docs`, zone `staging.test`, performed three provider requests, and returned a ready cache rule with `browser_ttl=respect_origin` and `already_present=false`.

Result: passed.

The proof was refreshed after the review-only follow-up commit. That commit
changes only the documented process-label example. The synchronized runtime
files retain the exact candidate hashes above. The marker lookup and isolated
Cloudflare provider operation both completed successfully on the retained
topology.
