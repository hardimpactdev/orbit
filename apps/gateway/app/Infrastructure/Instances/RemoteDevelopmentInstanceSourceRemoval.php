<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\InstanceCreationRecovery;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Instances\Removal\InstanceSourceMismatch;
use App\Domain\Instances\Removal\InstanceSourceRevalidationExpectation;
use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Schedules\ScheduleSpecification;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Instance;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class RemoteDevelopmentInstanceSourceRemoval implements DevelopmentInstanceSourceFinalizer, DevelopmentInstanceSourceRemoval
{
    /**
     * The refusals with which `instance.inspect-source` name the identity check that
     * failed ([Node agent](/reference/node-agent#commands)).
     *
     * @var array<string, InstanceSourceMismatch>
     */
    private const array CommandMismatches = [
        'instance.path_mismatch' => InstanceSourceMismatch::Path,
        'instance.ownership_mismatch' => InstanceSourceMismatch::Ownership,
        'instance.layout_mismatch' => InstanceSourceMismatch::Layout,
        'instance.origin_mismatch' => InstanceSourceMismatch::Origin,
        'instance.branch_mismatch' => InstanceSourceMismatch::Branch,
        'instance.worktrees_mismatch' => InstanceSourceMismatch::Worktrees,
    ];

    /** The fields of the inspection evidence, each base64 of the bytes the Node read. */
    private const array InspectionFields = ['top', 'common', 'origin', 'branch', 'commit', 'dirty', 'source_identity', 'worktrees'];

    public function __construct(
        private AgentCommandRunner $agents,
        private ManagedUserAccountResolver $accounts,
        private CheckoutRemovalBoundary $boundaries,
        private AppDevSourceOperationLock $lock,
        private RepositoryReadAccess $access,
    ) {}

    public function inspect(
        Instance $instance,
        bool $force,
        bool $inspectContent = true,
    ): InstanceSourceInventory {
        return $this->lock->synchronized(
            $instance->node_id,
            fn (): InstanceSourceInventory => $this->inspectLocked($instance, $force, $inspectContent),
        );
    }

    private function inspectLocked(
        Instance $instance,
        bool $force,
        bool $inspectContent,
    ): InstanceSourceInventory {
        $context = $this->context($instance);

        $inventory = $this->inspectPathLocked(
            instance: $instance,
            physicalCheckout: $instance->checkout_path,
            logicalCheckout: $instance->checkout_path,
            root: $context['root'],
            user: $context['user'],
            group: $context['group'],
            layout: $instance->source_layout,
            expectedBranch: $context['branch'],
            expectedRepositoryIdentity: $context['repositoryIdentity'],
            force: $force,
            inspectContent: $inspectContent && (! $this->failedCreation($instance) || $instance->registration_request_id !== null),
            unresolved: $this->failedCreation($instance) && $instance->starting_commit === null,
            allowAbsent: $this->failedCreation($instance),
        );

        if (
            $this->failedCreation($instance)
            && $instance->status === InstanceState::Reserved
            && $inventory->sourceIdentity !== 'absent'
            && ($instance->source_prepare_id === null || $instance->registration_request_id !== null)
            && ! $this->removableInPlaceRegistration($instance, $inventory)
        ) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-removal-inspect',
                errorCode: 'instance.remove_refused',
                message: 'The reserved Instance has no completed source preparation. Its existing checkout cannot be removed.',
            );
        }

        return $inventory;
    }

    /**
     * @param  array<string, string>  $quarantineMappings
     */
    private function inspectPathLocked(
        Instance $instance,
        string $physicalCheckout,
        string $logicalCheckout,
        StoragePath $root,
        string $user,
        string $group,
        string $layout,
        ?string $expectedBranch,
        string $expectedRepositoryIdentity,
        bool $force,
        bool $inspectContent = true,
        array $quarantineMappings = [],
        bool $unresolved = false,
        bool $allowAbsent = false,
    ): InstanceSourceInventory {
        $result = $this->runRefusable(
            $instance,
            'instance.inspect-source',
            [
                'checkout' => $physicalCheckout,
                'root' => $root->value,
                'user' => $user,
                'group' => $group,
                'layout' => $layout,
                'branch' => $expectedBranch ?? '',
                'inspect_content' => $inspectContent && ! $force,
                'unresolved' => $unresolved,
                'allow_absent' => $allowAbsent,
                'origin' => $instance->project->repository_url,
                'prepare_id' => $instance->source_prepare_id ?? '',
            ],
            step: 'app-instance-source-removal-inspect',
            force: $force,
        );

        [$top, $common, $origin, $branch, $commit, $dirty, $sourceIdentity, $worktrees] = array_map(
            fn (string $field): string => $this->decode(is_string($result[$field] ?? null) ? $result[$field] : null, $instance, $force),
            self::InspectionFields,
        );
        $branch = $branch === '' ? null : $branch;
        $top = $top === $physicalCheckout ? $logicalCheckout : $top;
        $common = $common === $physicalCheckout.'/.git' ? $logicalCheckout.'/.git' : $common;
        $checkout = StoragePath::tryParse($top);
        $commonPath = StoragePath::tryParse($common);

        if (
            ! $checkout instanceof StoragePath
            || ! $commonPath instanceof StoragePath
            || ($commit !== '' || $instance->starting_commit !== null) && preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1
            || ! in_array($dirty, ['', '0', '1'], true)
            || (preg_match('/\A[0-9]+:[0-9]+\z/D', $sourceIdentity) !== 1 && ! ($allowAbsent && $sourceIdentity === 'absent'))
        ) {
            $this->invalidEvidence($instance, $force);
        }

        if (
            $checkout->value !== $logicalCheckout
            || $layout === InstanceSourceLayout::Checkout->value
            && $commonPath->value !== $logicalCheckout.'/.git'
            || $layout === InstanceSourceLayout::Worktree->value
            && $sourceIdentity !== 'absent'
            && $commonPath->value === $logicalCheckout.'/.git'
        ) {
            $this->mismatch($instance, InstanceSourceMismatch::Layout);
        }

        if ($unresolved) {
            $branch = $expectedBranch;
        }

        if ($branch !== $expectedBranch) {
            $this->mismatch($instance, InstanceSourceMismatch::Branch);
        }

        try {
            $repositoryIdentity = GitRepositoryIdentity::derive($origin);
        } catch (InvalidArgumentException) {
            $this->mismatch($instance, InstanceSourceMismatch::Origin);
        }

        if ($repositoryIdentity !== $expectedRepositoryIdentity) {
            $this->mismatch($instance, InstanceSourceMismatch::Origin);
        }

        if ($inspectContent && ! $force && $instance->starting_commit !== null && $dirty !== '0') {
            $this->unsafeContent($instance);
        }

        if ($inspectContent && ! $force && $instance->starting_commit !== null && ! $this->isPublished($instance, $origin, $commit)) {
            $this->unsafeContent($instance);
        }

        $linkedWorktreePaths = $sourceIdentity === 'absent' ? [$logicalCheckout] : $this->worktreePaths(
            $worktrees,
            $instance,
            $checkout->value,
            $force,
            $quarantineMappings,
        );
        $payload = [
            'instance_id' => $instance->id,
            'layout' => $layout,
            'repository_identity' => $repositoryIdentity,
            'checkout_path' => $checkout->value,
            'root' => $root->value,
            'branch' => $branch,
            'starting_commit' => $commit,
            'common_repository_path' => dirname($commonPath->value),
            'source_identity' => $sourceIdentity,
            'linked_worktree_paths' => $linkedWorktreePaths,
        ];

        return new InstanceSourceInventory(
            instanceId: $instance->id,
            layout: $layout,
            repositoryIdentity: $repositoryIdentity,
            checkoutPath: $checkout->value,
            root: $root->value,
            branch: $branch,
            startingCommit: $commit,
            commonRepositoryPath: dirname($commonPath->value),
            sourceIdentity: $sourceIdentity,
            linkedWorktreePaths: $linkedWorktreePaths,
            origin: $origin,
            worktreeInventory: $worktrees,
            digest: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        );
    }

    public function revalidate(
        InstanceRemovalMember $member,
        ?InstanceSourceRevalidationExpectation $expectation = null,
    ): InstanceSourceRevalidationState {
        return $this->lock->synchronized($member->node_id, function () use (
            $member,
            $expectation,
        ): InstanceSourceRevalidationState {
            $state = $member->source_prepared_at === null
                ? InstanceSourceRevalidationState::Present
                : $this->revalidationStateLocked($member);
            $receiptStructure = $state === InstanceSourceRevalidationState::ReceiptPendingCleanup
                ? $this->receiptStructureStateLocked($member)
                : null;

            if (
                in_array(
                    $state,
                    [InstanceSourceRevalidationState::Present, InstanceSourceRevalidationState::Quarantined],
                    true,
                )
                || $state === InstanceSourceRevalidationState::ReceiptPendingCleanup
                && $receiptStructure === 'intact'
            ) {
                $states = $expectation->authenticatedMemberStates ?? [];
                $states[$member->id] = $state;
                $authenticatedExpectation = $expectation instanceof InstanceSourceRevalidationExpectation
                    ? new InstanceSourceRevalidationExpectation(
                        $expectation->requiredLinkedWorktreePaths,
                        $expectation->permittedLinkedWorktreePaths,
                        $states,
                    )
                    : null;
                $this->inspectRecordedLocked(
                    $member,
                    $state,
                    $authenticatedExpectation,
                    allowDependentWorktrees: true,
                );
            }

            return $state;
        });
    }

    public function inspectRecorded(
        InstanceRemovalMember $member,
        InstanceSourceRevalidationState $state,
        ?InstanceSourceRevalidationExpectation $expectation = null,
    ): InstanceSourceInventory {
        return $this->lock->synchronized(
            $member->node_id,
            fn (): InstanceSourceInventory => $this->inspectRecordedLocked($member, $state, $expectation),
        );
    }

    private function inspectRecordedLocked(
        InstanceRemovalMember $member,
        InstanceSourceRevalidationState $state,
        ?InstanceSourceRevalidationExpectation $expectation = null,
        bool $allowDependentWorktrees = false,
    ): InstanceSourceInventory {
        if ($state === InstanceSourceRevalidationState::Completed) {
            $this->recordedConflict($member, 'A completed source has no inspectable source inventory.');
        }

        $this->assertDevelopmentMember($member);
        $instance = Instance::query()->with(['project', 'node'])->find($member->instance_id);

        if (! $instance instanceof Instance) {
            $this->recordedConflict($member, 'The recorded Instance removal member is unavailable.');
        }

        $this->assertRecordedOwnership($member, $instance);
        $context = $this->context($instance);
        $logicalCheckout = (string) $member->checkout_path;
        $physicalCheckout = $state === InstanceSourceRevalidationState::Present
            ? $logicalCheckout
            : $this->quarantinePath($member, $context['root']);
        $removal = $member->removal()->firstOrFail();
        $expectation ??= $this->exactExpectation($member);
        $authenticatedStates = $expectation->authenticatedMemberStates;
        $authenticatedStates[$member->id] = $state;
        $expectation = new InstanceSourceRevalidationExpectation(
            $expectation->requiredLinkedWorktreePaths,
            $expectation->permittedLinkedWorktreePaths,
            $authenticatedStates,
        );
        $inventory = $this->inspectPathLocked(
            instance: $instance,
            physicalCheckout: $physicalCheckout,
            logicalCheckout: $logicalCheckout,
            root: $context['root'],
            user: $context['user'],
            group: $context['group'],
            layout: $member->source_layout,
            expectedBranch: $member->branch,
            expectedRepositoryIdentity: (string) $member->repository_identity,
            force: (bool) $removal->force,
            inspectContent: $member->runtime_published || $instance->registration_request_id !== null
                || ! InstanceCreationRecovery::isPreActivation($instance, removing: true),
            quarantineMappings: $this->quarantineMappings($member, $context['root'], $expectation),
            unresolved: $member->starting_commit === null && ! $member->runtime_published,
            allowAbsent: $member->source_identity === 'absent',
        );

        if (
            $member->common_repository_path !== $inventory->commonRepositoryPath
            || $member->source_identity !== $inventory->sourceIdentity
            || $member->source_commit !== $inventory->startingCommit
            || $member->source_digest !== $this->originalDigest($member, $inventory)
        ) {
            $this->recordedConflict(
                $member,
                "Instance [{$member->name}] source identity changed after removal acceptance.",
            );
        }

        // A linked Instance owns only its worktree. Repository-wide membership is
        // a deletion dependency for a checkout, not for a forced linked removal.
        if (! $this->independentForcedWorktree($member)) {
            $this->assertExpectedLinkedInventory($member, $inventory, $expectation);
        }

        if (
            ! $allowDependentWorktrees
            && $member->source_layout === InstanceSourceLayout::Checkout->value
            && $inventory->linkedWorktreePaths !== [$logicalCheckout]
        ) {
            $this->recordedConflict(
                $member,
                "Instance [{$member->name}] checkout has linked worktrees.",
            );
        }

        return $inventory;
    }

    private function independentForcedWorktree(InstanceRemovalMember $member): bool
    {
        return $member->removal->force
            && $member->source_layout === InstanceSourceLayout::Worktree->value
            && ! $member->removal->members()
                ->where('source_layout', InstanceSourceLayout::Checkout->value)
                ->exists();
    }

    private function exactExpectation(
        InstanceRemovalMember $member,
    ): InstanceSourceRevalidationExpectation {
        $paths = $member->linked_worktree_paths;
        sort($paths, SORT_STRING);

        return new InstanceSourceRevalidationExpectation($paths, $paths);
    }

    private function originalDigest(
        InstanceRemovalMember $member,
        InstanceSourceInventory $inventory,
    ): string {
        return hash('sha256', json_encode([
            'instance_id' => $inventory->instanceId,
            'layout' => $inventory->layout,
            'repository_identity' => $inventory->repositoryIdentity,
            'checkout_path' => $inventory->checkoutPath,
            'root' => $inventory->root,
            'branch' => $inventory->branch,
            'starting_commit' => $inventory->startingCommit,
            'common_repository_path' => $inventory->commonRepositoryPath,
            'source_identity' => $inventory->sourceIdentity,
            'linked_worktree_paths' => $member->linked_worktree_paths,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertExpectedLinkedInventory(
        InstanceRemovalMember $member,
        InstanceSourceInventory $inventory,
        InstanceSourceRevalidationExpectation $expectation,
    ): void {
        $required = array_values(array_unique($expectation->requiredLinkedWorktreePaths));
        $permitted = array_values(array_unique($expectation->permittedLinkedWorktreePaths));
        $live = $inventory->linkedWorktreePaths;
        sort($required, SORT_STRING);
        sort($permitted, SORT_STRING);
        sort($live, SORT_STRING);

        if (
            array_diff($required, $live) !== []
            || array_diff($live, $permitted) !== []
        ) {
            $this->recordedConflict(
                $member,
                'The linked-worktree inventory changed after removal acceptance.',
            );
        }
    }

    private function receiptStructureStateLocked(InstanceRemovalMember $member): string
    {
        [$node, $user, $group, $root] = $this->memberContext($member);
        $result = $this->runStep(
            $node,
            'instance.read-removal-receipt',
            [
                'checkout' => (string) $member->checkout_path,
                'root' => $root->value,
                'common_repository' => (string) $member->common_repository_path,
                'layout' => $member->source_layout,
                'operation' => $member->instance_removal_id,
                'member' => (string) $member->id,
                'digest' => $member->source_digest,
                'receipt' => $this->receipt($member),
                'user' => $user,
                'group' => $group,
                'source_identity' => (string) $member->source_identity,
            ],
            step: 'app-instance-removal-revalidation',
            errorCode: 'instance.removal_conflict',
        );

        $state = $result['state'] ?? null;

        return match ($state) {
            'complete', 'incomplete', 'intact' => $state,
            default => $this->recordedConflict(
                $member,
                'Instance removal returned invalid receipt-recovery evidence.',
            ),
        };
    }

    private function revalidationStateLocked(
        InstanceRemovalMember $member,
    ): InstanceSourceRevalidationState {
        $this->assertDevelopmentMember($member);
        [$node, $user, $group, $root, $groupingDirectory] = $this->memberContext($member);
        $result = $this->runStep(
            $node,
            'instance.read-source-removal-state',
            [
                'checkout' => (string) $member->checkout_path,
                'root' => $root->value,
                'operation' => $member->instance_removal_id,
                'member' => (string) $member->id,
                'digest' => $member->source_digest,
                'receipt' => $this->receipt($member),
                'source_identity' => (string) $member->source_identity,
                'user' => $user,
                'group' => $group,
                'grouping_directory' => $groupingDirectory,
            ],
            step: 'app-instance-removal-revalidation',
            errorCode: 'instance.removal_conflict',
        );
        $state = InstanceSourceRevalidationState::tryFrom(str_replace('_', '-', is_string($result['state'] ?? null) ? $result['state'] : ''));

        if (! $state instanceof InstanceSourceRevalidationState) {
            $this->recordedConflict($member, 'Instance removal returned invalid source-presence evidence.');
        }

        if (
            $state === InstanceSourceRevalidationState::Completed
            && $member->source_layout === InstanceSourceLayout::Worktree->value
        ) {
            return match ($this->receiptStructureStateLocked($member)) {
                'complete' => InstanceSourceRevalidationState::Completed,
                'incomplete' => InstanceSourceRevalidationState::ReceiptPendingCleanup,
                default => $this->recordedConflict(
                    $member,
                    'Completed worktree evidence still has an inspectable source.',
                ),
            };
        }

        return $state;
    }

    /** @return array{0: Node, 1: string, 2: string, 3: StoragePath, 4: string} */
    private function memberContext(InstanceRemovalMember $member): array
    {
        $instance = Instance::query()->with(['project', 'node'])->find($member->instance_id);

        if (! $instance instanceof Instance) {
            $instance = $this->deletedMemberContext($member);
        }

        $this->assertRecordedOwnership($member, $instance);

        $context = $this->context($instance);

        return [
            $instance->node,
            $context['user'],
            $context['group'],
            $context['root'],
            $this->boundaries->instanceGroupingDirectory($instance)->value,
        ];
    }

    private function assertRecordedOwnership(
        InstanceRemovalMember $member,
        Instance $instance,
    ): void {
        if (
            $member->project_id !== $instance->project_id
            || $member->node_id !== $instance->node_id
            || $member->name !== $instance->name
            || $member->environment !== $instance->defaultAppEnv()
            || $member->source_layout !== $instance->source_layout
            || $member->checkout_path !== $instance->checkout_path
            || $member->root !== $instance->effectiveRoot()
            || $member->starting_commit !== null && $member->branch !== $instance->branch
            || $member->starting_commit !== $instance->starting_commit
            || $member->repository_identity !== $instance->project->repository_identity
        ) {
            $this->recordedConflict($member, 'The recorded Instance removal ownership changed.');
        }
    }

    private function deletedMemberContext(InstanceRemovalMember $member): Instance
    {
        $project = Project::query()->find($member->project_id);
        $node = Node::query()->find($member->node_id);

        if (
            $member->row_deleted_at === null
            || ! $project instanceof Project
            || ! $node instanceof Node
        ) {
            $this->recordedConflict($member, 'The recorded Instance removal member is unavailable.');
        }

        $instance = new Instance;
        $instance->forceFill([
            'project_id' => $member->project_id,
            'node_id' => $member->node_id,
            'name' => $member->name,
            'environment' => $member->environment,
            'source_layout' => $member->source_layout,
            'checkout_path' => $member->checkout_path,
            'root' => $member->root,
            'branch' => $member->branch,
            'starting_commit' => $member->starting_commit,
        ]);
        $instance->setAttribute('id', $member->instance_id);
        $instance->setRelation('project', $project);
        $instance->setRelation('node', $node);

        return $instance;
    }

    private function assertDevelopmentMember(InstanceRemovalMember $member): void
    {
        $startingCommit = $member->getAttribute('starting_commit');

        if (
            $member->environment !== 'development'
            || ! in_array(
                $member->source_layout,
                [InstanceSourceLayout::Checkout->value, InstanceSourceLayout::Worktree->value],
                true,
            )
            || ! is_string($member->checkout_path)
            || ! is_string($member->root)
            || $member->getAttribute('branch') !== null
            && ! is_string($member->getAttribute('branch'))
            || (! is_string($startingCommit) && ($startingCommit !== null || $member->runtime_published))
            || ! is_string($member->source_commit)
            || ! is_string($member->common_repository_path)
            || ! is_string($member->source_identity)
            || ! is_string($member->repository_identity)
            || ! Str::isUuid($member->instance_removal_id)
            || $member->id < 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $member->source_digest) !== 1
            || ($member->source_commit !== '' || $member->starting_commit !== null) && preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $member->source_commit) !== 1
        ) {
            $this->recordedConflict($member, 'The recorded Instance removal source evidence is incomplete.');
        }
    }

    private function recordedConflict(InstanceRemovalMember $member, string $message): never
    {
        throw new RuntimeConvergenceException(
            step: 'app-instance-removal-revalidation',
            errorCode: 'instance.removal_conflict',
            message: $message,
        );
    }

    private function receipt(InstanceRemovalMember $member): string
    {
        return hash(
            'sha256',
            "{$member->instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized",
        );
    }

    /** @return array<string, string> */
    private function quarantineMappings(
        InstanceRemovalMember $member,
        StoragePath $root,
        ?InstanceSourceRevalidationExpectation $expectation = null,
    ): array {
        if (! $expectation instanceof InstanceSourceRevalidationExpectation) {
            return [$this->quarantinePath($member, $root) => (string) $member->checkout_path];
        }

        $mappings = [];
        $members = $member->removal->members()->orderBy('position')->get();

        foreach ($members as $recordedMember) {
            $state = $expectation->authenticatedMemberStates[$recordedMember->id] ?? null;

            if (
                $recordedMember->common_repository_path !== $member->common_repository_path
                || ! in_array(
                    $state,
                    [
                        InstanceSourceRevalidationState::Quarantined,
                        InstanceSourceRevalidationState::ReceiptPendingCleanup,
                    ],
                    true,
                )
            ) {
                continue;
            }

            $mappings[$this->quarantinePath($recordedMember, $root)] = (string) $recordedMember->checkout_path;
        }

        return $mappings;
    }

    private function quarantinePath(InstanceRemovalMember $member, StoragePath $root): string
    {
        return sprintf(
            '%s/.orbit-removals/%s.%d.quarantine',
            $root->value,
            $member->instance_removal_id,
            $member->id,
        );
    }

    private function removableInPlaceRegistration(Instance $instance, InstanceSourceInventory $inventory): bool
    {
        return $instance->failed_step === 'registration'
            && $instance->registration_request_id !== null
            && $instance->registration_source_digest !== null
            && $instance->registration_original_path === $instance->checkout_path
            && $instance->registration_authoritative_path === $instance->checkout_path
            && in_array($instance->registration_relocation_state, ['reserved', 'relocating', 'destination_verified', 'original_cleanup', 'relocated'], true)
            && $instance->registration_repository_identity === $inventory->repositoryIdentity
            && $instance->registration_common_repository_path === $inventory->commonRepositoryPath.'/.git'
            && $instance->starting_commit === $inventory->startingCommit
            && $instance->branch === $inventory->branch
            && $instance->registration_detached === ($inventory->branch === null);
    }

    private function failedCreation(Instance $instance): bool
    {
        return InstanceCreationRecovery::isPreActivation($instance);
    }

    /** @return array{root: StoragePath, user: string, group: string, branch: ?string, repositoryIdentity: string} */
    private function context(Instance $instance): array
    {
        $instance->loadMissing(['project', 'node']);

        if (! in_array(
            $instance->source_layout,
            [InstanceSourceLayout::Checkout->value, InstanceSourceLayout::Worktree->value],
            true,
        )) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-layout',
                errorCode: 'instance.source_layout_conflict',
                message: "Instance [{$instance->name}] has an invalid source layout.",
            );
        }

        $branch = $instance->getAttribute('branch');
        $startingCommit = $instance->getAttribute('starting_commit');

        if (
            $branch !== null && ! is_string($branch)
            || (! is_string($startingCommit)
                && ! ($startingCommit === null
                    && InstanceCreationRecovery::isPreActivation($instance, removing: true)))
        ) {
            $this->invalidEvidence($instance, false);
        }

        $repositoryIdentity = $instance->project->getAttribute('repository_identity');

        if (! is_string($repositoryIdentity) || $repositoryIdentity === '') {
            $this->invalidEvidence($instance, false);
        }

        $account = $this->accounts->resolve($instance->node);

        return [
            'root' => $this->boundaries->instanceRoot($instance, $account),
            'user' => $account->user,
            'group' => $account->group,
            'branch' => $branch,
            'repositoryIdentity' => $repositoryIdentity,
        ];
    }

    /**
     * @param  array<string, string>  $quarantineMappings
     * @return list<string>
     */
    private function worktreePaths(
        string $inventory,
        Instance $instance,
        string $expectedCheckout,
        bool $force,
        array $quarantineMappings = [],
    ): array {
        $paths = [];

        foreach (self::liveWorktrees($inventory) as $value) {
            if (isset($quarantineMappings[$value])) {
                $value = $quarantineMappings[$value];
            }

            $path = StoragePath::tryParse($value);

            if (! $path instanceof StoragePath) {
                $this->invalidEvidence($instance, $force);
            }

            $paths[] = $path->value;
        }

        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        if (! in_array($expectedCheckout, $paths, true)) {
            $this->mismatch($instance, InstanceSourceMismatch::Worktrees);
        }

        return $paths;
    }

    /**
     * The worktree paths of a `git worktree list --porcelain -z` listing, without the ones Git
     * marks prunable because their directory is gone, such as a test worktree under a cleared
     * `/tmp`. Git drops those records on its next prune, and removing the checkout removes them.
     *
     * @return list<string>
     */
    private static function liveWorktrees(string $inventory): array
    {
        $paths = [];
        $current = null;
        $prunable = false;

        foreach (explode("\0", $inventory) as $field) {
            if (str_starts_with($field, 'worktree ')) {
                if (is_string($current) && ! $prunable) {
                    $paths[] = $current;
                }

                $current = substr($field, 9);
                $prunable = false;

                continue;
            }

            if ($current !== null && ($field === 'prunable' || str_starts_with($field, 'prunable '))) {
                $prunable = true;
            }
        }

        if (is_string($current) && ! $prunable) {
            $paths[] = $current;
        }

        return $paths;
    }

    private function sourceAccess(int $projectId): ProjectSourceAccess
    {
        return Project::query()->findOrFail($projectId)->source_access;
    }

    private function isPublished(Instance $instance, string $origin, string $commit): bool
    {
        $result = $this->runStep(
            $instance->node,
            'instance.check-publication',
            ['origin' => $origin, 'commit' => $commit, 'git_read' => $this->access->for($origin, $this->sourceAccess($instance->project_id))],
            step: 'app-instance-source-removal-publication',
            errorCode: 'instance.remove_refused',
        );

        return ($result['published'] ?? null) === true;
    }

    private function decode(
        ?string $value,
        Instance $instance,
        bool $force,
    ): string {
        $decoded = is_string($value) ? base64_decode($value, true) : false;

        if (! is_string($decoded)) {
            $this->invalidEvidence($instance, $force);
        }

        return $decoded;
    }

    /**
     * Run a command whose refusal names the check that failed, and turn that refusal into its error code.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function runRefusable(
        Instance $instance,
        string $command,
        array $input,
        string $step,
        bool $force,
    ): array {
        try {
            return $this->runStep($instance->node, $command, $input, $step, $this->failureCode($force));
        } catch (RuntimeConvergenceException $exception) {
            $refused = AgentStep::refusal($exception);
            $mismatch = $refused === null ? null : (self::CommandMismatches[$refused->errorCode] ?? null);

            if ($mismatch instanceof InstanceSourceMismatch) {
                $this->mismatch($instance, $mismatch, $step, $exception->result);
            }

            match ($refused?->errorCode) {
                'instance.unsafe_content' => $this->unsafeContent($instance, $step, $exception->result),
                'instance.source_changed' => throw new RuntimeConvergenceException(
                    step: $step,
                    errorCode: 'instance.removal_conflict',
                    message: "Instance [{$instance->name}] source changed after inspection.",
                    result: $exception->result,
                ),
                default => throw $exception,
            };
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function runStep(Node $node, string $command, array $input, string $step, string $errorCode): array
    {
        return AgentStep::run($this->agents, $node, $command, $input, $step, $errorCode, 'App development');
    }

    private function mismatch(
        Instance $instance,
        InstanceSourceMismatch $mismatch,
        string $step = 'app-instance-source-removal-inspect',
        ?CommandResult $output = null,
    ): never {
        throw new RuntimeConvergenceException(
            step: $step,
            errorCode: $mismatch->value,
            message: $mismatch->describe($instance->name),
            result: $output,
        );
    }

    private function unsafeContent(
        Instance $instance,
        string $step = 'app-instance-source-removal-inspect',
        ?CommandResult $output = null,
    ): never {
        throw new RuntimeConvergenceException(
            step: $step,
            errorCode: 'instance.remove_refused',
            message: "Instance [{$instance->name}] has dirty or unpublished source.",
            result: $output,
        );
    }

    private function invalidEvidence(Instance $instance, bool $force): never
    {
        throw new RuntimeConvergenceException(
            step: 'app-instance-source-removal-inspect',
            errorCode: $this->failureCode($force),
            message: "Instance [{$instance->name}] has invalid source evidence.",
        );
    }

    private function failureCode(bool $force): string
    {
        return $force ? 'instance.force_failed' : 'instance.remove_refused';
    }

    /**
     * The systemd units of the Instance's own Processes and Schedules. Runtime cleanup removes them after its ownership
     * checks, so the process stop leaves them alone. A unit of another owner, such as the Node's `pi-server` that runs
     * the agents, is not on the list: what it started inside the tree is stopped like any other process.
     *
     * @return list<string>
     */
    public static function ownUnits(Instance $instance): array
    {
        $systemd = app(SystemdProcessRenderer::class);
        $schedules = app(ScheduleSpecification::class);
        $units = [];
        foreach ($instance->processes()->get() as $process) {
            if ($process->runtime === ProcessRuntime::Systemd) {
                $units[] = $systemd->unitName($process);
            }
        }
        foreach ($instance->schedules()->get() as $schedule) {
            $units[] = $schedules->serviceName($schedule);
        }
        sort($units);

        return $units;
    }
}
