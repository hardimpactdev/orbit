# Retained topology runtime proof — OpenClaw process + php-cli remove

- Tip: `496f895cdce72e3b12264fd98ed114668ec02ff5`
- Topology: `dev-92f4b4` (kind `operator_gateway_agent`, host Beast)
- Checkouts: operator/gateway/agent at `/home/orbit/orbit-run`

## OpenClaw process lifecycle

1. Synced candidate tip `496f895c` into retained topology.
2. `process:add openclaw-gateway` rendered and started as systemd.
3. Prior tip `80c625b9` failed: systemd consumed shell `$TOKEN_FILE` / substitutions before bash, port 8081 closed, restart loop with “openclaw gateway token missing”.
4. Fix at `496f895c` (`SystemdUnitRenderer` ExecStart `$` → `$$`; explicit `/home/agent/.openclaw/gateway.token`).
5. After sync/re-add:
   - `http://10.6.0.6:8081` → HTTP 200
   - process restart → running; after 8s HTTP 200 again
   - logs: explicit `/home/agent/.openclaw/gateway.token`, preserved `${TOKEN_FILE}` and command substitution, gateway ready/listening
   - token file mode `600`, owner `agent:agent`
   - systemd unit active; listener on 8081 yes

## php-cli remove

- `tool:install php-cli` then `tool:remove php-cli --force` succeeded
- subsequent `tool:list` empty for that tool on the target

## Broader quality

- `.orbit/quality-gates/quality-check-2026-08-02T173417Z-17c49a52df08.json`
- exit 0, `git.dirty` false, commit binds exact tip `496f895cdce72e3b12264fd98ed114668ec02ff5`

## Independent review (follow-up)

- BLAST_RADIUS: complete — SystemdUnitRenderer sole ExecStart path; openclaw/opencode-cli/polyscope/default commands inventoried; only OpenClaw uses shell dollars; dollar-less commands unchanged
- HUMAN_JUDGMENT: not-required
- VERDICT: PASS
- No actionable findings
