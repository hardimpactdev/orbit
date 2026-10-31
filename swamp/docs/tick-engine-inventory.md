# Tick engine retirement inventory

Source: Orbit main `4c7f73a821ae6ced158bf17abbf9d8b88c34b7b7`, 2026-10-23.
Class names below are in `apps/gateway/app/Domain/Tasks` unless stated otherwise.
This is a responsibility inventory, not permission to delete these files.

The external path already skips lifecycle work in `TaskScheduler`. It still uses
several of the same classes for effects. In particular, `TaskProvisionOperation`
depends on `TaskClaimAdmission`, and `TaskCheckOperation` on `TaskSubtaskRunner`.
Removing either mixed class now would break the built operations.

## Lifecycle code that Swamp replaces

| Current class / entry point | Replacement | Delete boundary and prerequisite |
| --- | --- | --- |
| `TaskScheduler::tickWithWorkspaceLeases`, lifecycle facade methods | Factory status, one guarded stage, artifacts/evidence, transition | Remove claiming, advancement, rubric, consult, review and settle dispatch after all modes have parity and the ADR kill proof passes. Keep maintenance separately |
| `TaskClaimAdmission::claimNext`, reservation/start/retry policy; scheduler `claimAvailable` | Explicit `tasks.provision` stage and Swamp retry/assistance policy | Delete automatic `todo` claiming and start-first-child callbacks; retain provisioning effects, capacity admission and operation-owned reservation |
| `TaskSubtaskRunner::tickSubtasks`, `advance`, `startFirstTask`, `beginRunningTask`, implementer/handoff/baseline progression | Implement → check → review stages and board status writes | Delete automatic actor selection, progression and reminders. Extract shared baseline setup, check-reading persistence and workspace preparation before deleting the class |
| `TaskConsultation::remindOrAssist`, `beginConsult`, `handleConsult`, direction relay progression | Declared consult/relay stages, cap 2, failed-handoff streak 3, human gates | Move counters and next-turn decisions to Swamp. Preserve receipt/question records, thread-role constraints and no-second-sender rules |
| `TaskRubricReminder`, tick-only rubric/observation decision glue (`TaskSessionDecision`, `TaskSessionNextAction`) | Structured failed evidence and stage prompt/gate data | Candidate deletion after all callers move. Fixed turn safety instructions and deliverable validation remain Gateway code |
| `TaskSessionActor::execute`, relay/reminder sends; `TaskSessionObserver`'s phase/acting-thread selection | `tasks.turn` plus explicit orchestrator intent | Remove the alternative sender and tick-only observation policy. Keep driver observation, final receipts, metrics and transcript archival; do not delete shared observation DTOs without a caller audit |
| `TaskReviewOutcome`'s outcome branching, relays, retry/reminder and final-review start/fixup decisions | Review findings gate, rework stage, final-review stage and capped fixup loop | Split exact-tree validation, review packet/snapshot and evidence handling out first. No deletion while review-and-merge is unsupported |
| `TaskPublication`'s publication backoff and scheduler callbacks; `TaskHandoffCi`'s reminder/retry decisions | Publish/handoff-CI stages, keyed attempts and human retry gates | Retain mirror import, non-forced push, PR lookup/update, check-run reads, brief coverage and exact-commit rules as operations |
| `TaskFinalReview` append/window policy via `TaskReviewOutcome`; `TaskPublication::mergeWhenReady` lifecycle callbacks | Final-review/fixup stages, review-record effect and merge request | Retain reviewed-commit ledger, exact-head authorization, all merge predicates and GitHub App write effect. Final review is not equivalent to ordinary subtask review |
| `TaskSettling::tickPullRequest`, `healOpenPullRequest`, `nextFixup`, caps, resume/settle decisions | PR observation → bounded fixup or assistance → publish/merge stages | Retain factual PR reads and cleanup. Cover conflicts, CI, trusted feedback and incoming PRs before deleting automatic fixup logic |
| `TaskSettlingFixup`, `TaskGitHubReviewConsumption`, `TaskGitHubReviewFeedback` | Factory fixup identities/windows and consumed-source evidence | Move policy only. Retain GitHub review identity/trust parsing and any stored evidence needed to prevent duplicate work |
| `Actions/Tasks/StoreTaskCommentAction` resolution delivery, `ResumeDeliverableCorrectionAction`, `RetryTaskBaselineAction` tick retry dispatch | Resolution is recorded input; Swamp explicitly requests a new operation | Delete implicit turn/check sends and pending-delivery machinery after callers migrate. Keep comments, questions, assistance updates, Activity and broadcasts |
| `Actions/Tasks/WatchIncomingPullRequestsAction` admission/scheduling | One explicit owner creates review work from bounded eligible-PR observations | Retain author/repository/base/draft safety and dedup identity. Must decide incoming-work handoff before retiring this tick path |
| `TaskOrchestration` and `TaskGroupGuard::requireTick`, mode branches | One external lifecycle contract after migration | Remove only in ADR step 3. Until then modes are immutable; no in-place transfer of a live group |

The local `orbit-subtasks` spike expresses consult cap 2 and additive cycle
overrides. The live `orbit-groups` definition currently parks blocked/no-receipt
turns at human retry gates; it has **no automatic consult stage**. Its per-child
implement/review and failed-handoff counters are implemented, but this is not full
consult/direction parity with the tick. Add the missing ordinary stages using
`tasks.turn` role `consult` and recorded resolutions before claiming full engine
replacement. Do not infer live consult proof from the scratch factory.

## Effects and safety that Orbit retains

| Responsibility | Existing code to retain / extract | Why it stays |
| --- | --- | --- |
| Operation admission, deduplication and target ownership | `TaskOperations`, four `Task*Operation` classes, `Actions/Tasks/StartTask*Action`; shared `Domain/Operations` engine | Keys, target-busy refusal, waiting jobs, conditional claims, cancel intent and recovery protect effects even if Swamp dies |
| Physical workspace ownership | `TaskExecutionLock`, `TaskExecutionHold`, `TaskWorkspaceLease`, `LeasedAgentDriver` | A stage record is not proof that a remote process stopped. Checks, turns, publish and teardown must remain mutually exclusive |
| Ownership recovery and attribution | `TaskWorkspaceLeaseRecovery`, `TaskWorkspaceOperationRecovery`, workspace snapshots/state readers, remote operation journal | Prove owner death and reconcile terminal evidence. Preserve uncertain holders; no timeout-based release or competing sender |
| Workspace and VM effects | Provisioning part of `TaskClaimAdmission`, `InstanceProvisioning`, `TaskWorkspaceLifecycle`, routes/MCP/signing; `Infrastructure/TaskVms/TaskVmWorkspace` | Orbit sizes/reserves/resumes/provisions one VM and creates the workspace. Swamp does not manage Incus, UpCloud or Node credentials |
| Detached checks, commit and cancellation | `TaskCheckRunner`, `TaskCheckProcess`, `TaskCheckOperation`, `StartTaskCheckAction`, `CancelTaskCheckAction`, check output and deliverable verifiers | A failed check is successful execution with failed outcome. Lost/unreachable work stays fenced until terminal proof; a pass commits the exact checked tree |
| Pi turn dispatch and receipts | `TaskTurnOperation`, `AgentDriverRegistry`, `TaskTurnReceipts`, `TaskTurnInstructions`, `TaskAgentSpawner`, `TaskReviewReset`, Pi driver | Gateway is the only sender for the holding operation. Pi Durable owns same-turn replay/provider recovery; group key passes through as send/request key |
| Review and publication safety | `TaskReviewDiff`, `TaskReviewBase`, review packet builders, `TaskFinalReview` ledger helpers, `TaskPublication` predicates, publisher/updater/merger interfaces | Preserve reviewed tree/commit, brief coverage, App isolation, no forced/default-branch push, exact-head CI and trusted-review gate. Swamp chooses when; it cannot declare an arbitrary SHA safe |
| Topology preparation and evidence | `TaskTopology`, `TaskTopologyAdmission`, `TaskTopologyEvidence`; `Infrastructure/TaskVms/TaskVmTopologyAdmission`, `TaskVmTopologyUnit`, `TaskVmTopologyEvidenceReader` | Nested guests, seed sizing, boot-aware preparation, process supervision, per-node proof and teardown are Gateway/VM effects |
| Visible record and authorization | `Task`, `TaskComment`, `TaskQuestion`, `AgentThread`, `TaskCheck`, `TaskReviewedCommit`; `TaskAssistance`, `TaskQuestions`, broadcasts, metrics, guards/status moves and API access | Board history, operator answers, safe state transitions, transcripts and access are product behavior, independent of the orchestrator |
| Factual GitHub observations | `TaskSettling::observeExternalPullRequest`, PR watcher/check/review interfaces, `TaskReviewSelection`/`TaskReviewTrust` | Reads can remain bounded/cached in Orbit. They report facts and completeness; they do not silently schedule external work |
| Resource maintenance and cleanup | Cleanup part of `TaskSettling`, `RemoveTaskWorkspaceAction`, `ArchiveAgentTranscriptsAction`, `CancelTaskGroupAction`, `CompleteTaskGroupAction`; `Actions/TaskVms/DestroyEndedTaskVmsAction`, `ParkIdleTaskVmsAction` | Stop before teardown, preserve reviewed work as policy allows, archive before destroy, retry removal and keep removal-failure assistance. Non-terminal operations keep VMs awake |
| Pending-thread cleanup and maintenance clock | `PrunePendingTaskThreads`, `TaskTickClock`, extension enable/access state | Keep needed record hygiene and observable maintenance health; audit usage after the final sender migration |

`Console/Commands/TickTaskSessionsCommand` currently combines lifecycle calls with
maintenance. Retain the command/schedule as ADR 0229 allows, but reduce it to idle
parking, PR merged/closed observation, transcript/archive/ended-workspace cleanup,
and VM removal. Split the corresponding calls out of `TaskScheduler` and
`TaskSettling`. Do not remove stale-reservation safety needed by operation-owned
claims; external reservations already bypass the tick's reclamation rule.

Provider retry/restart-resume code is not a future Swamp deletion win: ADR 0229
step 2 / Orbit #1464 already moved it to Durable. Avoid recreating that logic in
the driver, comments, or a maintenance tick.

## Deletion acceptance and rollback

For each eventual deletion PR, enumerate the callers and persistent fields it
removes. Assert that every former sender/check/claim entry point either uses the
holding operation or is absent. Keep effect, recovery, stop and cleanup checks
covering the retained code. ADR 0229's live worker/orchestrator interruption proof
and amendments are prerequisites, not optional follow-up tests.

Before retirement, rollback means disabling **new admission** to a newly supported
external mode and reverting its driver change. Existing groups finish/cancel with
their original owner. After removing the mode, there is no safe switch to a second
sender: restore the prior reviewed release only with external work quiesced and
ownership reconciled. Never delete keyed operations or safety records as rollback.
