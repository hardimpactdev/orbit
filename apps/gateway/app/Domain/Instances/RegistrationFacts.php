<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Models\Instance;
use App\Support\ValidatedData;

/**
 * The checks and the activation of a registered checkout, shared by the record-only path, which reads the Node
 * agent's `checkout` fact, and the native run's projection, which verifies the requested effects.
 *
 * @phpstan-type Facts array{registered:true,branch:?string,commit:string,detached:bool,digest:?string,common:string,default_branch:?string,inferred_slug:string,php_version:?string,laravel:bool}
 */
final readonly class RegistrationFacts
{
    /**
     * The facts of one checkout of the Project's repository, from the agent's `checkout` fact.
     *
     * @param  array<string, mixed>  $fact
     * @return Facts
     */
    public static function fromAgent(array $fact, string $checkout, ?string $identity, ProjectType $type, string $user): array
    {
        $common = ValidatedData::nullableString($fact['common_dir'] ?? null);
        $trees = $fact['worktrees'] ?? null;
        if (($fact['top_level'] ?? null) !== $checkout || $common === null || StoragePath::tryParse($common) === null
            || basename($common) !== '.git' || ($fact['linked'] ?? null) !== ($common !== $checkout.'/.git')
            || ! is_array($trees) || ! in_array($checkout, $trees, true)) {
            throw new ResourceOperationException('instance.registration_conflict', 'The path is not a checkout of its reported shared repository.', 409);
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
            'common' => $common, 'default_branch' => ValidatedData::nullableString($fact['default_branch'] ?? null),
            'inferred_slug' => self::slug($origin),
            ...self::profile($artisan, ValidatedData::nullableString($fact['composer'] ?? null), $type)];
    }

    /**
     * Restore the typed facts frozen at admission, without reading source again.
     *
     * @param  array<string,mixed>  $facts
     * @return Facts
     */
    public static function recorded(array $facts): array
    {
        if (($facts['registered'] ?? null) !== true || ! is_bool($facts['detached'] ?? null) || ! is_bool($facts['laravel'] ?? null)) {
            throw new ResourceOperationException('operation.result_invalid', 'The recorded checkout facts are invalid.', 422);
        }

        return ['registered' => true, 'branch' => ValidatedData::nullableString($facts['branch']),
            'commit' => ValidatedData::string($facts['commit']), 'detached' => $facts['detached'],
            'digest' => ValidatedData::nullableString($facts['digest']), 'common' => ValidatedData::string($facts['common']),
            'default_branch' => ValidatedData::nullableString($facts['default_branch']),
            'inferred_slug' => ValidatedData::string($facts['inferred_slug']),
            'php_version' => ValidatedData::nullableString($facts['php_version']), 'laravel' => $facts['laravel']];
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
    public static function activate(Instance $instance, array $facts): void
    {
        self::record($instance, $facts);
        $instance->update(['status' => InstanceState::Active, 'provisioning_step' => 'active',
            'registration_completed_at' => now(), 'failed_step' => null, 'error_code' => null]);
    }

    /** @param Facts $facts */
    public static function record(Instance $instance, array $facts): void
    {
        $instance->update([
            'source_layout' => $facts['common'] === $instance->checkout_path.'/.git' ? 'checkout' : 'worktree',
            'branch' => $facts['branch'], 'branch_override' => $facts['branch'],
            'starting_commit' => $facts['commit'],
            'selected_php_version' => $facts['php_version'],
            'source_is_laravel' => $facts['laravel'],
            'registration_source_digest' => $facts['digest'],
            'registration_detached' => $facts['detached'],
            'registration_common_repository_path' => $facts['common'],
        ]);
    }
}
