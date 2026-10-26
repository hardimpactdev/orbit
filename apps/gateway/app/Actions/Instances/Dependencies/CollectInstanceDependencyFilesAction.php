<?php

declare(strict_types=1);

namespace App\Actions\Instances\Dependencies;

use App\Domain\Instances\Dependencies\CollectedDependencyFiles;
use App\Domain\Instances\Dependencies\DependencyCollectionException;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Projects\ProjectType;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\Project;
use Throwable;

/**
 * Reads the dependency manifests and lockfiles of an Instance through the Node agent: a development checkout with
 * `instance.read-dependency-files`, and a production home's current release with
 * `instance.read-release-dependency-files` (docs/reference/instance-dependency-contracts.md). It checks that the
 * answer names the recorded source.
 */
final readonly class CollectInstanceDependencyFilesAction
{
    /** The files an answer holds, in the order the agent reads them. */
    public const array FILES = ['composer.json', 'composer.lock', 'package.json', 'npm-shrinkwrap.json', 'package-lock.json', 'pnpm-lock.yaml', 'bun.lock', 'bun.lockb', 'yarn.lock', 'pnpm-workspace.yaml', '.yarnrc.yml', '.pnpmfile.cjs', 'pnpmfile.cjs', 'bunfig.toml', 'yarn.config.cjs'];

    private const array ERRORS = ['dependencies.source_unavailable', 'dependencies.unsafe_source', 'dependencies.unreadable_source', 'dependencies.source_changed', 'dependencies.source_too_large'];

    public function __construct(private AgentCommandRunner $agents) {}

    public function execute(Instance $instance, bool $discover = true): CollectedDependencyFiles
    {
        $node = $instance->node;
        $production = $instance->placedOnAppProd();
        $path = $production ? $instance->production_home : $instance->checkout_path;
        $user = $production ? $instance->production_user : $node->user;
        if ((! $instance->placedOnAppDev() && ! $production)
            || ! in_array($instance->source_layout, array_column(InstanceSourceLayout::cases(), 'value'), true)
            || ! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, "\0")
            || ! is_string($user) || preg_match('/\A[a-z_][a-z0-9_-]*\z/D', $user) !== 1
            || ($production && $path !== '/home/'.$user)) {
            throw new DependencyCollectionException('dependencies.unsafe_source');
        }

        $base = $production ? $path.'/current' : $path;
        $application = substr($instance->dependencyDirectory(), strlen($base));
        $project = $instance->getRelationValue('project');
        $monorepo = $discover && $project instanceof Project && $project->type === ProjectType::Monorepo;

        try {
            // In monorepo mode the application selects only the no-top-level-directories fallback.
            $receipt = $this->agents->run($node, $production ? 'instance.read-release-dependency-files' : 'instance.read-dependency-files', [
                'path' => $path,
                'application' => $application,
                'monorepo' => $monorepo,
            ]);
        } catch (Throwable) {
            throw new DependencyCollectionException('dependencies.unreadable_source');
        }

        if (isset($receipt['error']) && in_array($receipt['error'], self::ERRORS, true)) {
            throw new DependencyCollectionException($receipt['error']);
        }
        $total = 0;
        $sourceApplication = $monorepo && ($receipt['sub_projects'] ?? null) !== null ? '' : $application;
        $collected = $this->decode($receipt, $production, $path, $sourceApplication, $total);
        if (! $monorepo || ! array_key_exists('sub_projects', $receipt) || $receipt['sub_projects'] === null) {
            return $collected;
        }
        if (! is_array($receipt['sub_projects'])) {
            throw new DependencyCollectionException('dependencies.invalid_collection');
        }
        $projects = [];
        foreach ($receipt['sub_projects'] as $relative => $source) {
            if (! is_string($relative) || preg_match('/\A(?:apps|packages)\/[^\/\x00]+\z/D', $relative) !== 1
                || in_array(substr($relative, strpos($relative, '/') + 1), ['.', '..'], true)
                || ! is_array($source) || ($source['reference'] ?? null) !== $collected->reference
                || ($source['identity'] ?? null) !== $collected->identity) {
                throw new DependencyCollectionException('dependencies.invalid_collection');
            }
            $projects[$relative] = $this->decode($source, $production, $path, '/'.$relative, $total);
        }

        return new CollectedDependencyFiles($collected->projectRoot, $collected->reference, $collected->identity, $collected->contents, $collected->hashes, $collected->errors, $projects);
    }

    /** @param array<string, mixed> $receipt */
    private function decode(array $receipt, bool $production, string $path, string $application, int &$total): CollectedDependencyFiles
    {
        $root = $receipt['root'] ?? null;
        $reference = $receipt['reference'] ?? null;
        $identity = $receipt['identity'] ?? null;
        $files = $receipt['files'] ?? null;
        if (! is_string($root) || ! is_string($identity) || preg_match('/\A[a-f0-9]{64}\z/D', $identity) !== 1
            || ! is_array($files) || array_keys($files) !== self::FILES
            || ($production && (! is_string($reference) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $reference) !== 1 || $root !== $path.'/releases/'.$reference.$application))
            || (! $production && ($root !== $path.$application || $reference !== null))) {
            throw new DependencyCollectionException('dependencies.invalid_collection');
        }
        $contents = $hashes = $errors = [];
        foreach ($files as $name => $entry) {
            if (! is_array($entry) || array_keys($entry) !== ['content', 'hash', 'error']) {
                throw new DependencyCollectionException('dependencies.invalid_collection');
            }
            $content = $entry['content'];
            $hash = $entry['hash'];
            $error = $entry['error'];
            if ($error !== null) {
                if (! in_array($error, self::ERRORS, true) || $content !== null || $hash !== null) {
                    throw new DependencyCollectionException('dependencies.invalid_collection');
                }
                $errors[$name] = $error;
            } elseif ($content !== null || $hash !== null) {
                if (! is_string($content) || ! is_string($hash) || ($content = base64_decode($content, true)) === false
                    || ! hash_equals(hash('sha256', $content), $hash)
                    || strlen($content) > (in_array($name, ['composer.json', 'package.json'], true) ? 1024 * 1024 : 8 * 1024 * 1024)) {
                    throw new DependencyCollectionException('dependencies.invalid_collection');
                }
                $total += strlen($content);
            }
            $contents[$name] = $content;
            $hashes[$name] = $hash;
        }
        if ($total > 32 * 1024 * 1024) {
            throw new DependencyCollectionException('dependencies.source_too_large');
        }

        return new CollectedDependencyFiles($root, $reference, $identity, $contents, $hashes, $errors);
    }
}
