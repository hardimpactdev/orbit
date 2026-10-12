<?php

declare(strict_types=1);

use App\Actions\Instances\DeployDefaultInstanceAction;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use Tests\Support\DevelopmentDeploymentFixture;

pest()->group('privileged');

beforeEach(function (): void {
    // The task worker must reach the checkout, so it lives in the system temporary directory.
    $this->fixture = new DevelopmentDeploymentFixture(sys_get_temp_dir());
    app()->instance(DevelopmentDeployment::class, $this->fixture->deployment);
});

afterEach(function (): void {
    $this->fixture->cleanup();
});

function worker_git(string $directory, string ...$arguments): string
{
    return trim(DevelopmentDeploymentFixture::command(['git', '-C', $directory, ...$arguments]));
}

it('compares and checks out content as the worker on a shared checkout, so a planted filter never runs as the managed user', function (): void {
    $home = $this->fixture->home;
    $records = $this->fixture->sandbox.'/filter-users';
    touch($records);
    // The worker can rewrite `.git/config` on a shared checkout and plant a filter for every path.
    foreach (['clean', 'smudge'] as $filter) {
        worker_git($home, 'config', 'filter.uid.'.$filter, 'printf "%s\n" "$(id -u)" >> '.escapeshellarg($records).'; cat');
    }
    file_put_contents($home.'/.git/info/attributes', "* filter=uid\n");
    DevelopmentDeploymentFixture::command(['setfacl', '-R', '-m', 'u:nobody:rwX,d:u:nobody:rwX,d:u:'.posix_geteuid().':rwX', $this->fixture->sandbox]);
    config()->set('orbit.tasks.worker_user', 'nobody');
    $commit = $this->fixture->push('second');

    $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance, onlyChanged: true, triggeredBy: 'schedule');

    $users = file($records, FILE_IGNORE_NEW_LINES) ?: [];
    expect($result?->succeeded)->toBeTrue()
        ->and(worker_git($home, 'rev-parse', 'HEAD'))->toBe($commit)
        ->and($users)->not->toBeEmpty()
        ->and(array_unique($users))->toBe([(string) posix_getpwnam('nobody')['uid']]);
    // The worker owns what it wrote. Its inherited ACL still lets the managed user change the file.
    expect(fileowner($home.'/public/index.php'))->toBe(posix_getpwnam('nobody')['uid'])
        ->and(file_put_contents($home.'/public/index.php', 'changed'))->toBe(7);
});
