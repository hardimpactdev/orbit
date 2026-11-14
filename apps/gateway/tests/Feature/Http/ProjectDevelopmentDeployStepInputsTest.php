<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\DevelopmentDeploySteps;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Projects\ProjectDevelopmentDeployStepStore;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectDevelopmentDeployStep;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../Domain/Instances/ContainerInstanceHelpers.php';

/** @return array{Project, Node} */
function deployStepInputsFixture(): array
{
    Http::preventStrayRequests();
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/srv/tool/default', 'status' => 'active']);
    $node->accessibleNodes()->attach($node->id);

    return [$project, $node];
}

describe('development deploy step inputs', function (): void {
    it('creates a step with inputs, lists them, and lists an empty list for a step without', function (): void {
        [$project, $node] = deployStepInputsFixture();
        $url = "/api/v1/projects/{$project->id}/dev-deploy-steps";
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        $this->postJson($url, ['name' => 'install', 'command' => 'composer install', 'inputs' => ['composer.json', 'composer.lock', 'apps/web/']])
            ->assertCreated()->assertJsonPath('data.inputs', ['composer.json', 'composer.lock', 'apps/web/']);
        $this->postJson($url, ['name' => 'migrate', 'command' => 'php artisan migrate'])
            ->assertCreated()->assertJsonPath('data.inputs', []);
        $this->postJson($url, ['name' => 'cache', 'command' => 'php artisan optimize', 'inputs' => null])
            ->assertCreated()->assertJsonPath('data.inputs', []);

        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.0.inputs', ['composer.json', 'composer.lock', 'apps/web/'])
            ->assertJsonPath('data.1.inputs', [])
            ->assertJsonPath('data.2.inputs', []);
        expect(ProjectDevelopmentDeployStep::query()->where('name', 'migrate')->sole()->inputs)->toBeNull();
    });

    it('replaces inputs on update, keeps them when absent, and clears them with null or an empty list', function (mixed $clear): void {
        [$project, $node] = deployStepInputsFixture();
        $url = "/api/v1/projects/{$project->id}/dev-deploy-steps";
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
        $this->postJson($url, ['name' => 'install', 'command' => 'bun install', 'inputs' => ['package.json']])->assertCreated();

        $this->patchJson("{$url}/install", ['inputs' => ['bun.lock', 'package.json']])
            ->assertOk()->assertJsonPath('data.inputs', ['bun.lock', 'package.json']);
        $this->patchJson("{$url}/install", ['timeout_seconds' => 60])
            ->assertOk()->assertJsonPath('data.inputs', ['bun.lock', 'package.json']);
        $this->patchJson("{$url}/install", ['inputs' => $clear])
            ->assertOk()->assertJsonPath('data.inputs', [])->assertJsonPath('data.timeout_seconds', 60);

        expect(ProjectDevelopmentDeployStep::query()->where('name', 'install')->sole()->inputs)->toBeNull();
    })->with(['null' => [null], 'empty list' => [[]]]);

    it('refuses invalid inputs on create and update without changing the list', function (mixed $inputs): void {
        [$project, $node] = deployStepInputsFixture();
        $url = "/api/v1/projects/{$project->id}/dev-deploy-steps";
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
        $this->postJson($url, ['name' => 'install', 'command' => 'bun install', 'inputs' => ['package.json']])->assertCreated();

        $this->postJson($url, ['name' => 'build', 'command' => 'bun run build', 'inputs' => $inputs])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['inputs']]]);
        $this->patchJson("{$url}/install", ['inputs' => $inputs])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['inputs']]]);

        expect(ProjectDevelopmentDeployStep::query()->pluck('name')->all())->toBe(['install'])
            ->and(ProjectDevelopmentDeployStep::query()->sole()->inputs)->toBe(['package.json']);
    })->with([
        'a string' => ['composer.json'],
        'an object' => [['a' => 'composer.json']],
        'a non-string item' => [[1]],
        'an empty path' => [['']],
        'a long path' => [[str_repeat('a', 256)]],
        'an absolute path' => [['/etc/passwd']],
        'a parent segment' => [['apps/../secrets']],
        'a lone parent segment' => [['..']],
        'a NUL byte' => [["composer\0.json"]],
        // A line break would split the list the deploy run reads into lines, and pair a path with another path's object.
        'a line feed' => [["composer.json\ncomposer.lock"]],
        'a carriage return' => [["composer.json\r"]],
        'a delete character' => [["composer\x7f.json"]],
        'a line separator' => [["composer\u{2028}.json"]],
        'a star' => [['*.lock']],
        'a question mark' => [['composer.?son']],
        'a bracket' => [['composer.[jl]']],
        'a duplicate' => [['composer.json', 'composer.json']],
        'seventeen paths' => [array_map(static fn (int $i): string => "file-{$i}", range(1, 17))],
    ]);

    it('accepts sixteen paths of 255 characters with dots that are not a parent segment', function (): void {
        $inputs = [str_repeat('a', 255), '.env.example', 'a..b/c', ...array_map(static fn (int $i): string => "file-{$i}", range(1, 13))];
        expect(new DevelopmentDeployStep('install', 'true', inputs: $inputs)->inputs)->toBe($inputs);
    });

    it('keeps inputs through a store update of another field and through reordering', function (): void {
        [$project] = deployStepInputsFixture();
        $store = app(ProjectDevelopmentDeployStepStore::class);
        $store->create($project, new DevelopmentDeployStep('install', 'bun install', inputs: ['bun.lock']), null, null);
        $store->create($project, new DevelopmentDeployStep('build', 'bun run build'), null, null);

        $store->update($project, 'install', 'bun install --frozen-lockfile', null, null, 'build', true, false);

        expect(array_map(static fn (DevelopmentDeployStep $step): array => [$step->name, $step->inputs], $store->ordered($project)))
            ->toBe([['build', []], ['install', ['bun.lock']]]);
    });

    it('freezes each step\'s inputs and command digest into the deployment intent', function (): void {
        [$project] = deployStepInputsFixture();
        $store = app(ProjectDevelopmentDeployStepStore::class);
        $store->create($project, new DevelopmentDeployStep('install', 'composer install', inputs: ['composer.json', 'composer.lock']), null, null);
        $store->create($project, new DevelopmentDeployStep('migrate', 'php artisan migrate', 60, false), null, null);

        expect(app(DevelopmentDeploySteps::class)->capture($project))->toBe([
            ['name' => 'install', 'phase' => 'before_activation', 'required' => true, 'timeout_seconds' => 300,
                'command_digest' => hash('sha256', 'composer install'), 'inputs' => ['composer.json', 'composer.lock']],
            ['name' => 'migrate', 'phase' => 'before_activation', 'required' => false, 'timeout_seconds' => 60,
                'command_digest' => hash('sha256', 'php artisan migrate'), 'inputs' => []],
        ]);
    });
});
