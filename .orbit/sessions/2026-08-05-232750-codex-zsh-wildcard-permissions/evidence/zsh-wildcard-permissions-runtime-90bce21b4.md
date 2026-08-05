# Runtime proof: zsh unquoted `process:*` (candidate 90bce21b4)

- **candidate:** `90bce21b4b3daf5e3ed6c0e05c135fe0af553208`
- **venue:** retained-incus
- **environment:** dev-fixture
- **topology:** `dev-66d5de`
- **target:** operator instance `orbit-e2e-dev-66d5de-operator`
- **source overlay (instance checkout):** `/home/orbit/orbit-run`
- **host source path:** `/tmp/orbit-e2e-sources/codex-zsh-wildcard-permissions-incus-139b2630ce24/retained/dev-66d5de`
- **fixture note:** zsh available / installed in disposable operator fixture as needed
- **quality gate:** `.orbit/quality-gates/quality-check-2026-08-05T211655Z-efc15960179a.json` (exit_code=0, dirty=false, all subgates 0)
- **secret scan:** `SECRET_SCAN: PASS` at same HEAD
- **canonical release command:** `composer e2e:incus -- --stop --id=dev-66d5de`

## Expected

1. Pre-integration exact unquoted command fails with zsh NOMATCH.
2. First-upgrade bridge: candidate `orbit --version --local --json` succeeds and installs integration.
3. With `ZDOTDIR` ≠ `HOME`, managed rc under ZDOTDIR only; snippet under HOME config.
4. Fresh interactive zsh preserves literal argv including `--add=process:*`.
5. Non-Orbit unmatched globs still NOMATCH.
6. Second bridge is idempotent (single marker/snippet counts on healthy path).

## Observed

| Step | Result |
| --- | --- |
| Pre-integration exact unquoted command | NOMATCH, `NEGATIVE_EXIT=1` |
| Bridge `orbit --version --local --json` | exit 0 |
| `HOME` vs `ZDOTDIR` placement | `HOME_ZSHRC_EXISTS=no`, `ZDOTDIR_ZSHRC_EXISTS=yes` |
| Fresh interactive exact unquoted command | `ARG:node:permissions`, `ARG:beast`, `ARG:main1`, `ARG:--add=process:*`, `POSITIVE_EXIT=0` |
| Unrelated unmatched glob | `NON_ORBIT_EXIT=1` |
| Second bridge | `SECOND_BRIDGE_EXIT=0`, `MARKER_COUNT=1`, `SNIPPET_COUNT=1` |

## Result

`RUNTIME_PROOF=PASS`
