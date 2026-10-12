<?php

declare(strict_types=1);

use App\Domain\Projects\CiCacheArchives;
use App\Domain\Projects\CiCacheSetup;
use App\Domain\Projects\CiCacheSource;
use App\Domain\Projects\GatewaySetupSteps;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\LifecycleSshExecutor;

pest()->group('subprocess');

const CI_SETUP_SHA = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

/** One CI artifact archive of a Composer project, as `ci.yml` uploads it. */
function ci_setup_archive(string $project): string
{
    $path = tempnam(sys_get_temp_dir(), 'ci-cache-test-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode(['schema' => 1, 'project' => $project], JSON_THROW_ON_ERROR));
    $zip->addFromString('graph.json', '{}');
    $zip->addFromString('quality/pint', random_bytes(300 * 1024));
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

/** @param  list<string>  $projects */
function ci_setup_source(array $projects): void
{
    app()->instance(CiCacheSource::class, new class(array_map(ci_setup_archive(...), $projects)) implements CiCacheSource
    {
        /** @param  list<string>  $archives */
        public function __construct(private readonly array $archives) {}

        public function fetch(Project $project): CiCacheArchives
        {
            return new CiCacheArchives(CI_SETUP_SHA, 9, $this->archives);
        }
    });
}

/**
 * A checkout with two Pest projects and a `bin/tia-cache` that records each import. It writes the project's graph and
 * exits 0, or exits with the code that `ci-exit-<project>` names.
 */
function ci_setup_checkout(string $root): void
{
    foreach (['apps/cli', 'packages/php-sdk'] as $project) {
        File::ensureDirectoryExists("{$root}/{$project}/tests");
        file_put_contents("{$root}/{$project}/tests/Pest.php", '<?php');
    }
    File::ensureDirectoryExists($root.'/bin');
    file_put_contents($root.'/bin/tia-cache', <<<'BASH'
        #!/usr/bin/env bash
        set -eu
        while [ $# -gt 0 ]; do
            case $1 in
                --project) project=$2; shift ;;
                --artifact) artifact=$2; shift ;;
                --commit) commit=$2; shift ;;
            esac
            shift
        done
        printf '%s %s %s %s\n' "$project" "$commit" "$(wc -c < "$artifact/quality/pint")" "$(cat "$artifact/graph.json")" >> imports.log
        code_file="ci-exit-${project//\//-}"
        if [ -f "$code_file" ]; then exit "$(cat "$code_file")"; fi
        mkdir -p "$project/.orbit-tia"
        cp "$artifact/graph.json" "$project/.orbit-tia/graph.json"
        BASH);
    chmod($root.'/bin/tia-cache', 0755);
}

describe('CI main cache setup', function (): void {
    beforeEach(function (): void {
        $this->checkout = sys_get_temp_dir().'/orbit-ci-cache-test-'.bin2hex(random_bytes(8));
        mkdir($this->checkout, 0700);
        ci_setup_checkout($this->checkout);
    });
    afterEach(function (): void {
        File::deleteDirectory($this->checkout);
    });

    it('imports each archive with bin/tia-cache and names a project left cold', function (): void {
        ci_setup_source(['apps/cli', 'packages/php-sdk']);
        file_put_contents($this->checkout.'/ci-exit-packages-php-sdk', '3');
        $command = app(CiCacheSetup::class)->command(new Project, 60);

        $process = new Process(['bash', '-c', $command], $this->checkout);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(file($this->checkout.'/imports.log', FILE_IGNORE_NEW_LINES))->toBe([
                'apps/cli '.CI_SETUP_SHA.' '.(300 * 1024).' {}',
                'packages/php-sdk '.CI_SETUP_SHA.' '.(300 * 1024).' {}',
            ])
            ->and($process->getOutput())->toBe("[packages/php-sdk] has no Pest TIA graph: its tests run cold.\n")
            ->and(strlen($command))->toBeGreaterThan(2 * 128 * 1024)
            ->and(substr($command, 0, 4096))->toContain('import_ci_caches()');
    });

    it('fails the step on any other import failure or without bin/tia-cache', function (bool $missing): void {
        ci_setup_source(['apps/cli', 'packages/php-sdk']);
        $missing ? unlink($this->checkout.'/bin/tia-cache') : file_put_contents($this->checkout.'/ci-exit-apps-cli', '1');

        $process = new Process(['bash', '-c', app(CiCacheSetup::class)->command(new Project, 60)], $this->checkout);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and(file_exists($this->checkout.'/packages/php-sdk/.orbit-tia/graph.json'))->toBeFalse();
    })->with(['an import failure' => false, 'no bin/tia-cache' => true]);

    it('runs as a Gateway-owned Instance setup step and only in setup', function (): void {
        ci_setup_source(['apps/cli']);
        $project = Project::query()->create(['name' => 'CI', 'slug' => 'ci', 'repository_url' => 'https://github.com/acme/orbit.git']);
        $node = Node::query()->create(['name' => 'ci', 'wireguard_ip' => '192.0.2.9', 'public_ssh_host' => '192.0.2.9']);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'ci', 'checkout_path' => $this->checkout, 'status' => 'active']);
        $store = new ProjectLifecycleStepStore;
        $store->create($project, LifecyclePhase::Setup, new LifecycleStep('ci-caches', LifecycleStep::ImportCiCaches, 60), null, null);
        $store->create($project, LifecyclePhase::Teardown, new LifecycleStep('ci-caches', LifecycleStep::ImportCiCaches, 60), null, null);
        $transport = new LifecycleSshExecutor(local: true);

        expect($transport->runner()->run($instance, LifecyclePhase::Setup))->toBeTrue()
            ->and(file_get_contents($this->checkout.'/apps/cli/.orbit-tia/graph.json'))->toBe('{}')
            ->and(fn () => $transport->runner()->run($instance, LifecyclePhase::Teardown))->toThrow(ResourceOperationException::class, 'setup-only');
    });

    it('names its step when the caches are missing', function (): void {
        app()->instance(CiCacheSource::class, new class implements CiCacheSource
        {
            public function fetch(Project $project): CiCacheArchives
            {
                throw new ResourceOperationException('instance.ci_caches_missing', 'No caches.', 422);
            }
        });
        $project = Project::query()->create(['name' => 'CI', 'slug' => 'ci', 'repository_url' => 'https://github.com/acme/orbit.git']);
        $node = Node::query()->create(['name' => 'ci', 'wireguard_ip' => '192.0.2.9', 'public_ssh_host' => '192.0.2.9']);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'ci', 'checkout_path' => $this->checkout, 'status' => 'active']);
        (new ProjectLifecycleStepStore)->create($project, LifecyclePhase::Setup, new LifecycleStep('ci-caches', LifecycleStep::ImportCiCaches, 60), null, null);

        expect(fn () => (new LifecycleSshExecutor(local: true))->runner()->run($instance, LifecyclePhase::Setup))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.ci_caches_missing')
                ->and($exception->details)->toBe(['step' => 'ci-caches']));
        expect(GatewaySetupSteps::owns(LifecycleStep::ImportCiCaches))->toBeTrue()
            ->and(GatewaySetupSteps::owns('bin/tia-cache import-ci'))->toBeFalse();
    });
});
