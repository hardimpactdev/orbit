<?php

declare(strict_types=1);

use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\Node;
use Tests\TestCase;

uses(TestCase::class);

it('sends each Composer artifact package its mirror-first candidate URLs', function (): void {
    $recipe = new RecipeLoader()->source();
    $setup = app(ToolManagerIntent::class)->nativeInput(new Node(['platform' => 'linux', 'user' => 'orbit']), ToolManagerName::Composer);

    $artifacts = array_filter(array_column($setup['apt']['packages'], 'artifact', 'name'));
    expect(array_keys($artifacts))->toBe($recipe->gitPackages);
    foreach ($artifacts as $name => $artifact) {
        $pin = $recipe->tools[$name]->artifacts['amd64'];
        expect($artifact)->toBe(['urls' => $pin->candidateUrls($name, $recipe->artifactMirror), 'algorithm' => $pin->algorithm, 'digest' => $pin->digest])
            ->and($artifact['urls'][0])->toBe($recipe->artifactMirror.'/'.$name.'_'.$pin->digest.'.deb')
            ->and(end($artifact['urls']))->toBe($pin->location);
    }
});
