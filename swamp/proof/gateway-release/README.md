# Automatic Gateway release in a copy's nested fleet

A harness that drives the real five-minute `gateway:release:auto` tick of a copy's nested Gateway through one automatic release. Only the GitHub App edge is stubbed. The agent pin, its published release and its Sigstore provenance come from public GitHub through the egress proxy.

| File | Role |
| --- | --- |
| `github_stub.py` | Answers the api.github.com calls of the tick and the `gateway.release` run: installation, scoped tokens, main commits, check runs, compare, branch ref, web artifact, its CI run and its ZIP. Commits and ancestry come from the shared Git repository. A state file names the main head, the green commits and each commit's web ZIP. |
| `bootstrap-nested-gateway.sh` | Run as root in `nested-gateway`. `edge`: local CA, `api.github.com` certificate, hosts entry and `NO_PROXY` drop-ins. `layout`: shared repository, env and storage, taken from the mounted workspace. `release <sha> <zip>`: the first release, hand-deployed with the steps of `gateway-release-build.sh`, after the workspace mount is removed. `swamp <sha>`: a writable Swamp serve checkout, because a sealed release is read-only. |

## Run

1. Snapshot `nested-gateway`. Push both files, `web-dist.zip` (`apps/web` `bun run build`, then zip `dist/`) and `state.json` to `/opt/orbit-proof/`.
2. Run `edge` and `layout`. Stop the Gateway and Swamp units, then `incus config device remove nested-gateway checkout`. Run `release` with the first commit and `swamp`. Start the units again and run `artisan migrate --force`.
3. Start the stub: `systemd-run --unit=orbit-proof-github-stub python3 -I /opt/orbit-proof/github_stub.py --state /opt/orbit-proof/state.json --cert /opt/orbit-proof/api.pem --key /opt/orbit-proof/api.key`.
4. Store a throwaway GitHub App identity with a locally generated key through `GitHubAppStore::put`, then enable automatic releases with `GatewayReleaseAutomation::enable`.
5. Land a tree: set `main` in `state.json` to a newer commit and add it to `green`. The next tick starts the release.

## Cleanup

Stop `orbit-proof-github-stub`, then `incus snapshot restore nested-gateway <snapshot>`. This brings back the workspace mount and the earlier Gateway state.
