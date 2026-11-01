<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\MachineSoftware\VitePlusRuntimeLock;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Node;

/** Public, immutable recipe input for the existing named materialization commands. */
final readonly class ToolManagerIntent
{
    public function __construct(private VpToolManager $vp) {}

    /** @return array<string, mixed>|null */
    public function nativeInput(Node $node, ToolManagerName $manager, bool $imageProvidesManager = false): ?array
    {
        if ($node->platform !== 'linux' || $imageProvidesManager || $manager === ToolManagerName::Apt) {
            return null;
        }
        $recipe = new RecipeLoader()->source();
        if ($manager === ToolManagerName::Vp) {
            $tools = [];
            foreach (['node', 'vp-cli', 'vp', 'pnpm'] as $name) {
                $pin = $recipe->tools[$name]->forArchitecture('amd64');
                $artifact = $pin->artifacts['amd64'];
                $tools[$name] = ['version' => $pin->version, 'url' => $artifact->location,
                    'algorithm' => $artifact->algorithm, 'digest' => $artifact->digest,
                    ...($pin->lockSha256 === null ? [] : ['lock_sha256' => $pin->lockSha256])];
            }

            return ['manager' => 'javascript', 'packages' => [], 'input' => ['user' => $node->user,
                'tools' => $tools, 'vp_lock' => VitePlusRuntimeLock::read($recipe->tools['vp'])], 'apt' => null];
        }
        if ($manager === ToolManagerName::Composer) {
            $packages = [];
            foreach (['composer', ...$recipe->gitPackages, 'unzip'] as $name) {
                $pin = $recipe->tools[$name]->forArchitecture('amd64');
                $artifact = $pin->artifacts['amd64'] ?? null;
                $packages[] = ['name' => $name, 'version' => $pin->version,
                    'artifact' => $artifact === null ? null : ['urls' => $artifact->candidateUrls($name, $recipe->artifactMirror), 'algorithm' => $artifact->algorithm, 'digest' => $artifact->digest]];
            }

            return ['manager' => 'composer', 'packages' => [], 'input' => ['user' => $node->user, 'version' => $recipe->tools['composer']->version],
                'apt' => ['snapshot' => $recipe->snapshot, 'packages' => $packages]];
        }

        return ['manager' => 'homebrew', 'packages' => ['build-essential', 'procps', 'curl', 'file', 'git', 'ca-certificates'],
            'apt' => null, 'input' => ['user' => $node->user, 'revision' => 'd79ef822ab8136e393ed5f86e2b56afc68d04874', 'version' => '7.0.0']];
    }

    /** @return array<string, mixed> */
    public function input(Node $node, ToolManagerName $manager, bool $imageProvidesManager = false): array
    {
        if ($node->platform !== 'linux' || $imageProvidesManager) {
            return [];
        }
        $command = match ($manager) {
            ToolManagerName::Vp => 'tool.materialize-vp',
            ToolManagerName::Composer => 'tool.materialize-composer',
            ToolManagerName::Brew => 'tool.materialize-homebrew',
            default => null,
        };
        if ($command === null) {
            return [];
        }
        $input = $manager === ToolManagerName::Vp ? $this->vp->materializeInput($node) : ['user' => $node->user];

        return ['materialize' => $input];
    }
}
