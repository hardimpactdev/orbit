<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\Registration\RegistrationSourceFacts;
use App\Domain\Instances\Registration\RegistrationSourceManager;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

final readonly class RemoteRegistrationSourceManager implements RegistrationSourceManager
{
    public function __construct(
        private AgentCommandRunner $agents,
        private ManagedUserAccountResolver $accounts,
    ) {}

    public function inspect(Node $node, string $sourcePath, bool $includeWorktrees): array
    {
        $account = $this->account($node);
        $rows = $this->run($node, 'instance.inspect-registration-source', [
            'path' => $sourcePath,
            'include_worktrees' => $includeWorktrees,
            'user' => $account->user,
            'group' => $account->group,
        ], step: 'registration-source-inspect', errorCode: 'instance.source_invalid')['rows'] ?? null;

        if (! is_array($rows) || $rows === []) {
            throw $this->invalidSource();
        }

        $facts = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw $this->invalidSource();
            }

            $facts[] = $this->facts($row);
        }

        $repositoryIdentities = array_unique(array_map(
            static fn (RegistrationSourceFacts $fact): string => $fact->repositoryIdentity,
            $facts,
        ));

        if (count($repositoryIdentities) !== 1) {
            throw $this->invalidSource();
        }

        return $facts;
    }

    public function relocate(Instance $instance, RegistrationSourceFacts $facts): void
    {
        $this->relocateSet([['instance' => $instance, 'facts' => $facts]]);
    }

    public function validateRetained(
        Node $node,
        RegistrationSourceFacts $facts,
        string $authoritativePath,
    ): void {
        try {
            $actual = $this->inspect($node, $authoritativePath, false);
        } catch (\Throwable $exception) {
            throw new ResourceOperationException(
                'instance.registration_conflict',
                'The retained authoritative registration source no longer matches its verified Git identity.',
                409,
                previous: $exception,
            );
        }

        if (
            count($actual) !== 1
            || $actual[0]->path !== $authoritativePath
            || $actual[0]->layout !== $facts->layout
            || $actual[0]->repositoryIdentity !== $facts->repositoryIdentity
        ) {
            throw new ResourceOperationException(
                'instance.registration_conflict',
                'The retained authoritative registration source no longer matches its verified Git identity.',
                409,
            );
        }
    }

    public function validateRelocationRecovery(
        Node $node,
        RegistrationSourceFacts $facts,
        string $candidatePath,
    ): void {
        try {
            $actual = $this->inspect($node, $candidatePath, false);
        } catch (\Throwable $exception) {
            throw new ResourceOperationException(
                'instance.registration_conflict',
                'The retained relocation path no longer matches its preserved source state.',
                409,
                previous: $exception,
            );
        }

        if (
            count($actual) !== 1
            || $actual[0]->path !== $candidatePath
            || $actual[0]->layout !== $facts->layout
            || $actual[0]->repositoryIdentity !== $facts->repositoryIdentity
            || $actual[0]->branch !== $facts->branch
            || $actual[0]->detached !== $facts->detached
            || $actual[0]->commit !== $facts->commit
            || $actual[0]->sourceDigest !== $facts->sourceDigest
        ) {
            throw new ResourceOperationException(
                'instance.registration_conflict',
                'The retained relocation path no longer matches its preserved source state.',
                409,
            );
        }
    }

    public function relocateSet(array $members): void
    {
        if ($members === []) {
            throw new \InvalidArgumentException('A registration source set cannot be empty.');
        }

        $node = $members[0]['instance']->node;
        $payload = [];
        $relocated = 0;
        $cleanupReady = 0;

        foreach ($members as $member) {
            $instance = $member['instance'];
            $facts = $member['facts'];

            if ($instance->node_id !== $node->id) {
                throw new ResourceOperationException(
                    'instance.source_conflict',
                    'Registration sources must share one Node.',
                    409,
                );
            }

            $payload[] = [
                'id' => $instance->id,
                'source' => $facts->path,
                'destination' => $instance->checkout_path,
                'application' => $instance->applicationDirectory(),
                'layout' => $facts->layout->value,
                'commit' => $facts->commit,
                'branch' => $facts->branch,
                'detached' => $facts->detached,
                'digest' => $facts->sourceDigest,
                'common' => $facts->commonRepositoryPath,
                'worktrees' => $facts->worktreePaths,
                'source_device' => $instance->registration_source_device,
                'source_inode' => $instance->registration_source_inode,
            ];

            if (
                $instance->registration_relocation_state === 'relocated'
                && $instance->registration_authoritative_path === $instance->checkout_path
            ) {
                $relocated++;
            }

            if (
                in_array(
                    $instance->registration_relocation_state,
                    ['destination_verified', 'original_cleanup'],
                    strict: true,
                )
                && $instance->registration_authoritative_path === $instance->checkout_path
                && ($facts->path === $instance->checkout_path
                || is_int($instance->registration_source_device)
                && is_int($instance->registration_source_inode))
            ) {
                $cleanupReady++;
            }
        }

        if ($relocated === count($members)) {
            return;
        }

        if ($relocated !== 0 || $cleanupReady !== 0 && $cleanupReady !== count($members)) {
            throw new ResourceOperationException(
                'instance.registration_evidence_invalid',
                'Registration relocation evidence does not contain one complete source set.',
                409,
            );
        }

        if (array_all($members, static fn (array $member): bool => $member['facts']->path === $member['instance']->checkout_path)) {
            if ($cleanupReady === 0) {
                DB::transaction(static function () use ($members): void {
                    foreach ($members as $member) {
                        Instance::query()->whereKey($member['instance']->id)->update([
                            'registration_relocation_state' => 'relocating',
                            'registration_authoritative_path' => $member['facts']->path,
                        ]);
                    }
                });

                // An in-place source needs verification, not relocation or Git-link repair.
                $this->runRelocation($node, 'adopt', $payload);

                DB::transaction(static function () use ($members): void {
                    foreach ($members as $member) {
                        Instance::query()->whereKey($member['instance']->id)->update([
                            'registration_relocation_state' => 'destination_verified',
                            'registration_authoritative_path' => $member['instance']->checkout_path,
                            'registration_source_device' => null,
                            'registration_source_inode' => null,
                        ]);
                    }
                });
            }

            foreach ($members as $member) {
                $this->validateRetained($node, $member['facts'], $member['instance']->checkout_path);
            }

            // Persist adoption before changing environment permissions, so an interrupted
            // finalization never compares those changes with the pre-adoption digest.
            $this->runRelocation($node, 'adopt-finalize', $payload);

            DB::transaction(static function () use ($members): void {
                foreach ($members as $member) {
                    Instance::query()->whereKey($member['instance']->id)->update([
                        'registration_relocation_state' => 'relocated',
                        'registration_authoritative_path' => $member['instance']->checkout_path,
                        'registration_source_device' => null,
                        'registration_source_inode' => null,
                    ]);
                }
            });

            return;
        }

        if ($cleanupReady === 0) {
            DB::transaction(static function () use ($members): void {
                foreach ($members as $member) {
                    Instance::query()
                        ->whereKey($member['instance']->id)
                        ->update([
                            'registration_relocation_state' => 'relocating',
                            'registration_authoritative_path' => $member['facts']->path,
                            'registration_source_device' => null,
                            'registration_source_inode' => null,
                        ]);
                }
            });

            $identities = $this->preparationIdentities($this->runRelocation($node, 'prepare', $payload), $members);

            DB::transaction(static function () use ($members, $identities): void {
                foreach ($members as $member) {
                    $instance = $member['instance'];
                    $identity = $identities[$instance->id];
                    Instance::query()
                        ->whereKey($instance->id)
                        ->update([
                            'registration_relocation_state' => 'destination_verified',
                            'registration_authoritative_path' => $instance->checkout_path,
                            'registration_source_device' => $identity['device'],
                            'registration_source_inode' => $identity['inode'],
                        ]);
                }
            });

            $payload = array_map(
                static function (array $member) use ($identities): array {
                    $identity = $identities[$member['id']];

                    return [
                        ...$member,
                        'source_device' => $identity['device'],
                        'source_inode' => $identity['inode'],
                    ];
                },
                $payload,
            );
        }

        DB::transaction(static function () use ($members): void {
            foreach ($members as $member) {
                Instance::query()
                    ->whereKey($member['instance']->id)
                    ->update(['registration_relocation_state' => 'original_cleanup']);
            }
        });

        $this->runRelocation($node, 'cleanup', $payload);

        DB::transaction(static function () use ($members): void {
            foreach ($members as $member) {
                Instance::query()
                    ->whereKey($member['instance']->id)
                    ->update([
                        'registration_relocation_state' => 'relocated',
                        'registration_authoritative_path' => $member['instance']->checkout_path,
                    ]);
            }
        });
    }

    /**
     * @param  list<array{instance: Instance, facts: RegistrationSourceFacts}>  $members
     * @return array<int, array{device: int|null, inode: int|null}>
     */
    private function preparationIdentities(mixed $rows, array $members): array
    {
        if (! is_array($rows) || count($rows) !== count($members)) {
            throw new ResourceOperationException(
                'instance.registration_incomplete',
                'Registration relocation returned incomplete cleanup evidence.',
                502,
            );
        }

        $identities = [];

        foreach ($rows as $row) {
            $id = is_array($row) ? $row['id'] ?? null : null;
            $device = is_array($row) ? $row['source_device'] ?? null : null;
            $inode = is_array($row) ? $row['source_inode'] ?? null : null;

            if (
                ! is_int($id)
                || isset($identities[$id])
                || ($device !== null
                || $inode !== null)
                && (! is_int($device)
                || ! is_int($inode))
            ) {
                throw new ResourceOperationException(
                    'instance.registration_incomplete',
                    'Registration relocation returned invalid cleanup evidence.',
                    502,
                );
            }

            $identities[$id] = ['device' => $device, 'inode' => $inode];
        }

        $expectedIds = array_map(
            static fn (array $member): int => $member['instance']->id,
            $members,
        );
        sort($expectedIds);
        $actualIds = array_keys($identities);
        sort($actualIds);

        if ($actualIds !== $expectedIds) {
            throw new ResourceOperationException(
                'instance.registration_incomplete',
                'Registration relocation returned cleanup evidence for a different source set.',
                502,
            );
        }

        return $identities;
    }

    /**
     * Runs one relocation step and returns the identities that `prepare` reports.
     *
     * @param  list<array<string, mixed>>  $payload
     */
    private function runRelocation(Node $node, string $operation, array $payload): mixed
    {
        return $this->run($node, 'instance.relocate-registration-source', ['operation' => $operation, 'members' => $payload], step: 'registration-source-relocate', errorCode: 'instance.registration_incomplete')['identities'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function run(Node $node, string $command, array $input, string $step, string $errorCode): array
    {
        return AgentStep::run($this->agents, $node, $command, $input, $step, $errorCode, 'App development');
    }

    public function restoreOriginal(Instance $instance, RegistrationSourceFacts $facts): void
    {
        $destination = $instance->checkout_path;
        $instance->checkout_path = $facts->path;
        $reverse = new RegistrationSourceFacts(
            path: $destination,
            layout: $facts->layout,
            repositoryUrl: $facts->repositoryUrl,
            repositoryIdentity: $facts->repositoryIdentity,
            branch: $facts->branch,
            detached: $facts->detached,
            commit: $facts->commit,
            defaultBranch: $facts->defaultBranch,
            inferredSlug: $facts->inferredSlug,
            inferredRoot: $facts->inferredRoot,
            commonRepositoryPath: $facts->layout === InstanceSourceLayout::Checkout
                ? $destination.'/.git'
                : $facts->commonRepositoryPath,
            worktreePaths: array_map(
                static fn (string $path): string => $path === $facts->path ? $destination : $path,
                $facts->worktreePaths,
            ),
            sourceDigest: $facts->sourceDigest,
        );

        $this->relocate($instance, $reverse);
    }

    public function prepareLaravelRollback(Instance $instance): void
    {
        $this->runLaravelReceipt($instance, 'prepare');
    }

    public function restoreLaravelConfiguration(Instance $instance): void
    {
        $this->runLaravelReceipt($instance, 'restore');
    }

    public function discardLaravelRollback(Instance $instance): void
    {
        $this->runLaravelReceipt($instance, 'discard');
    }

    private function runLaravelReceipt(Instance $instance, string $operation): void
    {
        $instance->loadMissing('node');
        $this->run($instance->node, 'instance.laravel-registration-receipt', [
            'checkout' => $instance->checkout_path,
            'instance_id' => $instance->id,
            'operation' => $operation,
            'application' => $instance->applicationDirectory(),
        ], step: 'registration-laravel-rollback', errorCode: 'instance.laravel_rollback_failed');
    }

    /** @param array<array-key, mixed> $row */
    private function facts(array $row): RegistrationSourceFacts
    {
        $path = $this->requiredString($row, 'path');
        $layout = InstanceSourceLayout::tryFrom($this->requiredString($row, 'layout'));
        $repositoryUrl = GitRepositoryOrigin::validate($this->requiredString($row, 'repository_url'));
        $branch = is_string($row['branch'] ?? null) ? $row['branch'] : null;
        $defaultBranch = is_string($row['default_branch'] ?? null) ? $row['default_branch'] : null;
        $worktreePaths = $row['worktree_paths'] ?? null;

        if (
            ! $layout instanceof InstanceSourceLayout
            || $branch !== null
            && ! GitBranchName::isValid($branch)
            || $defaultBranch !== null
            && ! GitBranchName::isValid($defaultBranch)
            || ! is_bool($row['detached'] ?? null)
            || ! is_array($worktreePaths)
            || ! array_is_list($worktreePaths)
            || array_filter($worktreePaths, static fn (mixed $value): bool => ! is_string($value)) !== []
        ) {
            throw $this->invalidSource();
        }

        $validatedWorktreePaths = [];
        foreach ($worktreePaths as $worktreePath) {
            if (! is_string($worktreePath)) {
                throw $this->invalidSource();
            }
            $validatedWorktreePaths[] = $worktreePath;
        }

        $commit = $this->requiredString($row, 'commit');
        $digest = $this->requiredString($row, 'source_digest');

        if (
            preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $digest) !== 1
            || ($row['detached'] === true) !== ($branch === null)
        ) {
            throw $this->invalidSource();
        }

        return new RegistrationSourceFacts(
            path: $path,
            layout: $layout,
            repositoryUrl: $repositoryUrl,
            repositoryIdentity: GitRepositoryIdentity::derive($repositoryUrl),
            branch: $branch,
            detached: $row['detached'],
            commit: $commit,
            defaultBranch: $defaultBranch,
            inferredSlug: $this->requiredString($row, 'inferred_slug'),
            inferredRoot: is_string($row['inferred_root'] ?? null) ? $row['inferred_root'] : null,
            commonRepositoryPath: $this->requiredString($row, 'common_repository_path'),
            worktreePaths: $validatedWorktreePaths,
            sourceDigest: $digest,
        );
    }

    /** @param array<array-key, mixed> $row */
    private function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (! is_string($value) || $value === '' || preg_match('//u', $value) !== 1) {
            throw $this->invalidSource();
        }

        return $value;
    }

    private function account(Node $node): ManagedUserAccount
    {
        $account = $this->accounts->resolve($node);

        if ($account->user !== $node->user) {
            throw new ResourceOperationException(
                'instance.source_owner_invalid',
                'Registration requires the caller Node managed user.',
                409,
            );
        }

        return $account;
    }

    private function invalidSource(?\Throwable $previous = null): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'instance.source_invalid',
            message: 'The requested path is not a supported Git checkout or worktree.',
            status: 422,
            previous: $previous,
        );
    }
}
