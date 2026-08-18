# Todo 766 — Retained-Incus Runtime Proof

- Candidate: `896400dc7428b749ce88fd48e23cddf5276c66ce`
- Venue: retained-incus
- Environment: dev-fixture
- Topology: `operator_gateway_app-dev` id `dev-aac924` (host `beast`)
- VMs: `orbit-e2e-dev-aac924-{operator,gateway,dev}`
- Bind: sha256 of `S3PublishAction.php` and `S3PublishCommandTest.php` match exactly
  between the worktree candidate and the operator VM mount `/home/orbit/orbit`.
- Note: acquired with `COMPOSER_PROCESS_TIMEOUT=0` (default 300s composer wrapper timeout
  trips at the wireguard phase under heavy host load).

## Part A — gateway boots on the candidate

The topology brought up `operator`, `gateway`, `dev` on the candidate checkout; the
gateway API reported ready (`gateway-api.ready`) with WireGuard gateway `10.6.0.2` / dev
`10.6.0.4`. The single-engine refactor does not break the deployed gateway boot.

## Part B — single S3 publish engine behavior on the deployed runtime

Ran the S3 command Pest inside the operator VM against an isolated disposable test DB
(NOT `composer test:e2e*`):
`ssh beast incus exec orbit-e2e-dev-aac924-operator -- sudo -u orbit bash -lc 'cd
/home/orbit/orbit/apps/gateway && DB_DATABASE=/tmp/766-test.sqlite APP_ENV=testing
HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan test tests/Feature/Commands/S3 --compact'`.

Observed: **113 passed (392 assertions)** on the retained-topology runtime — proving on
the deployed gateway that:
- the single `publishWithProgress()` engine (the dead duplicate `publish()` removed) runs
  the full route-publishing flow, including the migrated preflight and domain-conflict
  cases;
- the progress error-code→status mapping is stable (validation_failed,
  authorization_failed, proxy.domain_conflict, s3.publish_failed and the unpublish
  siblings) across the renderer/command surface;
- the no-progress path and engine-layer coverage pass.

Result: passed.
