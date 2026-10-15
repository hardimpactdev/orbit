<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Models\Node;
use InvalidArgumentException;

/**
 * Runs `php.install-build`, the one installer of the recipe's PHP build (ADR 0215), on a Node. Recipe convergence and
 * the PHP step of an Instance call it; the image build and bin/php-build run the same program with the same input.
 */
final readonly class PhpBuildInstaller
{
    public const string Program = 'php.install-build';

    /** Outcomes after which the Node runs the recipe build. */
    public const array Succeeded = ['planned', 'current', 'applied'];

    public function __construct(
        private RemoteProgramRunner $runner,
        private RemoteProgramCatalog $catalog,
    ) {}

    /**
     * @param  list<string>  $versions
     * @param  'plan'|'apply'  $mode
     * @param  'refuse'|'keep'|'purge'  $extras  `refuse` for recipe convergence, `keep` for an Instance step, which never
     *                                           removes a site's extension, and `purge` only for the ci-runner profile.
     * @param  'none'|'shared'|'all'  $fpm  Which active PHP-FPM masters restart after a change. `shared` defers a
     *                                      service whose pools fail `php-fpm -t`; `all` rolls back instead.
     */
    public function run(Node $node, MachineRecipe $recipe, array $versions, string $mode, bool $pcov, string $extras, string $fpm): ProgramResult
    {
        return $this->runner->run($node, $this->catalog->program(self::Program), self::input($recipe, $versions, $mode, $pcov, $extras, $fpm));
    }

    /**
     * @param  list<string>  $versions
     * @param  array{id: string, codenames: list<string>}|null  $release  The operating systems the caller supports.
     * @return array<string, mixed>
     */
    public static function input(MachineRecipe $recipe, array $versions, string $mode, bool $pcov, string $extras, string $fpm, ?array $release = null): array
    {
        if (! in_array($mode, ['plan', 'apply'], true) || ! in_array($extras, ['refuse', 'keep', 'purge'], true) || ! in_array($fpm, ['none', 'shared', 'all'], true)) {
            throw new InvalidArgumentException('Invalid PHP build installer options.');
        }
        $sets = [];
        foreach (array_values(array_unique($versions)) as $version) {
            $build = $recipe->php($version);
            $packages = [];
            foreach ($build->packages as $name) {
                $pin = $recipe->tools[$name];
                foreach ($pin->artifacts as $architecture => $artifact) {
                    $packages[] = ['name' => $name, 'version' => $pin->version, 'architecture' => $architecture, 'url' => $artifact->location, 'sha256' => $artifact->digest];
                }
            }
            $sets[$version] = ['packages' => $packages, 'extensions' => $build->extensions];
        }
        $rollback = $recipe->tools[$recipe->phpPolicy->rollbackTool] ?? null;

        return [
            'mode' => $mode,
            'versions' => $sets,
            'mirror' => $recipe->phpPolicy->mirror,
            'pcov' => $pcov,
            'extras' => $extras,
            'fpm' => $fpm,
            'repack' => ['package' => $recipe->phpPolicy->rollbackTool, 'version' => $rollback->version ?? ''],
            'release' => $release,
        ];
    }

    /** A one-line explanation of a refusal, for an operator. */
    public static function describe(ProgramResult $result): string
    {
        $data = $result->data;
        $list = static fn (string $key): string => implode(', ', array_map(strval(...), is_array($data[$key] ?? null) ? array_filter($data[$key], is_scalar(...)) : []));

        return match ($result->outcome) {
            'extra_packages' => 'PHP packages outside the recipe are installed: '.$list('packages').'. Remove them, then retry.',
            'conflicting_source' => 'Another PHP apt source is configured: '.$list('sources').'. Remove it, then retry.',
            'modules_mismatch' => 'The PHP CLI loads other extensions than the recipe. Missing: ['.$list('missing').']. Extra: ['.$list('extra').'].',
            default => "The PHP build installer ended with [{$result->outcome}]".(is_string($data['reason'] ?? null) ? ": {$data['reason']}" : '').(is_string($data['package'] ?? null) ? " ({$data['package']})" : '').(is_string($data['unit'] ?? null) ? " on {$data['unit']}" : '').'.',
        };
    }
}
