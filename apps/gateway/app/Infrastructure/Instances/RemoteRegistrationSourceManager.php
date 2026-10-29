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
use App\Models\Node;

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

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function run(Node $node, string $command, array $input, string $step, string $errorCode): array
    {
        return AgentStep::run($this->agents, $node, $command, $input, $step, $errorCode, 'App development');
    }

    /** @param array<array-key,mixed> $row */
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
