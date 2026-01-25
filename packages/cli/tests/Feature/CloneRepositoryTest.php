<?php

use App\Actions\Provision\CloneRepository;
use App\Data\Provision\ProvisionContext;
use App\Services\ProvisionLogger;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->testPath = sys_get_temp_dir().'/orbit-tests/clone-test-'.time();
    $this->logger = new ProvisionLogger(slug: 'clone-test');
});

afterEach(function () {
    if (is_dir($this->testPath)) {
        deleteDirectory($this->testPath);
    }
});

it('fails when no clone URL provided', function () {
    $context = new ProvisionContext(
        slug: 'test-project',
        projectPath: $this->testPath,
        cloneUrl: null,
    );

    $action = new CloneRepository;
    $result = $action->handle($context, $this->logger);

    expect($result->isFailed())->toBeTrue();
    expect($result->error)->toContain('No clone URL provided');
});

it('fails when directory is not empty', function () {
    // Create non-empty directory
    mkdir($this->testPath, 0755, true);
    file_put_contents("{$this->testPath}/existing-file.txt", 'content');

    $context = new ProvisionContext(
        slug: 'test-project',
        projectPath: $this->testPath,
        cloneUrl: 'owner/repo',
    );

    $action = new CloneRepository;
    $result = $action->handle($context, $this->logger);

    expect($result->isFailed())->toBeTrue();
    expect($result->error)->toContain('not empty');
});

it('removes empty placeholder directory before cloning', function () {
    // Create empty directory
    mkdir($this->testPath, 0755, true);

    Process::fake([
        'gh repo clone *' => Process::result(output: 'Cloning into...'),
    ]);

    $context = new ProvisionContext(
        slug: 'test-project',
        projectPath: $this->testPath,
        cloneUrl: 'owner/repo',
    );

    $action = new CloneRepository;
    $result = $action->handle($context, $this->logger);

    Process::assertRan(fn ($process) => str_contains($process->command, 'gh repo clone'));
});

it('uses gh repo clone command with owner/repo format', function () {
    Process::fake([
        'gh repo clone *' => Process::result(output: 'Cloning into...'),
    ]);

    $context = new ProvisionContext(
        slug: 'test-project',
        projectPath: $this->testPath,
        cloneUrl: 'hardimpactdev/liftoff-starterkit',
    );

    $action = new CloneRepository;
    $result = $action->handle($context, $this->logger);

    expect($result->isSuccess())->toBeTrue();

    Process::assertRan(function ($process) {
        return str_contains($process->command, 'gh repo clone')
            && str_contains($process->command, 'hardimpactdev/liftoff-starterkit');
    });
});

it('returns failure when gh clone fails', function () {
    Process::fake([
        'gh repo clone *' => Process::result(
            exitCode: 1,
            errorOutput: 'repository not found',
        ),
    ]);

    $context = new ProvisionContext(
        slug: 'test-project',
        projectPath: $this->testPath,
        cloneUrl: 'nonexistent/repo',
    );

    $action = new CloneRepository;
    $result = $action->handle($context, $this->logger);

    expect($result->isFailed())->toBeTrue();
    expect($result->error)->toContain('repository not found');
});
