# Dependency workflow: preparation and blocked task-VM adoption

Status: narrow consumer-safety preparation for independent review, 2026-10-25.
The parent accepted isolated docs-workflow adoption in merged PR10
`e6737edf55a98afeb32f3340cb4633f54657084b`. This author continued in the same
owned checkout. Dependency execution through Gateway task VMs is **not delivered**.
No package update, lockfile change, deployment or live task invocation occurred.

## What this slice does

Reuse the earlier [`scan → plan → assert` workflow](../workflows/workflow-dependency-upgrade.yaml)
and [`orbit-deps` planner](../extensions/behaviors/orbit_deps.ts) as evidence of
existing behavior. Correct its demonstrated registry failure: `{}` previously
became an empty release list and a successful `current` row. HTTP failures
became `unknown` rows but the direct `plan` method still succeeded. A caller
that omitted the workflow's registry assertion could mistake that for success.

The existing parser now validates the expected Packagist package releases and
npm package identity/version metadata. Missing packages, empty supported stable
release sets, malformed or internally inconsistent rows, missing tagged
releases, invalid versions, JSON/HTTP errors and transport failures produce
`unknown` diagnostic rows. Arbitrary text containing digits cannot be coerced
into a release. The complete diagnostic `upgrade-plan` is persisted through the
existing resource before the method fails. A healthy sibling package can still
be shown in that diagnostic; it cannot turn partial coverage into success.
There is no new resource, approval record, worker or scanner.

Valid no-change remains distinct: successful method, zero unknown rows and no
upgrade subtasks, with explicit `current` rows. Existing unsupported/path/dev
constraints are not queried and remain visibly `skipped`; zero subtasks with skipped rows is not a
claim that every package was assessed. Numeric Composer shorthand/four-part
normalization and the existing caret-major convention remain historical planner
policy, not a replacement for Composer's resolver. An unsupported metadata
format fails closed. These checks detect missing and inconsistent fields; they
cannot prove that a registry returned its entire historical catalog when a
self-consistent subset is indistinguishable from a full response.

The exact package/alias, manifest scope, declared constraint, locked version,
within-constraint maximum and proposed constraint change remain in the existing
plan. Major proposals remain proposals. No plan or workflow authorization here
grants a major upgrade. No current planner result is admitted as task-VM
execution input.

## Exact source and capability gap

Authoritative Orbit source, read-only git objects:
`0364765d6d41794f3f89d702e0f72cc54e954b07` (delivered ORB2074). No newer native
capability is assumed. Swamp base is PR10's merge above. Read current
[docs adoption](repository-analysis-adoption.md) for the already accepted
full-report, plan-consent and replay boundaries.

| Existing source | What it proves | Adoption consequence |
| --- | --- | --- |
| [`StartTaskAnalysisRequest:19–35`](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Http/Requests/Tasks/StartTaskAnalysisRequest.php#L19-L35) | Exact input is subtask/plan/digest/base/HEAD/tree; no command, selection or write flag | Do not invent dependency wire fields |
| [`bin/task-analyze:95–109`](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/bin/task-analyze#L95-L109) | Actual repository adapter emits `orbit.docs-impact.v1`; no registry inventory/proposal/apply output | A docs report is not dependency analysis |
| [`TaskAnalysisReport:19–53`](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Domain/Tasks/TaskAnalysisReport.php#L19-L53) | Gateway envelope permits a repository-owned report schema; transport is generic | A read-only combined report may reuse this API after repository/consumer contract review; a new scan API is not yet proved necessary |
| [`RemoteTaskCheckRunner:123–143`](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Infrastructure/Tasks/RemoteTaskCheckRunner.php#L123-L143) and [`fixed entrypoint check:380–397`](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/resources/tasks/check#L380-L397) | Analysis starts only the tracked fixed profile and records its workspace identity | No alternative dependency command through analysis |
| [`StartTaskCheckRequest:17–28`](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Http/Requests/Tasks/StartTaskCheckRequest.php#L17-L28), [`TaskCheckKind:7–14`](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Domain/Tasks/TaskCheckKind.php#L7-L14), [`baseline guard:45–56`](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Domain/Tasks/TaskCheckOperation.php#L45-L56) | Only baseline/handoff; baseline refuses after any group implementer starts | Do not repurpose check or baseline as update execution |
| [`Instance dependency contract:100–131`](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/docs/reference/instance-dependencies.md#L100-L131) and [`empty request:17–46`](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Http/Requests/Instances/InstanceDependenciesRequest.php#L17-L46) | Existing update is directory/root scoped, all declared packages within constraints; no selected package/plan/revision input; HTTP 200 is not success | It cannot apply an exact authorized task proposal |
| [`Instance access/update:61–84`](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Actions/Instances/Dependencies/AccessInstanceDependenciesAction.php#L61-L84), [`update locks:35–55`](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Actions/Instances/Dependencies/UpdateInstanceDependenciesAction.php#L35-L55), [`direct SSH:43–54`](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/apps/gateway/app/Actions/Instances/Dependencies/UpdateComposerDependenciesAction.php#L43-L54) | Uses Instance/environment and app-dev source locks and direct SSH, not a keyed task-group operation/TaskWorkspaceLease contract | An Instance ID or an idempotency header does not create task ownership or replay semantics |
| [`Operations task kinds:73–77`](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/docs/reference/operations.md#L73-L77) | Provision/check/analyze/turn/publish; no dependency-apply task operation | Do not dispatch an invented operation |

The proven missing **write primitive** is a fixed repository-owned dependency
apply under the existing task-group holder, serial workspace lease and terminal
supervision, bound to the exact selected proposal and input workspace revision.
An ordinary Instance update is not this contract, even if a caller can address
its Instance. Waiting, cancellation, unknown descendants and lost replies must
retain the same ownership and execution identity. No host-side command, agent
turn, local commit-trailer retry or private checkpoint can supply that proof.

The existing host planner is also not a native read-only producer. `scan`
fetches a branch into a scratch clone and reads committed manifests/locks with
`git show`, falling back from the development Instance inventory. Its commit
comparison cannot detect modified/untracked files in a task workspace. An
isolated test demonstrates two different workspace inputs behind one unchanged
commit. That legacy result must not be wrapped in invented native provenance.

## Smallest parent-owned native follow-up

This is a prerequisite brief, **not an approved wire schema** or runtime
implementation. The parent owns normal serial native design review and delivery.
Do not create a native group from this author thread.

1. First review a repository-owned read-only dependency report under the existing
   fixed analysis profile. Reuse existing inventory/constraint logic where sound;
   do not port host checkout fallback or copy its unsupported-format assumptions
   as native policy. Preserve the docs report and actual-file gate alongside the
   dependency evidence. Decide its report composition and complete bounded
   transport before implementation. Existing generic `tasks.analyze` transport
   may suffice; no new scanner endpoint or worker is prescribed.
2. Bind the dependency proposal to the provision pin, group/current child,
   cumulative authorized path-plan revision/digest, candidate HEAD/whole-worktree
   tree, exact manifest/lock/config content identities, registry observation,
   repository command identity, ecosystem/manager/subproject and exact package
   selections/constraints/targets. A changed manifest or lock invalidates the
   proposal even when HEAD is unchanged. Missing, malformed, partial or stale
   inputs are unavailable, never an empty successful plan.
3. Review the smallest fixed write effect using existing task operation/lease/
   journal facilities. Reuse existing package-manager actions only after proving
   task placement, exact selection and ownership fit. Repository commands choose
   package-manager flags and policy; Gateway must not know Orbit package rules.
   No caller shell, command string, arbitrary directory or write-generator lane.
   Preserve original key/request/execution on lost reply or restart; a changed
   proposal gets no mutation under the old key. Unknown termination stays fenced.
   Failure after writes retains the actual candidate and failed outcome, without
   blind retry, synthetic rollback or an approved receipt.
4. Use the existing official factory artifacts and latest-decision approval
   records. Any future exact dependency consent must include the full proposal
   and input revision; the current path-only `plan-v1` consent cannot silently
   authorize package targets. Bind major-upgrade consent to that same reviewed
   subject with the existing approval mechanism, not a second ledger. Replacement,
   rejection, later generic approval, cycle or era change must not revive consent.
5. Feed complete evidence to existing author/reviewer turns, then reuse native
   handoff/check outcomes. The cumulative path inventory covers actual manifest,
   lockfile, package-manager config, documentation and generated paths, including
   additions, deletions and both rename endpoints. Omitted actual work requires
   a revised plan; a package no-change verdict never approves semantic docs,
   waiver meaning or a review receipt.

Before a new write effect or admission is implemented, amend
[ADR0229](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/docs/decisions/0229-let-an-orchestrator-drive-task-work-through-gateway-operations.md#L149-L167)
and owning Tasks/Operations docs with the reviewed request/result/error,
idempotency and terminal-ownership contract. The present read-only amendment
cannot authorize dependency writes. Swamp runtime adoption follows delivered
public contracts; this PR changes no `orbit-groups` gate or driver behavior.

## Repository policy retained

[Orbit contributor policy:235–252](https://github.com/hardimpactdev/orbit/blob/0364765d6d41794f3f89d702e0f72cc54e954b07/docs/contributor-guide.md#L235-L252)
requires targeted updates, lockfile diff review, checks, release-age controls
(three days for manual Composer/Bun resolution), coupled Vite+/Vitest/Pi pins,
and explicit advisory evidence/exceptions. Dependabot has its separate
seven-day grouped policy. The old broad subproject `composer update` is not
policy parity with targeted `composer update vendor/package --with-dependencies`
or `bun update package`.

The old planner does not check release timestamps or all coupled pins. Its
translated Composer ranges and direct-only counts are advisory. The fixed
repository command and package manager must enforce authoritative constraints,
release age, lock format and transitive changes. Existing audit commands check
advisories; they are not a scan/plan/apply operation. Registry failure must not
be treated as an empty approved update, and workflow authorization never grants
an age exception, major upgrade or changed constraint.

## Acceptance and current evidence

| Requirement | Current proof / remaining boundary |
| --- | --- |
| Valid no-change versus registry failure; malformed/partial JSON, HTTP/transport fault, one healthy sibling | Real existing planner with controlled registry transport; three controls fail on exact base as intended |
| Exact selected package/alias/constraint/max target, outside-range and 0.x major classification | Existing planner/range helpers executed; advisory output only, no major consent or package mutation |
| Actual manifest/lock revisions and invalid JSON | Real legacy inventory in disposable Git fixtures; proves committed v1/v2 differ **and** uncommitted input is invisible to legacy commit identity |
| Workflow refuses registry failure before approval | Actual Swamp workflow/assert engine and actual planner; scan/registry are explicit fixture transports; no factory/driver or task-VM update acceptance claimed |
| Typed plan, immutable pin, complete docs report/provenance, current candidate and semantic/actual-path handoff guards | Accepted PR10 proof reused after byte-identity verification; no new execution of unchanged heavy factory suites |
| Native dependency report, input-bound package proposal and retained full result | Not delivered by current repository adapter; parent-owned prerequisite |
| Exact major consent, rejection/replacement/input-revision invalidation for dependency proposal | Not implemented; no package proposal is admitted by the task factory |
| Task-held update/check failure, original-key recovery, cancellation/competing-owner and terminal candidate | Not implemented/proved for dependency writes; requires fixed native write effect; docs-operation simulations cannot establish it |
| Manifest/lock/docs actual paths at dependency handoff | Existing docs gate remains intact; no dependency candidate was produced, so dependency end-to-end proof remains pending |
| Live workflow/interruption/lease release | Not proved; no live task API calls or experiments |

Focused validation uses already-installed tools/cache:

```sh
~/.swamp/deno/deno test --cached-only --allow-all tests/dependency_plan_test.ts
~/.swamp/deno/deno test --cached-only --allow-all tests/dependency_workflow_test.ts
```

The workflow fixture runs five cases: healthy no-change succeeds; malformed,
partial, unavailable and transport cases fail with retained unknown evidence.
Only the synthetic scan and registry responses are substituted. No LLM or
package-manager stage is present in this execution. Record fixture timings and
zero mechanical LLM calls without claiming native performance or savings;
there is no comparable baseline. Source, logs, negative controls, full responses
and model readback are retained at `/tmp/swamp-dependency-boundary-ej0ac1bz`.
The final run passed five tests in 12s, including five official-workflow cases.
Current workflow proof: `/tmp/swamp-dependency-policy-proof-fa5e3140915fffea`.
Planner step durations were 33 ms (no-change), 77 ms (malformed), 46 ms (partial),
51 ms (HTTP unavailable) and 37 ms (transport failure); full CLI run/query wall
durations are recorded separately. These are controlled fixture measurements,
not native registry/task timings. Four planner/inventory tests also passed after
the explicit skipped-constraint lookup guard; the final combined run includes
that guard. The exact final tests against base `e6737edf` produced three expected
failures and one unchanged inventory pass. Lint passed for three affected TS
files and the behavior type check passed. No timeout, assertion, call/tick limit
or skip was widened.

The complete historical 216325-byte / compact 162390-byte report and isolated
69452-byte reports remain unusable. No evidence is shortened, rewritten or
admitted here. Report 65536-byte and prompt 100000-character bounds are unchanged.
Accepted failed-author retry, exact latest consent, original same-send identity
and the disclosed absent-history fail-closed CEL diagnostic remain unchanged.
No new factory approval, scanner, worker or dependency approval ledger is added.

Parent owns fresh exact-head independent review, merge and native prerequisite
work. This preparation is not full dependency adoption acceptance. External
review-and-merge/topology admission, live workflow proof, interruption proof,
write generators and engine retirement remain closed or separate as before.
