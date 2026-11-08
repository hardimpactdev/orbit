---
title: "Gateway response fixtures"
description: "Recorded Gateway responses are the contract that CLI tests replay, so a response change shows which commands it reaches before it ships."
covers:
  - packages/php-sdk/fixtures/**
  - apps/cli/tests/Helpers/GatewayFixtures.php
  - bin/api-fixtures
  - bin/cli-contract
  - apps/web/fixtures/**
---

# Gateway response fixtures

The `database-server-update` fixtures replay [`database:server:update --require-password`](/cli/database/server-update). The request uses PATCH and an explicit true field; its Operation fixture retains `database_server.close`, the persisted execution kind.

Node enrollment fixtures record the real HTTP 202 admission and a separate terminal Operation read. The CLI replays both, so a queued response cannot be mistaken for an active Node. Native identity observations supply the completion fixture.

The `node-show` and `project-show` fixtures hold the record, and for a Node its `access`. They carry no exclusion lists.

The `proof-run` fixtures record a result as rows of `command`, `file` (the cases file), `case`, `verdict` and `seconds`. A refusal for a disabled execution lists the selected cases files.

The web fleet includes a Swamp run for task group 12, with succeeded, running, and pending steps. It proves task-panel rendering and output expansion without an agent transcript. It does not prove live Swamp connectivity or credential grants. [Swamp run output](/reference/tasks#swamp-run-output) defines the live read.

The `websocket-apps` fixtures cover Reverb app create, list, show, and destroy, plus Instance add and remove. The CLI replays them in human and JSON modes. App fixtures carry the app ID and public key. Attachment fixtures carry key names only. Neither carries the secret. See [Reverb apps](/reference/websocket-apps) for the response contract.



The Tasks fixtures also cover [group assistance](/reference/tasks#group-assistance-after-all-children-end). `tasks/task-comment-create` records requests, resolutions, and open-child and closed-group refusals. `tasks/task-comment-list` records group-only history and an empty history. Successful comments carry the group ID in both ownership fields. The SDK checks typed responses, and the separate group CLI commands replay every case in human and JSON modes; these fixtures do not replace the child comment contracts.

This page tells a contributor how a Gateway response change reaches the CLI tests. A fixture is one recorded Gateway response under `packages/php-sdk/fixtures/<family>/<command>/<case>.json`. The Gateway test suite records it and the [API reference](/api/overview) validates it. The CLI test suite replays it through a Saloon mock and compares the complete command output with a stored expectation.

Repository URLs in fixtures name `hardimpactdev/orbit`. Recipe artifact pins keep their recorded URLs. Fleet and Node convergence fixtures include footprint digests bound to the delivered agent pin; re-record them when that identity changes. See [Node provisioning](/reference/node-provisioning) for the footprint rule.

Task group fixtures include nullable `vm_power`, separately from task status. CLI tests cover the **VM power** label for known states and omit the label and JSON field when power is null. The SDK accepts a missing power field and ignores unknown optional response fields, so a field rename does not make an older client reject the task group.

Tool scan and adoption fixtures distinguish formula and Vite+ global identities, registered and informational packages, dependencies, unsupported sources, and the time of the oldest fact. Each manager has its own scan state, `not_observed` included. Scan fixtures also record authorization and unmanaged-node refusals. Adoption fixtures record an adopted Tool with the agent's liveness, a manager the agent has not reported, constraint conflicts, invalid input, an invalid state, and authorization failure. The CLI replays those responses for `tool:adopt`.

Doctor fixtures record the `200` reply: the report the Gateway answers at once. It holds an informational-only healthy report or a mixed report with drift or unverifiable findings. Node fixtures include an enrolled Mac with empty roles. Web fixtures preserve registered Tools while a live inventory request fails.

Node key fixtures under `nodes/node-wireguard-key` record an updated Node, a Gateway-role refusal, and a sanitized hub projection failure. The `node:wireguard-key` CLI tests replay each response in human and JSON modes. Keep the projection failure's error code and step, but never record remote process output or private key material. [WireGuard key recovery](/cli/node/wireguard-key) owns the stored-key and identical-replay behavior; subprocess feature tests cover interrupted publication separately from response fixtures.

Task fixtures include a GitHub feedback fixup with `fixup_problem: review:{reviewer_id}` and an immutable findings packet in `brief`. Record that response from the Gateway fixture test with example identities and findings. Its `review-findings` deliverable uses the existing response schema. Replaying the fixture must preserve source provenance without interpreting review text as an action. The [Tasks contract](/reference/tasks#execution-ownership) owns the meaning of those fields.

Task response fixtures include a `topology` array on each subtask. Default cases record `[]`; the declared case records `app-dev`. Re-record all responses that contain subtasks when this field changes, regenerate OpenAPI and the web API types, and update the matching CLI expectations. Human subtask details show `Topology` only for a nonempty declaration; JSON keeps the array in both cases. [Compute drivers](/reference/compute-drivers) defines the accepted workload roles and readiness gate.

Task group fixtures include the `current_recipe` boolean and the `review` mode. Default cases record `false` and `manual`. When this field changes, re-record every response that contains a task group, regenerate OpenAPI and the web API types, and update the matching CLI JSON expectations. [Image fallback](/reference/compute-drivers#image-fallback) owns its meaning.

Process response fixtures include `restart_on_deploy`, which defaults to true and preserves an explicit false. They also include `user`, which is null when the Process uses its derived account. When an explicit account is selected, the response includes that name both in `user` and in `runtime_config.user`; see [Node accounts](/reference/processes-and-schedules#node-account).

Instance list and show fixtures include `routes`, every Route of the Instance, and each Route includes `web_root`.

Gateway fixtures record the [desired fleet state](/reference/self-update#desired-fleet-state) with an available, a fallback, and a pending CLI release, and `gateway:status` as an active peer sees it. The Gateway test serves the release-shaped GitHub responses under `apps/gateway/tests/Fixtures/GitHub/CliRelease`. The `self-update` contract downloads the stand-in binaries under `apps/cli/tests/Fixtures/SelfUpdate`, whose checksums those fixtures name.

An Agent pin change updates the Gateway and Fleet fixtures with the version, source commit, asset URLs, and verified checksums from its delivery manifest. Update the SDK checksum expectations, CLI download stand-ins, and matching CLI output together. The Agent release checksums remain separate from the local stand-in checksums used by `self-update` tests.

Gateway release fixtures under `gateway-releases` record one release record at each point the CLI can see it: queued, running, live, switched back, paused, and rolled back. `gateway:release:deploy` and `gateway:release:rollback` replay the queued response and then a sequence of record reads, so their expected output shows how the progress tree follows a release. The automatic release fixtures record the state disabled, enabled, paused after a failed migration, and resumed.

Project Document fixtures record a complete file lifecycle, reads of earlier versions, revision conflicts, and redacted storage status. The SDK and CLI replay the same envelopes, preserving IDs, revisions, checksums, cursors, and request IDs. Human show, list, and version-history expectations live under `apps/cli/tests/Expected/project-documents`; raw read and download tests check exact bytes separately.

Gateway rollback fixtures record a queued code switch with `force: false` and no force member in the rollback phase. The request is bodyless. The crossing-refusal fixture is removed because Laravel owns migration discovery; [Gateway recovery](/reference/gateway-recovery#laravel-owns-migrations) explains the decision.

Database list and show fixtures contain resource records. Managed database creation, user creation, server creation and server closing replay terminal native Operations recorded through `DatabaseNativeResponseFixturesTest`. The CLI tests use those terminal responses for the accepted start and final Operation read, in human and JSON modes. MySQL, PostgreSQL and Valkey request tests retain their driver options, worker counts, replication flags and password exclusion. Valkey closing also retains the refusal while a client still uses `default`.


## Record a fixture

A Gateway fixture test sends a request with deterministic data and calls `record_fixture()` from `apps/gateway/tests/Support/ResponseFixtures.php` with the fixture name, the SDK request class that sends the route, and the route.

```php
record_fixture($this->getJson('/api/v1/nodes')->assertOk(), 'nodes/node-list/default', ListNodesRequest::class, 'GET /api/v1/nodes');
```

A normal test run asserts that the response still equals the recorded file. A run with `ORBIT_FIXTURES=record` rewrites the file. Use the request id `fixture_request_id()` so recorded and expected output stay stable.

```bash
cd apps/gateway && ORBIT_FIXTURES=record vendor/bin/pest --filter=Fixtures
```

Instance fixtures include nullable `seed_path` and `seed_commit` fields. These name the default's checkout and its last deployed commit, or the seed selected for a new workspace. Re-record create, list, and show responses together, then update the CLI's JSON expectations; human tables keep their existing columns.

The recorded file holds the request class, the route, the status, and the body. It holds no secrets, because fixture tests use example values. Project fixtures include source defaults and task settings such as `task_check`, and the `maintenance` switch; update the corresponding CLI expectations when those response fields change. Task fixtures share one id sequence with their subtasks. A subtask id is greater than its task id, and creating another task does not reuse a subtask id.

The `tasks/task-show/watched` fixture records a running task whose branch pull request was opened outside Orbit. It has `watched_pr_url`, `watched_pr_number`, and `watched_pr_state`, while `pr_url` remains null. The default task fixtures keep the watched fields null. The SDK and CLI replay both cases so a watched pull request cannot become the reviewed pull request by accident. When these fields change, re-record every response that includes a task and update its CLI JSON expectation. The [branch watch](/reference/tasks#watch-the-branch-while-subtasks-are-open) owns their meaning.

The `tasks/task-question-close` fixtures record a question closed as `superseded` and the `tasks.question_closed` refusal of a second close with another status. The SDK and the CLI replay both.

The `tasks/task-agent-show` fixtures record four Gateway snapshots. `default` is a running thread with a saved transcript. `after-sequence` returns a complete snapshot even when a cursor was supplied. `pending` has no recorded transcript. `archived` is a saved transcript from an ended thread. Every cursor is null. `archived` means a stored transcript exists; later turns can replace it. The SDK and CLI replay all four. See [Read a thread](/reference/tasks#read-a-thread).

Node fixtures include `agent`, the [agent liveness](/reference/node-agent#liveness). `NodeResponseFixturesTest` seeds the agent-view cache, so the Gateway Node is offline since a fixed time and the app-dev Node is online. Re-record the node-list, node-show and node-add responses together when `agent` changes.

Connection fixtures include nullable `locale` and `lan_host` separately from the default WireGuard `host`. A null LAN host means LAN selection is unavailable. Record server and connection changes through `DatabaseServerResponseFixturesTest` and `GatewayResponseFixturesTest`, then replay changed fixtures with `bin/cli-contract --changed <base>`.

## Validate a fixture

`bin/api-fixtures --check` validates every fixture body against the response schema for its route and status in `docs/openapi.json`. The `apps/docs` checks run it, so a fixture whose body differs from the API reference fails continuous integration. Regenerate the reference with `composer docs-openapi` when a response shape changes on purpose. The inputs that command reads are on [API reference generation](/reference/api-reference).

## Replay a fixture in the CLI

A CLI contract test loads a fixture by name with `gateway_fixture_mock()` from `apps/cli/tests/Helpers/GatewayFixtures.php`, runs the command, and compares the output with `apps/cli/tests/Expected/<family>/<command>/<case>.human.txt` or `.json` through `expect_output()`.

```php
MockClient::global(gateway_fixture_mock('nodes/node-list/default'));
expect(Artisan::call('node:list'))->toBe(0);
expect_output(Artisan::output(), 'nodes/node-list/default.human.txt');
```

A normal run asserts the exact output. A run with `ORBIT_EXPECTED=update` rewrites the expected files after a reviewed change.

```bash
cd apps/cli && ORBIT_EXPECTED=update vendor/bin/pest --filter=Contract
```

## Watch a fixture in a terminal

A development checkout of the CLI can replay recorded responses under a real command, so you can watch the rendering that the contract tests hold. Set `ORBIT_DESIGN=1`, name the fixtures in `ORBIT_GATEWAY_FIXTURES`, and point `ORBIT_HOME` at an empty directory. The replay seeds a Gateway profile there. The CLI binary does not include the replay. `apps/cli/design/README.md` also covers the design sketches that `ORBIT_DESIGN=1` loads.

```bash
export ORBIT_DESIGN=1 ORBIT_HOME=/tmp/orbit-replay
ORBIT_GATEWAY_FIXTURES=projects/project-list/default apps/cli/orbit project:list
```

## Find the commands a change reaches

`bin/cli-contract` runs only the CLI tests that replay the named fixtures. With `--changed`, it takes the fixtures that differ from `origin/main`. With `--preview`, it renders every contract case into a temporary directory and lists the commands whose output differs from `tests/Expected`, with a diff per file. That list is the map of the commands a rendering change reaches. With `--coverage`, it lists the product commands that have no expected output yet.

```bash
bin/cli-contract nodes/node-add/created
bin/cli-contract --changed
bin/cli-contract --preview
bin/cli-contract --coverage
```

The change cycle is: change the Gateway, re-record the fixtures, review the fixture diff, run `bin/cli-contract --changed`, fix or accept each command's output, and update the expected files.

## Web app fixtures

The web app's demo mode and its tests run against the files under `apps/web/fixtures/fleet`. These files use the same format without a request class. They are written by hand as one coherent fleet, because a recorded fixture covers one route and the web app needs records that refer to each other. The Gateway Node runs a release, so it is the only Node with an `updating` value. `bin/api-fixtures --check` validates them together with the recorded fixtures. One file, `firewall-list.json`, answers `GET /api/v1/firewall-rules` for every Node; the demo Gateway derives the one-Node, `managed` and `live` forms from it.

The demo fleet has one tool inventory for each active Node. Node 1 is an empty Linux read: `brew` completed with no packages, `brew-cask` unsupported, and `vp` not observed. Node 2 is a partial Linux read: `brew` not observed, `brew-cask` unsupported, and `vp` not observed, so none of those empty arrays is an inventory. Node 3 is unreachable and has no inventory fixture; the demo refuses a scan with `tool.node_inactive`.

Node 4 is the macOS read: registered packages with a newer observed version, the `openssl@3` dependency, unsupported casks, a formula and a cask both named `visual-studio-code`, and `ghost`, which the demo lists as supported and then refuses on adopt. The demo adopt, update, and remove handlers change only that in-memory fleet.

`task-insights.json` holds 30 days of [task insights](/reference/tasks#task-insights) with a previous period and a breakdown by model, Project, and release. The demo answers every period and Project filter with it. The recorded agent thread fixtures carry `cost_usd`.

## Families with fixtures

The directories under `packages/php-sdk/fixtures` list the families that have recorded fixtures and contract tests. Add a family by recording from its Gateway tests with `record_fixture()` and writing its CLI contract test in the same change. Extension responses are replayed by the `extension:list`, `extension:enable`, and `extension:disable` command contracts, and the `extension:list` fixture names every extension the Gateway registers, from `tasks` to `conn`; do not leave a changed extension fixture without a CLI test that names it. `bin/cli-contract --coverage` shows the commands that still have no expected output.

Node update fixtures under `nodes/node-update` record an unmanaged peer set to `ipados` and the `node.platform_managed` refusal. The SDK and `node:update` CLI tests replay both responses.

Automatic Gateway release fixtures use the `gateway-auto-release-*` directories under `gateway-releases`, matching the public commands.
