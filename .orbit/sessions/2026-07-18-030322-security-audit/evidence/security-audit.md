# Security audit evidence

Date: 2026-07-18

## Confirmed findings remediated

- Gateway-owned WireGuard peer private keys and pre-shared keys were stored as plaintext in SQLite. The model now uses encrypted casts, and a forward-only, restart-safe migration encrypts existing rows while widening the pre-shared-key column.
- Gateway API and router-colocated gateway TLS leaf keys were installed with mode `0644`. Private keys now converge to `0600`; public certificates remain `0644`. Routine stack convergence also repairs existing config-root and host-mounted TLS private keys.
- Gateway config-root directories and credential-bearing `.env`, `gateway.sqlite`, and operations Reverb app configuration now converge to owner-only modes.
- Gateway image startup creates fresh credential state securely and repairs pre-existing config, Reverb, database, and TLS modes before APP_KEY generation or runtime startup.
- The host installer now creates gateway config directories at `0700`, creates or repairs `.env` and SQLite state at `0600`, and securely creates every `.env` replacement at `0600` before writing APP_KEY or other values.
- Websocket `app.key`, `apps.php`, and the source-runtime shared `.env` now converge to `0600`, including pre-existing files. New or replaced credential files are securely created at `0600` before content is written.
- Gateway-container host-path prefix mapping now rejects relative and traversal-bearing prefixes before any TLS key glob or chmod.

## Audit coverage

- Dependency advisories: Composer audit passed for `apps/cli`, `apps/docs`, `apps/e2e`, `apps/gateway`, `apps/reverb`, `packages/core`, and `packages/sdk`; zero advisories and zero abandoned packages. npm audit passed for `apps/macos` and `packages/sdk-typescript`; zero vulnerabilities.
- Secret scan: `bin/orbit-secret-scan` passed over the repository-owned source and configuration surfaces.
- HTTP/API authorization: inventoried 176 API-related routes. Routes outside `WireGuardIdentity` were limited to the public CA root, status, signed update artifacts, and restricted Scramble documentation routes. Sensitive API routes retained identity and permission middleware.
- Injection and unsafe execution: inspected raw-query inventory, outbound HTTP call sites, `proc_open`, `shell_exec`, and direct `exec` usage. No confirmed SQL injection, command injection, SSRF, or path-traversal defect remained in the audited source.
- File-mode inventory: scanned installer and runtime writes for `.key`, `app.key`, `apps.php`, and `.env` mode declarations. The only public keyring file remains `bin/install-orbit`; credential-bearing files now converge to owner-only modes.
- Optional external scanners `cargo-audit`, `gitleaks`, `semgrep`, and `trivy` were not installed. Their absence is recorded as a tooling limit, not a passing result.

## Programmatic verification

- Gateway focused remediation tests: 17 tests, 194 assertions.
- CLI focused remediation tests: 15 tests, 122 assertions.
- Migration restart-safety test: 1 test, 10 assertions.
- First-gateway provisioning contract: 9 tests, 66 assertions.
- Routine gateway TLS drift regression: 1 test, 2 assertions; combined gateway installer and provisioning contracts: 16 tests, 182 assertions.
- Gateway entrypoint fresh/existing mode contracts: 5 tests, 26 assertions for the complete entrypoint file.
- Host installer fresh/existing executable mode contracts: 2 data sets within 5 tests, 30 assertions for the focused installer bootstrap file.
- Websocket fixed-argv runtime contract: 16 tests, 129 assertions, including secure-create-before-write ordering.
- Full gateway Pest lane: 4,958 tests, 28,838 assertions.
- Full CLI quality Pest split: 2,330 tests, 9,634 assertions.
- Docs Pest lane: 169 tests, 11,565 assertions.
- Core Pest lane, standalone: 129 tests, 538 assertions.
- Scoped Mago format/analyze checks and `git diff --check`: PASS.
- Root `composer docs-lint`: PASS as part of the aggregate quality run.

The final exact candidate passed `ORBIT_QUALITY_CHECK_CPU_BUDGET=1 composer quality-check` with every Pest, Mago analyze/lint/format, Rector, docs, and Cargo subgate at exit zero. The serialized supported CPU profile avoided the concurrent runner contention observed during earlier aggregate attempts. Exact receipt and profile:

- `.orbit/quality-gates/quality-check-2026-07-18T010133Z-e9563b20280c.json`
- `.orbit/quality-gates/profiles/2026-07-18T00-55-59Z-093c6a3266fc`

## Runtime verification

Retained Incus topology evidence: `.orbit/evidence/runtime-proof.txt`.
