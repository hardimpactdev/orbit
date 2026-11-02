# Vaultwarden CLI integration

Setup and sync run the pinned official Bitwarden CLI directly on Swamp workers.
No script assets are shipped or copied to Nodes. The public planning behavior
only serializes frozen intent and shapes reconciliation metadata.

Native command steps bound each CLI action. Bash enables pipefail so a failed
CLI cannot become a successful filter. Credentials enter through vault-backed
environment values; shell builtins export only the needed CLI credential. Cipher
payloads reach the CLI on stdin. Raw inventory and write responses are filtered
with jq before native stdout, retaining only public IDs and ledger metadata.
CLI diagnostics are discarded because they may contain credentials.

Setup verifies every new request, including its harmless write probe. A native
resume keeps completed verification. Its cleanup runs after verification succeeds
or fails, and a separate completed dependency always removes the private cache.
Session files use umask 077. No second login is used for the end check.

The Gateway maps setup's public refusal exit codes and records the terminal
result. Sync keeps native per-item write receipts for summary resume, records
only a complete ledger, and ends with a native assert. Empty change sets still
log out, remove the cache and return the unchanged ledger.
