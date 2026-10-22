<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Tasks\ArchiveAgentTranscriptsAction;
use App\Actions\Tasks\CancelRunningSubtaskAction;
use App\Actions\Tasks\CancelTaskCheckAction;
use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\CloseTaskQuestionAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\CreateTaskAction;
use App\Actions\Tasks\CreateTaskGroupAction;
use App\Actions\Tasks\DestroyTaskAction;
use App\Actions\Tasks\ListAgentWorkspacesAction;
use App\Actions\Tasks\ListTaskGroupsAction;
use App\Actions\Tasks\ListTaskQuestionsAction;
use App\Actions\Tasks\ReadAgentThreadAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\Tasks\RequestEndedPullRequestAssistanceAction;
use App\Actions\Tasks\RequireTasksExtensionAction;
use App\Actions\Tasks\ResumeDeliverableCorrectionAction;
use App\Actions\Tasks\RetryTaskBaselineAction;
use App\Actions\Tasks\ShowAgentThreadsAction;
use App\Actions\Tasks\ShowTaskGroupAction;
use App\Actions\Tasks\ShowTasksStatusAction;
use App\Actions\Tasks\StopTaskSubtaskAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Actions\Tasks\StreamAgentThreadAction;
use App\Actions\Tasks\UpdateTaskAction;
use App\Actions\Tasks\UpdateTaskGroupAction;
use App\Actions\Tasks\WatchIncomingPullRequestsAction;
use App\Actions\Tasks\WatchTaskBranchPullRequestAction;
use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Actions\TaskVms\DeleteTaskVmAction;
use App\Actions\TaskVms\DestroyEndedTaskVmsAction;
use App\Actions\TaskVms\FailTaskVmAction;
use App\Actions\TaskVms\FillPackageCacheAction;
use App\Actions\TaskVms\ForgetTaskVmWorkspacesAction;
use App\Actions\TaskVms\ObservePackageCacheAction;
use App\Actions\TaskVms\ParkIdleTaskVmsAction;
use App\Actions\TaskVms\PrepareTaskVmRuntimeAction;
use App\Actions\TaskVms\ReadPackageCacheUsageAction;
use App\Actions\TaskVms\RecordTaskVmAction;
use App\Actions\TaskVms\WakeTaskVmAction;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentInputRequest;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadEvent;
use App\Domain\Tasks\AgentThreadObserver;
use App\Domain\Tasks\DeliverablePathChecker;
use App\Domain\Tasks\InstanceProvisionFailure;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\LeasedAgentDriver;
use App\Domain\Tasks\LocalTaskSettleMetricsCollector;
use App\Domain\Tasks\PrunePendingTaskThreads;
use App\Domain\Tasks\TaskableType;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskBroadcastObserver;
use App\Domain\Tasks\TaskBroadcasts;
use App\Domain\Tasks\TaskCheckOutputBuilder;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskClaimAdmission;
use App\Domain\Tasks\TaskConsultation;
use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskDeliverableEvidence;
use App\Domain\Tasks\TaskDeliverableVerifier;
use App\Domain\Tasks\TaskExecutionHold;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskFinalReview;
use App\Domain\Tasks\TaskGitHubReviewConsumption;
use App\Domain\Tasks\TaskGitHubReviewFeedback;
use App\Domain\Tasks\TaskGitHubReviewObservations;
use App\Domain\Tasks\TaskGroupGuard;
use App\Domain\Tasks\TaskGroupMetricsRefresher;
use App\Domain\Tasks\TaskHandoffCi;
use App\Domain\Tasks\TaskHierarchyException;
use App\Domain\Tasks\TaskLevelStatusCast;
use App\Domain\Tasks\TaskObservationAvailability;
use App\Domain\Tasks\TaskPositions;
use App\Domain\Tasks\TaskPromptRenderer;
use App\Domain\Tasks\TaskPublication;
use App\Domain\Tasks\TaskPullRequestCheck;
use App\Domain\Tasks\TaskPullRequestDescription;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestHealth;
use App\Domain\Tasks\TaskQuestions;
use App\Domain\Tasks\TaskRemoteBranch;
use App\Domain\Tasks\TaskReviewBase;
use App\Domain\Tasks\TaskReviewCandidate;
use App\Domain\Tasks\TaskReviewContext;
use App\Domain\Tasks\TaskReviewFindingsPacket;
use App\Domain\Tasks\TaskReviewOutcome;
use App\Domain\Tasks\TaskReviewPacket;
use App\Domain\Tasks\TaskReviewPacketBuilder;
use App\Domain\Tasks\TaskReviewRequestLogins;
use App\Domain\Tasks\TaskReviewSelection;
use App\Domain\Tasks\TaskReviewTrust;
use App\Domain\Tasks\TaskRubricReminder;
use App\Domain\Tasks\TaskSchedule;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSequenceException;
use App\Domain\Tasks\TaskSessionActor;
use App\Domain\Tasks\TaskSessionDecision;
use App\Domain\Tasks\TaskSessionObservation;
use App\Domain\Tasks\TaskSessionObserver;
use App\Domain\Tasks\TaskSettling;
use App\Domain\Tasks\TaskSettlingFixup;
use App\Domain\Tasks\TaskSetupFailure;
use App\Domain\Tasks\TaskSubtaskRunner;
use App\Domain\Tasks\TaskThreadObservation;
use App\Domain\Tasks\TaskTickClock;
use App\Domain\Tasks\TaskTopology;
use App\Domain\Tasks\TaskTopologyEvidence;
use App\Domain\Tasks\TaskTopologyFailedException;
use App\Domain\Tasks\TaskTurnFetcher;
use App\Domain\Tasks\TaskTurnFetchNotice;
use App\Domain\Tasks\TaskTurnInstructions;
use App\Domain\Tasks\TaskTurnMode;
use App\Domain\Tasks\TaskTurnPullRequest;
use App\Domain\Tasks\TaskTurnReceipt;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceLeaseRecovery;
use App\Domain\Tasks\TaskWorkspaceLifecycle;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Domain\Tasks\TaskWorkspaceSummary;
use App\Jobs\TaskVms\DestroyTaskVm;
use App\Jobs\TaskVms\EnrollTaskVm;
use App\Jobs\TaskVms\FillTaskVmPackageCache;
use App\Jobs\TaskVms\ImageBuild\BuildRecipeImageStep;
use App\Jobs\TaskVms\ImageBuild\PromoteImage;
use App\Jobs\TaskVms\ImageBuild\PruneRecipeImages;
use App\Jobs\TaskVms\ParkTaskVm;
use App\Jobs\TaskVms\PrepareTaskVmRuntime;
use App\Jobs\TaskVms\ProvisionsTaskVm;
use App\Jobs\TaskVms\ProvisionTaskVm;
use App\Jobs\TaskVms\PublishTaskVmCommit;
use App\Jobs\TaskVms\RefreshTaskVmBaseImages;
use App\Jobs\TaskVms\RefreshUpCloudTaskVmBaseImage;
use App\Jobs\TaskVms\ResumeTaskVm;
use App\Jobs\TaskVms\SweepTaskVmHosts;
use App\Jobs\TaskVms\TaskVmRowJob;

/**
 * Reviewed public Tasks surface. Every public method is discovered independently of its signature and
 * these lists, so a new wrapper around an existing private effect needs a classification as well.
 * Refuses contains engine entry points; Skips contains the global admission sweeps.
 * Create/update and conditional comment sends have API coverage in TaskExternalOrchestrationTest.
 */
final class TaskOrchestrationEntryPoints
{
    /** @var array<class-string, list<string>> */
    public const array Refuses = [
        TaskHandoffCi::class => ['test'],
        TaskAgentSpawner::class => ['spawnReviewer', 'consult', 'relay', 'reserveReviewer', 'reserveImplementer', 'spawnImplementer', 'requestReview'],
        TaskConsultation::class => ['remindOrAssist', 'recordCommunicationFailure', 'beginConsult', 'handleConsult', 'beginDirectionRelay', 'handleDirectionRelay', 'replayHeldDirectionReview', 'requestAssistance', 'retryProviderFailure', 'resumePiServerRestart'],
        TaskPublication::class => ['publishApprovedCommit', 'mergeWhenReady', 'checkReturningPullRequest'],
        TaskReviewOutcome::class => ['handleReviewerOutcome', 'retryCommittedApproval', 'replaceFinalReviewWithFixup', 'stopAtFinalReviewCap', 'beginFinalReview', 'startFinalReview', 'nudgeReviewer', 'settleImplementer', 'acceptReview'],
        TaskSessionActor::class => ['execute', 'relayAnswer', 'relayReviewBody', 'remindRubric', 'resumeInterruptedTurn'],
        TaskSettling::class => ['tickPullRequest', 'cancelRunningSubtask', 'settle', 'queueBaselineRetryOnGreenTip', 'resumeStrandedSubtask', 'resumeWaitingSubtask'],
        TaskSubtaskRunner::class => ['handleImplementerCompletion', 'prepareTurn', 'startTask', 'startFirstTask', 'markRunning', 'beginRunningTask', 'assignImplementer', 'tickSubtasks'],
        CancelRunningSubtaskAction::class => ['execute'],
        RequestEndedPullRequestAssistanceAction::class => ['execute'],
        ResumeDeliverableCorrectionAction::class => ['reserve', 'execute'],
        RetryTaskBaselineAction::class => ['queue', 'recover'],
        StoreTaskCommentAction::class => ['execute', 'retryPendingResolution'],
        TaskScheduler::class => ['handleReviewerOutcome', 'retryCommittedApproval', 'publishApprovedCommit', 'replaceFinalReviewWithFixup', 'stopAtFinalReviewCap', 'beginFinalReview', 'startFinalReview', 'mergeWhenReady', 'checkReturningPullRequest', 'remindOrAssist', 'recordCommunicationFailure', 'beginConsult', 'handleConsult', 'beginDirectionRelay', 'handleDirectionRelay', 'replayHeldDirectionReview', 'requestAssistance', 'retryProviderFailure', 'resumePiServerRestart', 'nudgeReviewer', 'settleImplementer', 'startTask', 'cancelRunningSubtask', 'acceptReview', 'settle', 'queueBaselineRetryOnGreenTip', 'resumeStrandedSubtask', 'resumeWaitingSubtask'],
    ];

    /**
     * Each direct effect links to public admission cases executed by the inventory test.
     * Private helpers inherit the tested caller boundary: for example claimNext never enters endClaim for external work.
     * Adding an effect site requires a proof link or a named infrastructure boundary below.
     *
     * @var array<class-string, array<string, list<string>>>
     */
    public const array EffectProofs = [
        TaskAgentSpawner::class => [
            'sendReviewer' => [TaskAgentSpawner::class.'::consult', TaskAgentSpawner::class.'::relay'],
            'requestAdmittedReview' => [TaskAgentSpawner::class.'::requestReview'],
            'startAdmittedPending' => [TaskAgentSpawner::class.'::spawnImplementer', TaskAgentSpawner::class.'::spawnReviewer'],
        ],
        TaskClaimAdmission::class => [
            'claimNext' => [TaskClaimAdmission::class.'::claimNext'],
            'endClaim' => [TaskClaimAdmission::class.'::claimNext'],
            'startReserved' => [TaskClaimAdmission::class.'::claimNext'],
            'releaseStaleReservations' => [TaskClaimAdmission::class.'::releaseStaleReservations'],
        ],
        TaskReviewOutcome::class => [
            'handleReviewerOutcome' => [TaskReviewOutcome::class.'::handleReviewerOutcome'],
        ],
        TaskSessionActor::class => [
            'executeAdmitted' => [TaskSessionActor::class.'::execute'],
            'relayAnswer' => [TaskSessionActor::class.'::relayAnswer'],
            'relayReviewBody' => [TaskSessionActor::class.'::relayReviewBody'],
            'remindRubric' => [TaskSessionActor::class.'::remindRubric'],
            'resumeInterruptedTurn' => [TaskSessionActor::class.'::resumeInterruptedTurn'],
        ],
        TaskSubtaskRunner::class => [
            'handleImplementerCompletion' => [TaskSubtaskRunner::class.'::handleImplementerCompletion'],
            'checkHandoff' => [TaskSubtaskRunner::class.'::handleImplementerCompletion'],
            'startBaseline' => [TaskSubtaskRunner::class.'::beginRunningTask'],
        ],
        RequestEndedPullRequestAssistanceAction::class => [
            'notice' => [RequestEndedPullRequestAssistanceAction::class.'::execute'],
        ],
        ResumeDeliverableCorrectionAction::class => [
            'execute' => [ResumeDeliverableCorrectionAction::class.'::execute'],
        ],
        StoreTaskCommentAction::class => [
            'deliverFailureResolution' => [StoreTaskCommentAction::class.'::execute', StoreTaskCommentAction::class.'::retryPendingResolution'],
            'sendDirectionReview' => [StoreTaskCommentAction::class.'::execute'],
            'sendDirectionRelay' => [StoreTaskCommentAction::class.'::execute'],
        ],
    ];

    /** @var array<class-string, array<string, string>> */
    public const array EffectInfrastructure = [
        PrepareTaskVmRuntimeAction::class => ['execute' => 'Starts the VM step clock, not an agent turn, check, or claim.'],
        EnrollTaskVm::class => ['handle' => 'Starts the VM step clock during reusable VM enrollment; engine claim admission is guarded.'],
        ProvisionTaskVm::class => ['handle' => 'Starts the VM step clock during reusable VM provisioning; engine claim admission is guarded.'],
        ResumeTaskVm::class => ['handle' => 'Starts the VM provider during reusable VM resume; failures use the exercised FailTaskVmAction gate.'],
        LeasedAgentDriver::class => [
            'create' => 'Reusable agent driver; TaskAgentSpawner admission is exercised above.',
            'send' => 'Reusable agent driver; TaskSessionActor and TaskAgentSpawner admission is exercised above.',
            'respond' => 'Reusable agent driver; TaskSessionActor admission is exercised above.',
        ],
        TaskWorkspaceLease::class => [
            'startCheck' => 'Reusable check serialization; TaskSubtaskRunner and TaskHandoffCi admission is exercised.',
        ],
        TaskBroadcasts::class => ['flush' => 'Sends UI broadcasts, never an agent turn.'],
    ];

    /**
     * Public methods outside turn/check/claim admission. Every new public entry point must be classified,
     * even if it delegates its effect and therefore does not appear in the direct-effect scan.
     *
     * @var array<class-string, array<string, string>>
     */
    public const array OtherBoundaries = [
        AllocateTaskVmAction::class => [
            'execute' => 'Reusable VM placement; engine claim admission is guarded; does not change a group claim or send a turn.',
        ],
        DeleteTaskVmAction::class => [
            'execute' => 'Allowed VM cleanup; does not send a turn, start a check, or claim work.',
        ],
        DestroyEndedTaskVmsAction::class => [
            'execute' => 'Allowed ended-group VM cleanup; external cleanup requires no assistance.',
        ],
        FillPackageCacheAction::class => [
            'execute' => 'Fills host package caches without changing task work.',
        ],
        ForgetTaskVmWorkspacesAction::class => [
            'execute' => 'Forgets workspace records during VM cleanup without starting task work.',
        ],
        ObservePackageCacheAction::class => [
            'execute' => 'Observes repository package metadata without starting task work.',
        ],
        ParkIdleTaskVmsAction::class => [
            'execute' => 'Allowed idle VM parking; unfinished group operations block it.',
        ],
        PrepareTaskVmRuntimeAction::class => [
            'execute' => 'Reusable VM runtime preparation; engine claim admission is guarded.',
        ],
        ReadPackageCacheUsageAction::class => [
            'execute' => 'Reads package cache usage.',
        ],
        RecordTaskVmAction::class => [
            'execute' => 'Reusable VM row and credential creation; engine claim admission is guarded.',
            'piToken' => 'Reusable VM row and credential creation; engine claim admission is guarded.',
        ],
        WakeTaskVmAction::class => [
            'forGroup' => 'Reusable VM wake infrastructure; engine work admission is guarded and resume failures use FailTaskVmAction.',
            'forInstance' => 'Reusable VM wake infrastructure; engine work admission is guarded and resume failures use FailTaskVmAction.',
            'forVm' => 'Reusable VM wake infrastructure; engine work admission is guarded and resume failures use FailTaskVmAction.',
        ],
        BuildRecipeImageStep::class => [
            'step' => 'Describes a base-image build step without task work.',
            'program' => 'Describes a base-image build step without task work.',
        ],
        PromoteImage::class => [
            'step' => 'Describes a base-image build step without task work.',
        ],
        PruneRecipeImages::class => [
            'step' => 'Describes a base-image build step without task work.',
        ],
        DestroyTaskVm::class => [
            'handle' => 'Allowed ended-group VM cleanup; assistance writes are gated for tick ownership and tested in TaskVmLifecycleTest.',
            'uniqueId' => 'Allowed ended-group VM cleanup; assistance writes are gated for tick ownership and tested in TaskVmLifecycleTest.',
        ],
        EnrollTaskVm::class => [
            'handle' => 'Reusable VM enrollment; failure delegates to the exercised FailTaskVmAction gate.',
            'failed' => 'Reusable VM enrollment; failure delegates to the exercised FailTaskVmAction gate.',
            'uniqueId' => 'Reusable VM enrollment; failure delegates to the exercised FailTaskVmAction gate.',
        ],
        FillTaskVmPackageCache::class => [
            'uniqueId' => 'Host package cache maintenance without task work.',
            'middleware' => 'Host package cache maintenance without task work.',
            'handle' => 'Host package cache maintenance without task work.',
        ],
        ParkTaskVm::class => [
            'handle' => 'Allowed idle VM parking; errors stay on the VM without group assistance.',
            'failed' => 'Allowed idle VM parking; errors stay on the VM without group assistance.',
            'uniqueId' => 'Allowed idle VM parking; errors stay on the VM without group assistance.',
        ],
        PrepareTaskVmRuntime::class => [
            'enqueue' => 'Reusable VM preparation; failure delegates to the exercised FailTaskVmAction gate.',
            'handle' => 'Reusable VM preparation; failure delegates to the exercised FailTaskVmAction gate.',
            'failed' => 'Reusable VM preparation; failure delegates to the exercised FailTaskVmAction gate.',
            'uniqueId' => 'Reusable VM preparation; failure delegates to the exercised FailTaskVmAction gate.',
        ],
        ProvisionTaskVm::class => [
            'handle' => 'Reusable VM provisioning; failure delegates to the exercised FailTaskVmAction gate.',
            'failed' => 'Reusable VM provisioning; failure delegates to the exercised FailTaskVmAction gate.',
            'uniqueId' => 'Reusable VM provisioning; failure delegates to the exercised FailTaskVmAction gate.',
        ],
        ProvisionsTaskVm::class => [
            'failed' => 'Shared VM provisioning failure callback uses the exercised FailTaskVmAction gate.',
            'uniqueId' => 'Shared VM provisioning failure callback uses the exercised FailTaskVmAction gate.',
        ],
        PublishTaskVmCommit::class => [
            'handle' => 'Reusable queued commit push or failure observation; engine publication admission is guarded.',
            'failed' => 'Reusable queued commit push or failure observation; engine publication admission is guarded.',
        ],
        RefreshTaskVmBaseImages::class => [
            'handle' => 'Base-image maintenance without task work.',
        ],
        RefreshUpCloudTaskVmBaseImage::class => [
            'uniqueId' => 'Base-image maintenance without task work.',
            'handle' => 'Base-image maintenance without task work.',
        ],
        ResumeTaskVm::class => [
            'retryUntil' => 'Reusable VM resume; failure delegates to the exercised FailTaskVmAction gate.',
            'handle' => 'Reusable VM resume; failure delegates to the exercised FailTaskVmAction gate.',
            'failed' => 'Reusable VM resume; failure delegates to the exercised FailTaskVmAction gate.',
            'uniqueId' => 'Reusable VM resume; failure delegates to the exercised FailTaskVmAction gate.',
        ],
        SweepTaskVmHosts::class => [
            'handle' => 'Observes missing VMs; failure delegates to the exercised FailTaskVmAction gate.',
        ],
        TaskVmRowJob::class => [
            'uniqueId' => 'Identifies the queued VM row without changing task work.',
        ],

        RequestEndedPullRequestAssistanceAction::class => [
            'isReason' => 'Recognizes an existing ended-PR assistance reason.',
        ],
        TaskableType::class => [
            'allows' => 'Validates an attached workspace type.',
        ],
        TaskWorkspaceSummary::class => [
            'apply' => 'Records settle metrics without driving work.',
        ],
        TaskWorkspaceName::class => [
            'for' => 'Builds a workspace name.',
        ],
        TaskWorkspaceLifecycle::class => [
            'settledState' => 'Maps a settled Instance state.',
        ],
        TaskWorkspaceLeaseRecovery::class => [
            'release' => 'Releases workspace serialization after recovery; this is not a group provisioning claim.',
        ],
        TaskTurnMode::class => [
            'forTask' => 'Builds turn mode data without starting a turn.',
        ],
        TaskTurnInstructions::class => [
            'implementer' => 'Formats turn instructions without sending them.',
            'reviewer' => 'Formats turn instructions without sending them.',
            'consult' => 'Formats turn instructions without sending them.',
            'relay' => 'Formats turn instructions without sending them.',
            'deliverables' => 'Formats turn instructions without sending them.',
            'groupStart' => 'Formats turn instructions without sending them.',
            'topology' => 'Formats turn instructions without sending them.',
        ],
        TaskTopologyFailedException::class => [
            'caused' => 'Recognizes an existing topology failure.',
        ],
        TaskTopologyEvidence::class => [
            'none' => 'Parses or formats topology evidence without executing it.',
            'fromJsonLines' => 'Parses or formats topology evidence without executing it.',
            'missing' => 'Parses or formats topology evidence without executing it.',
            'summary' => 'Parses or formats topology evidence without executing it.',
            'reminder' => 'Parses or formats topology evidence without executing it.',
        ],
        TaskSetupFailure::class => [
            'rateLimited' => 'Classifies an existing setup failure.',
        ],
        TaskSessionDecision::class => [
            'escalate' => 'Builds a session decision without executing it.',
        ],
        TaskSequenceException::class => [
            'siblingRunning' => 'Builds a sequence refusal.',
            'notNext' => 'Builds a sequence refusal.',
        ],
        TaskSchedule::class => [
            'register' => 'Registers the global schedule without running it.',
        ],
        TaskRubricReminder::class => [
            'compose' => 'Formats a reminder without sending it.',
        ],
        TaskReviewTrust::class => [
            'fromConfig' => 'Builds the review author policy.',
        ],
        TaskReviewSelection::class => [
            'select' => 'Selects recorded GitHub reviews.',
        ],
        TaskReviewRequestLogins::class => [
            'parseEnv' => 'Parses review-request logins.',
            'withoutAuthor' => 'Parses review-request logins.',
        ],
        TaskReviewFindingsPacket::class => [
            'fromCandidate' => 'Builds a findings packet without sending it.',
        ],
        TaskReviewBase::class => [
            'commit' => 'Reads the review base and prior findings.',
            'previousReview' => 'Reads the review base and prior findings.',
            'reviewBase' => 'Reads the review base and prior findings.',
            'findings' => 'Reads the review base and prior findings.',
            'groupStartCommit' => 'Reads the review base and prior findings.',
        ],
        TaskRemoteBranch::class => [
            'for' => 'Builds a remote branch name.',
            'handoff' => 'Builds a remote branch name.',
        ],
        TaskQuestions::class => [
            'recordBlocked' => 'Reads or records question state without sending a turn.',
            'recordOperator' => 'Reads or records question state without sending a turn.',
            'openConsult' => 'Reads or records question state without sending a turn.',
            'escalateOpen' => 'Reads or records question state without sending a turn.',
            'answerConsult' => 'Reads or records question state without sending a turn.',
            'consultCount' => 'Reads or records question state without sending a turn.',
            'earlierAnswers' => 'Reads or records question state without sending a turn.',
            'attachResolution' => 'Reads or records question state without sending a turn.',
            'close' => 'Reads or records question state without sending a turn.',
            'settle' => 'Reads or records question state without sending a turn.',
            'awaitsCause' => 'Reads or records question state without sending a turn.',
            'answerPending' => 'Reads or records question state without sending a turn.',
        ],
        TaskPullRequestException::class => [
            'pending' => 'Builds a pending-push exception.',
        ],
        TaskPullRequestDescription::class => [
            'render' => 'Renders a PR description.',
        ],
        TaskPromptRenderer::class => [
            'implementer' => 'Renders an implementer prompt without sending it.',
        ],
        TaskPositions::class => [
            'assign' => 'Assigns subtask ordering without claiming or sending.',
        ],
        TaskLevelStatusCast::class => [
            'get' => 'Converts a persisted status without changing a claim.',
            'set' => 'Converts a persisted status without changing a claim.',
        ],
        TaskHierarchyException::class => [
            'column' => 'Builds a hierarchy error.',
            'nested' => 'Builds a hierarchy error.',
        ],
        TaskGroupGuard::class => [
            'requireTick' => 'Builds or evaluates a policy gate without driving work.',
            'supportOrchestration' => 'Builds or evaluates a policy gate without driving work.',
            'noSubtasks' => 'Builds or evaluates a policy gate without driving work.',
            'deliverablesMissing' => 'Builds or evaluates a policy gate without driving work.',
            'deliverablesRequired' => 'Builds or evaluates a policy gate without driving work.',
            'deliverableGateBlocked' => 'Builds or evaluates a policy gate without driving work.',
            'deliverableCorrectionCheck' => 'Builds or evaluates a policy gate without driving work.',
            'deliverableCorrectionRecorded' => 'Builds or evaluates a policy gate without driving work.',
            'deliverablesLocked' => 'Builds or evaluates a policy gate without driving work.',
            'notInBacklog' => 'Builds or evaluates a policy gate without driving work.',
            'groupClosed' => 'Builds or evaluates a policy gate without driving work.',
            'alreadyClaimed' => 'Builds or evaluates a policy gate without driving work.',
        ],
        TaskFinalReview::class => [
            'isUnreviewedHeadReason' => 'Reads or records review and removal-push metadata; never sends a turn, starts a check, or changes a claim.',
            'isResumableReason' => 'Reads or records review and removal-push metadata; never sends a turn, starts a check, or changes a claim.',
            'isCapReason' => 'Reads or records review and removal-push metadata; never sends a turn, starts a check, or changes a claim.',
            'brief' => 'Reads or records review and removal-push metadata; never sends a turn, starts a check, or changes a claim.',
            'deliverables' => 'Reads or records review and removal-push metadata; never sends a turn, starts a check, or changes a claim.',
            'fixupBrief' => 'Reads or records review and removal-push metadata; never sends a turn, starts a check, or changes a claim.',
            'fixupDeliverables' => 'Reads or records review and removal-push metadata; never sends a turn, starts a check, or changes a claim.',
            'reconcileRemovalPush' => 'Reads or records review and removal-push metadata; never sends a turn, starts a check, or changes a claim.',
            'recordRemovalPush' => 'Reads or records review and removal-push metadata; never sends a turn, starts a check, or changes a claim.',
            'isReviewed' => 'Reads or records review and removal-push metadata; never sends a turn, starts a check, or changes a claim.',
            'hasUnreviewedWork' => 'Reads or records review and removal-push metadata; never sends a turn, starts a check, or changes a claim.',
            'due' => 'Reads or records review and removal-push metadata; never sends a turn, starts a check, or changes a claim.',
            'fixupsInWindow' => 'Reads or records review and removal-push metadata; never sends a turn, starts a check, or changes a claim.',
            'nextPosition' => 'Reads or records review and removal-push metadata; never sends a turn, starts a check, or changes a claim.',
            'log' => 'Reads or records review and removal-push metadata; never sends a turn, starts a check, or changes a claim.',
        ],
        TaskExecutionHold::class => [
            'active' => 'Reusable execution-hold infrastructure; effect callers own tested orchestration admission.',
            'run' => 'Reusable execution-hold infrastructure; effect callers own tested orchestration admission.',
        ],
        TaskDeliverableVerifier::class => [
            'unconfirmed' => 'Compares recorded deliverable evidence without starting a check.',
            'failures' => 'Compares recorded deliverable evidence without starting a check.',
            'matches' => 'Compares recorded deliverable evidence without starting a check.',
        ],
        TaskDeliverableEvidence::class => [
            'fromArray' => 'Parses deliverable verification evidence.',
            'baseRunReview' => 'Parses deliverable verification evidence.',
        ],
        TaskCheckReading::class => [
            'running' => 'Builds a check observation without starting a check.',
            'finished' => 'Builds a check observation without starting a check.',
            'interrupted' => 'Builds a check observation without starting a check.',
            'lost' => 'Builds a check observation without starting a check.',
        ],
        TaskCheckOutputBuilder::class => [
            'tail' => 'Reads and formats check output without starting a check.',
            'build' => 'Reads and formats check output without starting a check.',
            'buildStream' => 'Reads and formats check output without starting a check.',
        ],
        TaskBroadcastObserver::class => [
            'created' => 'Records UI broadcast notifications.',
            'updated' => 'Records UI broadcast notifications.',
            'deleted' => 'Records UI broadcast notifications.',
        ],
        TaskAssistance::class => [
            'sameRequest' => 'Records assistance or compares requests; does not send, check, or claim. Tick callers are filtered or guarded.',
            'clearResolvedParent' => 'Records assistance or compares requests; does not send, check, or claim. Tick callers are filtered or guarded.',
            'apply' => 'Records assistance or compares requests; does not send, check, or claim. Tick callers are filtered or guarded.',
            'questionFromBlockedReason' => 'Records assistance or compares requests; does not send, check, or claim. Tick callers are filtered or guarded.',
        ],
        InstanceProvisionIntent::class => [
            'for' => 'Builds a provisioning request without provisioning.',
        ],
        InstanceProvisionFailure::class => [
            'fromException' => 'Classifies an existing provisioning failure.',
        ],
        DeliverablePathChecker::class => [
            'check' => 'Validates a deliverable path without starting a Project check.',
        ],
        AgentThreadObserver::class => [
            'observe' => 'Reads and records agent observations; never sends.',
            'record' => 'Reads and records agent observations; never sends.',
        ],
        AgentDriverRegistry::class => [
            'get' => 'Resolves the configured driver without starting it.',
        ],
        AgentDriverException::class => [
            'unsupported' => 'Builds a typed driver error.',
        ],
        AgentInputRequest::class => [
            'toArray' => 'Serializes an input request.',
        ],
        AgentObservation::class => [
            'toArray' => 'Serializes an agent observation.',
        ],
        AgentThreadEvent::class => [
            'toArray' => 'Serializes a transcript event.',
        ],
        LeasedAgentDriver::class => [
            'create' => 'Reusable agent transport and session infrastructure; TaskAgentSpawner and TaskSessionActor own tested admission.',
            'send' => 'Reusable agent transport and session infrastructure; TaskAgentSpawner and TaskSessionActor own tested admission.',
            'respond' => 'Reusable agent transport and session infrastructure; TaskAgentSpawner and TaskSessionActor own tested admission.',
            'interrupt' => 'Reusable agent transport and session infrastructure; TaskAgentSpawner and TaskSessionActor own tested admission.',
            'archive' => 'Reusable agent transport and session infrastructure; TaskAgentSpawner and TaskSessionActor own tested admission.',
            'observe' => 'Reusable agent transport and session infrastructure; TaskAgentSpawner and TaskSessionActor own tested admission.',
            'read' => 'Reusable agent transport and session infrastructure; TaskAgentSpawner and TaskSessionActor own tested admission.',
            'events' => 'Reusable agent transport and session infrastructure; TaskAgentSpawner and TaskSessionActor own tested admission.',
            'key' => 'Reads the driver key.',
        ],
        PrunePendingTaskThreads::class => [
            'run' => 'Removes unstarted placeholder rows for ended tasks; never sends or changes a group claim.',
        ],
        TaskBroadcasts::class => [
            'groupCreated' => 'Records or emits UI broadcasts, never an agent turn.',
            'groupChanged' => 'Records or emits UI broadcasts, never an agent turn.',
            'commentCreated' => 'Records or emits UI broadcasts, never an agent turn.',
            'threadChanged' => 'Records or emits UI broadcasts, never an agent turn.',
            'extensionChanged' => 'Records or emits UI broadcasts, never an agent turn.',
            'broadcastsGroupChange' => 'Records or emits UI broadcasts, never an agent turn.',
            'broadcastsThreadChange' => 'Records or emits UI broadcasts, never an agent turn.',
            'flush' => 'Sends UI broadcasts, never an agent turn.',
        ],
        TaskDeliverable::class => [
            'fromArray' => 'Parses or formats a deliverable.',
            'listFrom' => 'Parses or formats a deliverable.',
            'join' => 'Parses or formats a deliverable.',
            'relative' => 'Parses or formats a deliverable.',
            'toArray' => 'Serializes a deliverable.',
            'line' => 'Formats a deliverable.',
        ],
        TaskExtensionState::class => [
            'enabled' => 'Reads extension availability.',
            'enable' => 'Enables the extension without starting work.',
            'disable' => 'Disables the extension without starting work.',
        ],
        TaskPullRequestCheck::class => [
            'failures' => 'Reads or formats PR check evidence.',
            'fromRun' => 'Reads or formats PR check evidence.',
            'withoutExplainedRollups' => 'Reads or formats PR check evidence.',
            'rollup' => 'Reads check results.',
        ],
        TaskPullRequestHealth::class => [
            'isReason' => 'Recognizes an existing PR health reason.',
            'reason' => 'Builds a PR health reason.',
        ],
        TaskReviewCandidate::class => [
            'digest' => 'Hashes review evidence.',
        ],
        TaskReviewContext::class => [
            'render' => 'Renders review context without sending it.',
        ],
        TaskReviewPacket::class => [
            'render' => 'Renders a review packet without sending it.',
        ],
        TaskSessionObservation::class => [
            'thread' => 'Reads an observed thread.',
            'toArray' => 'Serializes a session observation.',
        ],
        TaskSettlingFixup::class => [
            'plans' => 'Builds a fixup plan without starting it.',
            'withCheckOutput' => 'Builds a fixup plan without starting it.',
            'reviewPlan' => 'Builds a fixup plan without starting it.',
            'conflictBase' => 'Reads a recorded conflict base.',
        ],
        TaskThreadObservation::class => [
            'toArray' => 'Serializes a thread observation.',
        ],
        TaskTickClock::class => [
            'record' => 'Records when the global tick starts.',
            'lastStartedAt' => 'Reads when the global tick last started.',
            'lastStarted' => 'Reads when the global tick last started.',
        ],
        TaskTopology::class => [
            'maxNodes' => 'Parses, validates or serializes declared topology without provisioning.',
            'invalidMessage' => 'Parses, validates or serializes declared topology without provisioning.',
            'tooManyMessage' => 'Parses, validates or serializes declared topology without provisioning.',
            'valid' => 'Parses, validates or serializes declared topology without provisioning.',
            'fromInput' => 'Parses, validates or serializes declared topology without provisioning.',
            'fromStored' => 'Parses, validates or serializes declared topology without provisioning.',
            'forGroup' => 'Parses, validates or serializes declared topology without provisioning.',
            'declarations' => 'Parses, validates or serializes declared topology without provisioning.',
            'union' => 'Parses, validates or serializes declared topology without provisioning.',
            'assertFits' => 'Parses, validates or serializes declared topology without provisioning.',
            'covers' => 'Parses, validates or serializes declared topology without provisioning.',
            'castUsing' => 'Parses, validates or serializes declared topology without provisioning.',
            'isEmpty' => 'Reads declared topology.',
            'count' => 'Reads declared topology.',
            'nodes' => 'Reads declared topology.',
            'toArray' => 'Serializes declared topology.',
        ],
        TaskTurnFetchNotice::class => [
            'apply' => 'Formats a fetch notice without sending it.',
            'clear' => 'Clears a recorded turn-fetch failure.',
            'fail' => 'Records a turn-fetch failure without sending.',
        ],
        TaskTurnPullRequest::class => [
            'fromArray' => 'Parses a turn PR reference.',
            'toArray' => 'Serializes a turn PR reference.',
        ],
        TaskTurnReceipt::class => [
            'parse' => 'Parses or validates a recorded receipt.',
            'withThread' => 'Parses or validates a recorded receipt.',
            'fits' => 'Parses or validates a recorded receipt.',
            'body' => 'Formats a receipt without sending it.',
        ],
        LocalTaskSettleMetricsCollector::class => [
            'collect' => 'Reads settle metrics.',
        ],
        TaskConsultation::class => [
            'receiptItem' => 'Reads receipts or formats decisions without sending them.',
            'receiptOutcome' => 'Reads receipts or formats decisions without sending them.',
            'waitingItem' => 'Reads receipts or formats decisions without sending them.',
            'reminderKey' => 'Reads receipts or formats decisions without sending them.',
            'failedItems' => 'Reads receipts or formats decisions without sending them.',
            'isWorking' => 'Reads receipts or formats decisions without sending them.',
            'newerTurnHasStopped' => 'Reads receipts or formats decisions without sending them.',
            'topologyRequiredItem' => 'Reads receipts or records retry observations; never sends a turn or changes a claim.',
            'confirmationItem' => 'Reads receipts or records retry observations; never sends a turn or changes a claim.',
            'collectReceipt' => 'Reads receipts or records retry observations; never sends a turn or changes a claim.',
            'pendingReceipt' => 'Reads receipts or records retry observations; never sends a turn or changes a claim.',
            'clearCommunicationFailures' => 'Reads receipts or records retry observations; never sends a turn or changes a claim.',
            'pendingRelayResolution' => 'Reads receipts or records retry observations; never sends a turn or changes a claim.',
            'reconcilePiRestart' => 'Reads receipts or records retry observations; never sends a turn or changes a claim.',
            'subtaskReviewer' => 'Reads receipts or records retry observations; never sends a turn or changes a claim.',
            'failSpawn' => 'Reads receipts or records retry observations; never sends a turn or changes a claim.',
        ],
        TaskGitHubReviewConsumption::class => [
            'consumed' => 'Reads or records consumed review evidence.',
            'record' => 'Reads or records consumed review evidence.',
            'orphanCharges' => 'Reads or records consumed review evidence.',
            'missingFixups' => 'Reads or records consumed review evidence.',
        ],
        TaskGitHubReviewFeedback::class => [
            'isReason' => 'Recognizes an existing review reason.',
            'causes' => 'Builds review feedback.',
            'change' => 'Builds review feedback.',
        ],
        TaskGitHubReviewObservations::class => [
            'begin' => 'Reads and records GitHub observations.',
            'finishCached' => 'Reads and records GitHub observations.',
            'finish' => 'Reads and records GitHub observations.',
            'report' => 'Reads and records GitHub observations.',
        ],
        TaskGroupMetricsRefresher::class => [
            'refresh' => 'Refreshes metrics only.',
        ],
        TaskObservationAvailability::class => [
            'clear' => 'Records observation availability only.',
            'unavailableDecision' => 'Records observation availability only.',
        ],
        TaskPublication::class => [
            'orphanedCommit' => 'Reads commit state or builds a backoff key.',
            'publicationBackoffKey' => 'Reads commit state or builds a backoff key.',
        ],
        TaskReviewOutcome::class => [
            'workspaceSnapshot' => 'Reads the workspace snapshot.',
        ],
        TaskReviewPacketBuilder::class => [
            'build' => 'Builds review context without sending it.',
            'reviewContext' => 'Builds review context without sending it.',
        ],
        TaskScheduler::class => [
            'isTickFailureReason' => 'Forwards pure predicates, receipt formatting, or retry bookkeeping; effectful forwards are exercised separately.',
            'receiptItem' => 'Forwards pure predicates, receipt formatting, or retry bookkeeping; effectful forwards are exercised separately.',
            'receiptOutcome' => 'Forwards pure predicates, receipt formatting, or retry bookkeeping; effectful forwards are exercised separately.',
            'waitingItem' => 'Forwards pure predicates, receipt formatting, or retry bookkeeping; effectful forwards are exercised separately.',
            'failedItems' => 'Forwards pure predicates, receipt formatting, or retry bookkeeping; effectful forwards are exercised separately.',
            'retryDelaySeconds' => 'Forwards pure predicates, receipt formatting, or retry bookkeeping; effectful forwards are exercised separately.',
            'extendBackoff' => 'Forwards pure predicates, receipt formatting, or retry bookkeeping; effectful forwards are exercised separately.',
            'retryIsDue' => 'Forwards pure predicates, receipt formatting, or retry bookkeeping; effectful forwards are exercised separately.',
            'readBackoff' => 'Forwards pure predicates, receipt formatting, or retry bookkeeping; effectful forwards are exercised separately.',
            'rememberBackoff' => 'Forwards pure predicates, receipt formatting, or retry bookkeeping; effectful forwards are exercised separately.',
            'isClaimFailureReason' => 'Forwards pure predicates, receipt formatting, or retry bookkeeping; effectful forwards are exercised separately.',
            'isWorking' => 'Forwards pure predicates, receipt formatting, or retry bookkeeping; effectful forwards are exercised separately.',
            'newerTurnHasStopped' => 'Forwards pure predicates, receipt formatting, or retry bookkeeping; effectful forwards are exercised separately.',
            'isMissingPullRequestReason' => 'Forwards pure predicates, receipt formatting, or retry bookkeeping; effectful forwards are exercised separately.',
            'resumeBlocked' => 'Forwards pure predicates, receipt formatting, or retry bookkeeping; effectful forwards are exercised separately.',
            'removeAbandonedWorkspaces' => 'Allowed ended-group cleanup; never starts a turn, check, or group claim.',
            'orphanedCommit' => 'Forwards read or record-only helpers; effectful forwards are exercised in Refuses.',
            'publicationBackoffKey' => 'Forwards read or record-only helpers; effectful forwards are exercised in Refuses.',
            'topologyRequiredItem' => 'Forwards read or record-only helpers; effectful forwards are exercised in Refuses.',
            'confirmationItem' => 'Forwards read or record-only helpers; effectful forwards are exercised in Refuses.',
            'collectReceipt' => 'Forwards read or record-only helpers; effectful forwards are exercised in Refuses.',
            'pendingReceipt' => 'Forwards read or record-only helpers; effectful forwards are exercised in Refuses.',
            'clearCommunicationFailures' => 'Forwards read or record-only helpers; effectful forwards are exercised in Refuses.',
            'pendingRelayResolution' => 'Forwards read or record-only helpers; effectful forwards are exercised in Refuses.',
            'reconcilePiRestart' => 'Forwards read or record-only helpers; effectful forwards are exercised in Refuses.',
            'clearUnavailable' => 'Forwards read or record-only helpers; effectful forwards are exercised in Refuses.',
            'unavailableDecision' => 'Forwards read or record-only helpers; effectful forwards are exercised in Refuses.',
            'workspaceSnapshot' => 'Forwards read or record-only helpers; effectful forwards are exercised in Refuses.',
            'subtaskReviewer' => 'Forwards read or record-only helpers; effectful forwards are exercised in Refuses.',
            'progressBlockedByAssistance' => 'Forwards read or record-only helpers; effectful forwards are exercised in Refuses.',
            'conflictBase' => 'Forwards read or record-only helpers; effectful forwards are exercised in Refuses.',
            'failSpawn' => 'Forwards read or record-only helpers; effectful forwards are exercised in Refuses.',
        ],
        TaskSessionObserver::class => [
            'observe' => 'Reads agent observations.',
        ],
        TaskSettling::class => [
            'retryDelaySeconds' => 'Reads predicates or records retry bookkeeping; does not send, start a check, or change a claim.',
            'extendBackoff' => 'Reads predicates or records retry bookkeeping; does not send, start a check, or change a claim.',
            'retryIsDue' => 'Reads predicates or records retry bookkeeping; does not send, start a check, or change a claim.',
            'readBackoff' => 'Reads predicates or records retry bookkeeping; does not send, start a check, or change a claim.',
            'rememberBackoff' => 'Reads predicates or records retry bookkeeping; does not send, start a check, or change a claim.',
            'isForeignPullRequestReason' => 'Reads predicates or records retry bookkeeping; does not send, start a check, or change a claim.',
            'isMissingPullRequestReason' => 'Reads predicates or records retry bookkeeping; does not send, start a check, or change a claim.',
            'resumeBlocked' => 'Reads predicates or records retry bookkeeping; does not send, start a check, or change a claim.',
            'removeAbandonedWorkspaces' => 'Allowed ended-group cleanup; never starts a turn, check, or group claim.',
            'observeExternalPullRequest' => 'Reads or records PR observations and progress gates.',
            'progressBlockedByAssistance' => 'Reads or records PR observations and progress gates.',
            'conflictBase' => 'Reads or records PR observations and progress gates.',
        ],
        TaskSubtaskRunner::class => [
            'recordReading' => 'Records existing check readings or reads task ordering without starting a check or turn.',
            'orderedTasks' => 'Records existing check readings or reads task ordering without starting a check or turn.',
            'lowestTodo' => 'Records existing check readings or reads task ordering without starting a check or turn.',
            'finishWorkspaceRoute' => 'Reads workspace state or records metadata; does not start agents, checks, or claims.',
            'workspaceRouteReady' => 'Reads workspace state or records metadata; does not start agents, checks, or claims.',
            'hasImplementer' => 'Reads workspace state or records metadata; does not start agents, checks, or claims.',
            'recordSubtaskStart' => 'Reads workspace state or records metadata; does not start agents, checks, or claims.',
            'lockedTasks' => 'Reads workspace state or records metadata; does not start agents, checks, or claims.',
            'runningSibling' => 'Reads workspace state or records metadata; does not start agents, checks, or claims.',
            'clearUnavailable' => 'Reads workspace state or records metadata; does not start agents, checks, or claims.',
            'unavailableDecision' => 'Reads workspace state or records metadata; does not start agents, checks, or claims.',
        ],
        TaskTurnFetcher::class => [
            'beforeTurn' => 'Reads turn state.',
            'fetch' => 'Reads turn state.',
        ],
        TaskWorkspaceLease::class => [
            'within' => 'Reusable workspace serialization and recovery infrastructure; effect callers own tested orchestration admission.',
            'remoteStarted' => 'Reusable workspace serialization and recovery infrastructure; effect callers own tested orchestration admission.',
            'remoteFinished' => 'Reusable workspace serialization and recovery infrastructure; effect callers own tested orchestration admission.',
            'withThreadLock' => 'Reusable workspace serialization and recovery infrastructure; effect callers own tested orchestration admission.',
            'admittedSendKey' => 'Reusable workspace serialization and recovery infrastructure; effect callers own tested orchestration admission.',
            'inputContinuation' => 'Reusable workspace serialization and recovery infrastructure; effect callers own tested orchestration admission.',
            'completedSend' => 'Reusable workspace serialization and recovery infrastructure; effect callers own tested orchestration admission.',
            'retrying' => 'Reusable workspace serialization and recovery infrastructure; effect callers own tested orchestration admission.',
            'requireReviewBaseline' => 'Reusable workspace serialization and recovery infrastructure; effect callers own tested orchestration admission.',
            'dispatch' => 'Reusable workspace serialization and recovery infrastructure; effect callers own tested orchestration admission.',
            'pendingSend' => 'Reusable workspace serialization and recovery infrastructure; effect callers own tested orchestration admission.',
            'abandonPreparation' => 'Reusable workspace serialization and recovery infrastructure; effect callers own tested orchestration admission.',
            'rejectedDispatch' => 'Reusable workspace serialization and recovery infrastructure; effect callers own tested orchestration admission.',
            'invalidateCheck' => 'Reusable workspace serialization and recovery infrastructure; effect callers own tested orchestration admission.',
            'readCheck' => 'Reusable workspace serialization and recovery infrastructure; effect callers own tested orchestration admission.',
            'release' => 'Reusable workspace serialization and recovery infrastructure; effect callers own tested orchestration admission.',
            'finishedCheck' => 'Reusable workspace serialization and recovery infrastructure; effect callers own tested orchestration admission.',
            'localOwnerDead' => 'Reusable workspace serialization and recovery infrastructure; effect callers own tested orchestration admission.',
            'beginTick' => 'Initializes workspace lease bookkeeping.',
            'endTick' => 'Clears workspace lease bookkeeping.',
            'currentScope' => 'Reads the current workspace lease.',
            'cancelQueued' => 'Reusable workspace serialization infrastructure; engine callers own orchestration admission.',
            'acquire' => 'Reusable workspace serialization infrastructure; engine callers own orchestration admission.',
            'run' => 'Reusable workspace serialization infrastructure; engine callers own orchestration admission.',
            'inspect' => 'Reusable workspace serialization infrastructure; engine callers own orchestration admission.',
            'withReceipt' => 'Reusable workspace serialization infrastructure; engine callers own orchestration admission.',
            'turn' => 'Reusable workspace serialization infrastructure; engine callers own orchestration admission.',
            'startCheck' => 'Reusable workspace serialization infrastructure; engine callers own orchestration admission.',
            'reconcile' => 'Reusable workspace serialization infrastructure; engine callers own orchestration admission.',
            'ownedChange' => 'Reusable workspace serialization infrastructure; engine callers own orchestration admission.',
            'recordPendingCheck' => 'Reusable workspace serialization infrastructure; engine callers own orchestration admission.',
            'status' => 'Reusable workspace serialization infrastructure; engine callers own orchestration admission.',
        ],
        ArchiveAgentTranscriptsAction::class => [
            'path' => 'Allowed ended-group transcript cleanup.',
            'execute' => 'Allowed ended-group transcript cleanup.',
            'read' => 'Allowed ended-group transcript cleanup.',
        ],
        CancelTaskCheckAction::class => [
            'execute' => 'Stops a check during allowed group cancellation or completion.',
        ],
        CancelTaskGroupAction::class => [
            'execute' => 'Allowed tasks:cancel boundary.',
        ],
        CloseTaskQuestionAction::class => [
            'execute' => 'Records an operator question closure without sending.',
        ],
        CompleteTaskGroupAction::class => [
            'execute' => 'Allowed tasks:complete boundary.',
        ],
        CreateTaskAction::class => [
            'execute' => 'Creates a subtask record without claiming or sending.',
        ],
        DestroyTaskAction::class => [
            'execute' => 'Deletes a subtask record; external subtask cancellation behavior is deferred.',
        ],
        ListAgentWorkspacesAction::class => [
            'execute' => 'Reads workspace records.',
        ],
        ListTaskGroupsAction::class => [
            'execute' => 'Reads groups.',
        ],
        ListTaskQuestionsAction::class => [
            'execute' => 'Reads questions.',
        ],
        ReadAgentThreadAction::class => [
            'execute' => 'Reads saved or live transcripts.',
            'assertReadable' => 'Reads saved or live transcripts.',
            'nodeGone' => 'Reads saved or live transcripts.',
            'savedTranscript' => 'Reads saved or live transcripts.',
            'event' => 'Reads saved or live transcripts.',
        ],
        RemoveTaskWorkspaceAction::class => [
            'reservationCutoff' => 'Reads the provisioning-claim timeout; does not change a claim.',
            'awaitVmPreservation' => 'Reads the provisioning-claim timeout; does not change a claim.',
            'claimInFlight' => 'Allowed ended-group workspace cleanup; no task turn, check, or claim.',
            'remove' => 'Allowed ended-group workspace cleanup; no task turn, check, or claim.',
            'recordFailure' => 'Allowed ended-group workspace cleanup; no task turn, check, or claim.',
            'finishRemoval' => 'Allowed ended-group workspace cleanup; no task turn, check, or claim.',
        ],
        RequireTasksExtensionAction::class => [
            'execute' => 'Checks extension availability.',
        ],
        ResumeDeliverableCorrectionAction::class => [
            'continuationMessage' => 'Builds continuation text or records a superseded correction without sending.',
            'supersede' => 'Builds continuation text or records a superseded correction without sending.',
        ],
        RetryTaskBaselineAction::class => [
            'unrequested' => 'Reads baseline retry state.',
            'pending' => 'Reads baseline retry state.',
        ],
        ShowAgentThreadsAction::class => [
            'execute' => 'Reads thread records.',
        ],
        ShowTaskGroupAction::class => [
            'execute' => 'Reads a group.',
        ],
        ShowTasksStatusAction::class => [
            'execute' => 'Reads Tasks status.',
        ],
        StopTaskSubtaskAction::class => [
            'snapshot' => 'Stops agent/check activity for cancellation; does not start a check or turn.',
            'execute' => 'Stops agent/check activity for cancellation; does not start a check or turn.',
        ],
        StoreTaskCommentAction::class => [
            'commitHeldDirectionReview' => 'Records a held direction resolution after its guarded send boundary.',
        ],
        StreamAgentThreadAction::class => [
            'execute' => 'Reads transcript events.',
        ],
        UpdateTaskAction::class => [
            'execute' => 'Updates subtask metadata without claiming or sending.',
        ],
        WatchIncomingPullRequestsAction::class => [
            'execute' => 'Reads and records incoming PR observations.',
        ],
        WatchTaskBranchPullRequestAction::class => [
            'execute' => 'Reads and records a task branch PR.',
        ],
    ];

    /** @var array<class-string, list<string>> */
    public const array Skips = [
        FailTaskVmAction::class => ['execute', 'requestAssistance'],
        TaskScheduler::class => ['tick', 'claimNext', 'claimAvailable', 'releaseStaleReservations'],
        TaskClaimAdmission::class => ['claimNext', 'claimAvailable', 'releaseStaleReservations'],
        CreateTaskGroupAction::class => ['execute'],
        UpdateTaskGroupAction::class => ['execute'],
    ];
}
