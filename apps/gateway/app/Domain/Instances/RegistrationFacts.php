<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Models\Instance;
use App\Support\ValidatedData;

/**
 * The checks and the activation of a registered checkout, shared by the record-only path, which reads the Node
 * agent's `checkout` fact, and the native run's projection, which reads the run's output.
 *
 * @phpstan-type Facts array{registered:true,branch:?string,commit:string,detached:bool,digest:?string,common:string,default_branch:?string,inferred_slug:string,php_version:?string,laravel:bool}
 */
final readonly class RegistrationFacts
{
    /**
     * The facts of one plain checkout of the Project's repository, from the agent's `checkout` fact.
     *
     * @param  array<string, mixed>  $fact
     * @return Facts
     */
    public static function fromAgent(array $fact, string $checkout, ?string $identity, ProjectType $type, string $user): array
    {
        if (($fact['top_level'] ?? null) !== $checkout || ($fact['common_dir'] ?? null) !== $checkout.'/.git'
            || ($fact['linked'] ?? null) !== false || ($fact['worktrees'] ?? null) !== [$checkout]) {
            throw new ResourceOperationException('instance.registration_conflict', 'The path is not one plain checkout.', 409);
        }
        $origin = ValidatedData::nullableString($fact['origin'] ?? null) ?? '';
        if (self::identity($origin) !== $identity) {
            throw new ResourceOperationException('instance.source_identity_invalid', 'The checkout origin is not the Project repository.', 409);
        }
        if (($fact['owner'] ?? null) !== $user) {
            throw new ResourceOperationException('instance.source_owner_invalid', 'The checkout does not belong to the Node account.', 409);
        }
        $commit = ValidatedData::nullableString($fact['head'] ?? null);
        if ($commit === null) {
            throw new ResourceOperationException('instance.source_invalid', 'The checkout has no commit.', 422);
        }
        $branch = ValidatedData::nullableString($fact['branch'] ?? null);
        // The agent names a symlinked entry point `symlink`; the classifier calls it `unsafe`.
        $artisan = match ($fact['artisan'] ?? null) {
            'regular' => 'regular',
            'symlink' => 'unsafe',
            default => 'absent',
        };

        return ['registered' => true, 'branch' => $branch, 'commit' => $commit, 'detached' => $branch === null, 'digest' => null,
            'common' => $checkout.'/.git', 'default_branch' => ValidatedData::nullableString($fact['default_branch'] ?? null),
            'inferred_slug' => self::slug($origin),
            ...self::profile($artisan, ValidatedData::nullableString($fact['composer'] ?? null), $type)];
    }

    /** The repository identity of an origin; a masked or unparseable origin has none. */
    public static function identity(string $origin): ?string
    {
        try {
            return str_contains($origin, 'REDACTED@') ? null : GitRepositoryIdentity::derive($origin);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public static function slug(string $origin): string
    {
        $segments = preg_split('#[/:]#', rtrim($origin, '/')) ?: [''];

        return (string) preg_replace(['/\.git\z/', '/[^a-z0-9-]+/', '/\A-|-\z/'], ['', '-', ''], strtolower(end($segments)));
    }

    /**
     * The PHP version and Laravel marker from the entry point kind and `composer.json`; a monorepo or a source without
     * `composer.json` has none.
     *
     * @return array{php_version:?string,laravel:bool}
     */
    public static function profile(?string $entryPoint, ?string $composer, ProjectType $type): array
    {
        if ($type === ProjectType::Monorepo || $entryPoint === null || $composer === null || trim($composer) === '') {
            return ['php_version' => null, 'laravel' => false];
        }
        if (! in_array($entryPoint, ['unsafe', 'regular', 'absent'], true) || strlen($composer) > 1048576) {
            throw new ResourceOperationException('app-dev.source_metadata_unsafe', 'The checkout metadata is unsafe.', 422);
        }
        try {
            $profile = app(ComposerSourceClassifier::class)->classify($composer, $type, $entryPoint);
        } catch (RuntimeConvergenceException $exception) {
            throw new ResourceOperationException($exception->errorCode, 'The development source metadata is invalid or unsupported.', 422);
        }

        return ['php_version' => $profile->phpVersion, 'laravel' => $profile->laravel];
    }

    /**
     * Records the checked facts on the Instance and makes it active.
     *
     * @param  Facts  $facts
     */
    public static function activate(Instance $instance, array $facts, string $repository, ?string $identity): void
    {
        $instance->update([
            'status' => InstanceState::Active, 'provisioning_step' => 'active',
            'branch' => $facts['branch'], 'branch_override' => $facts['branch'],
            'starting_commit' => $facts['commit'],
            'selected_php_version' => $facts['php_version'],
            'source_is_laravel' => $facts['laravel'],
            'registration_repository_url' => $repository, 'registration_repository_identity' => $identity,
            'registration_source_digest' => $facts['digest'],
            'registration_detached' => $facts['detached'],
            'registration_default_branch' => $facts['default_branch'],
            'registration_inferred_slug' => $facts['inferred_slug'],
            'registration_common_repository_path' => $facts['common'], 'registration_worktree_paths' => [$instance->checkout_path],
            'registration_relocation_state' => 'relocated', 'registration_completed_at' => now(), 'failed_step' => null, 'error_code' => null,
        ]);
    }
}
