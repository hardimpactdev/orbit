# Retained Incus Doctor proof — dev-4d398b (post-main-merge tip)

**Feature HEAD:** `d8b302c613d0f5cbbf3f6395ec289223fe893e26`  
**Integrated main:** `6dbcbd2d0292db36f7645a6fc8e2e9fa86c1e340`  
**Topology:** `id=dev-4d398b` `kind=operator_gateway` provider=incus host=beast  
**Start:** `incus-start-operator_gateway-d8b302c61.json`  
**Stop:** `incus-stop-dev-4d398b.json`

## Controlled path: `node.agent_expectation_stale`

1. Inject synthetic `installed_agent` on gateway DB (`managed=false`, no agent intent)
2. **Verify before:** genuine_drift, restorable=true — `doctor-verify-agent-before-dev-4d398b.json`
3. **Restore (same-command acceptance):** healthy=true, issues=[], fixed=1, passes=1, stop_reason=**converged**, action completed — `doctor-restore-agent-dev-4d398b.json`
4. **Fresh verify:** healthy=true, issues=[] — `doctor-verify-agent-after-dev-4d398b.json`
5. **Non-genuine:** `node.platform_record_mismatch` → invalid_intent, restorable=false — `doctor-verify-non-genuine-dev-4d398b.json`

## Quality on this tip

`.orbit/quality-gates/quality-check-2026-08-03T172313Z-eefa12a4ceee.json`  
`git.commit=d8b302c613d0f5cbbf3f6395ec289223fe893e26` `dirty=false` `exit_code=0`
