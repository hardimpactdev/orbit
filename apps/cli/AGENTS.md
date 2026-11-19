# Orbit CLI

Laravel Zero 13 client for Orbit. This file holds every CLI rule. Read no other instruction file before a change; the root `AGENTS.md` owns the workflow, checks and review.

## Boundaries

- Keep the CLI stateless except for explicit files under `$ORBIT_HOME`.
- A command that works on the Gateway validates explicit input, sends typed requests through `nckrtl/orbit-php-sdk` (`packages/php-sdk`) and renders human and JSON output. The Gateway owns policy: the CLI decides no access, placement or validation rule that the Gateway owns. Local commands, such as `gateway:use`, `gateway:remove`, `dns:resolve` and `profile` with an explicit URL, work without the Gateway.
- Run no SSH, remote sudo, package manager, systemd or shell for a remote change. Privileged local changes stay in the visible `gateway:add`, `gateway:trust`, `dns:resolve` and `self-update` commands.
- Add no database, web route, queue, scheduler or frontend setup. Laravel Boost is a development dependency only; production commands must not depend on it.
- Each command's page and chart under `docs/cli/<family>/` own its contract. Read them before you change the command.

## Output

- Follow the [CLI design standard](../../docs/reference/cli-ux.md). Prove terminal behaviour with real PTY output ([verifying-cli-output](../../.agents/skills/verifying-cli-output/SKILL.md)).
- Keep the Gateway's request ID in human and JSON errors. A JSON error envelope always has `error.request_id`, `null` when there is none.
- Never echo secret-bearing input in an error or exception text. Only an explicit credential-delivery contract, such as Metrics credentials, renders its credential fields.

## Code and tests

- Start every PHP file with `declare(strict_types=1)`.
- From `apps/cli`, run the tests that cover the change: `vendor/bin/pest --compact tests/Feature/...Test.php`, or `--filter=<name>`. `composer test` runs the whole suite.
- Fake the SDK transport for a remote command and assert the exact request type and payload. Invalid local input sends no request and changes no local file. A command may send a read-only lookup first, such as the Node list; when the lookup fails, it sends no mutation. Use temporary directories for configuration and trust-store tests, and credential-shaped sentinels where output could leak a secret.
- `box.json` lays out the PHAR for `bin/orbit-build-cli-binary`; PHPacker stays in `phpacker/` so its Symfony 7 constraint stays out of the CLI lockfile.
