<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\AgentCaddyPackage;

/** Public recipe input for the native Caddy installer; the frozen agent contract stays separate. */
final readonly class NativeCaddyPackageInput
{
    /** @return array{url:string,urls:list<string>,path:string,sha256:string,minimum_version:string} */
    public static function make(): array
    {
        $recipe = new RecipeLoader()->source();
        $urls = $recipe->tools['caddy']->artifacts['amd64']->candidateUrls('caddy', $recipe->artifactMirror);
        $file = 'caddy_'.AgentCaddyPackage::RELEASE.'_linux_amd64.deb';

        return ['url' => $urls[0], 'urls' => $urls,
            'path' => '/var/cache/apt/archives/'.$file, 'sha256' => AgentCaddyPackage::AMD64_SHA256, 'minimum_version' => CaddyRelease::MINIMUM];
    }
}
