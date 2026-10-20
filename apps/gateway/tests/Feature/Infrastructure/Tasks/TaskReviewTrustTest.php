<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubRepository;
use App\Domain\Tasks\TaskReviewTrust;

it('authorizes only the named repository and positive numeric accounts', function (): void {
    $repository = GitHubRepository::fromOrigin('https://github.com/acme/orbit.git');
    $trust = TaskReviewTrust::fromConfig($repository, 'acme/orbit:42,7,42;other/orbit:99');

    expect($trust->valid)->toBeTrue();
    expect($trust->accountIds)->toBe([7, 42]);
    expect(TaskReviewTrust::fromConfig($repository, 'other/orbit:42')->accountIds)->toBe([]);
    expect(TaskReviewTrust::fromConfig($repository, 'acme/orbit:7,42')->revision)->toBe($trust->revision);
    expect(TaskReviewTrust::fromConfig($repository, 'acme/orbit:7')->revision)->not->toBe($trust->revision);
});

it('fails closed on malformed operator trust without converting names or roles', function (string $config): void {
    $trust = TaskReviewTrust::fromConfig(GitHubRepository::fromOrigin('https://github.com/acme/orbit.git'), $config);

    expect($trust->valid)->toBeFalse();
    expect($trust->accountIds)->toBe([]);
})->with([
    'wildcard repository' => ['acme/*:42'],
    'wildcard owner' => ['*/orbit:42'],
    'wildcard global' => ['*:42'],
    'git suffix alias' => ['acme/orbit.git:42'],
    'login' => ['acme/orbit:reviewer-renamed'],
    'role' => ['acme/orbit:admin'],
    'association' => ['acme/orbit:MEMBER'],
    'wildcard account' => ['acme/orbit:*'],
    'zero' => ['acme/orbit:0'],
    'negative' => ['acme/orbit:-1'],
    'float' => ['acme/orbit:42.0'],
    'mixed valid and invalid' => ['acme/orbit:42,admin'],
    'no account' => ['acme/orbit:'],
]);

it('isolates valid repository trust from malformed unrelated scopes', function (string $scope): void {
    $repository = GitHubRepository::fromOrigin('https://github.com/acme/orbit.git');
    $trust = TaskReviewTrust::fromConfig($repository, "acme/orbit:42;acme/widgets:7;{$scope}:99");

    expect($trust->valid)->toBeTrue();
    expect($trust->accountIds)->toBe([42]);
    expect($trust->revision)->toBe(TaskReviewTrust::fromConfig($repository, 'acme/orbit:42')->revision);
})->with(['Other/widgets', 'other/widgets.git', 'other/*', '*/widgets', 'garbage', '42']);

it('disables only the repository named by an invalid alias', function (): void {
    $config = 'acme/orbit:42;other/widgets:7;other/widgets.git:7';

    expect(TaskReviewTrust::fromConfig(GitHubRepository::fromOrigin('https://github.com/acme/orbit.git'), $config)->accountIds)->toBe([42]);
    expect(TaskReviewTrust::fromConfig(GitHubRepository::fromOrigin('https://github.com/other/widgets.git'), $config)->valid)->toBeFalse();
});

it('rejects relevant alias and wildcard scopes even alongside a valid named entry', function (string $scope): void {
    $trust = TaskReviewTrust::fromConfig(GitHubRepository::fromOrigin('https://github.com/acme/orbit.git'), "acme/orbit:42;{$scope}:7");

    expect($trust->valid)->toBeFalse();
    expect($trust->accountIds)->toBe([]);
})->with(['acme/orbit.git', 'acme/*', '*/orbit', '*']);

it('keeps absent trust valid but empty and isolates repository account lists', function (): void {
    $repository = GitHubRepository::fromOrigin('https://github.com/acme/orbit.git');

    expect(TaskReviewTrust::fromConfig($repository, '')->valid)->toBeTrue();
    expect(TaskReviewTrust::fromConfig($repository, '')->accountIds)->toBe([]);
    expect(TaskReviewTrust::fromConfig($repository, 'other/orbit:invalid;acme/orbit:42')->accountIds)->toBe([42]);
});

it('reads trusted reviewers from the operator value and fails closed on a malformed id', function (): void {
    $repository = GitHubRepository::fromOrigin('https://github.com/nckrtl/orbit.git');

    expect(TaskReviewTrust::fromConfig($repository, ' Nckrtl/Orbit : 18613261, 42 ; other/repo:7')->accountIds)->toBe([42, 18613261])
        ->and(TaskReviewTrust::fromConfig($repository, 'nckrtl/orbit:18613261')->accountIds)->toBe([18613261])
        ->and(TaskReviewTrust::fromConfig($repository, 'nckrtl/orbit:nckrtl')->valid)->toBeFalse()
        ->and(TaskReviewTrust::fromConfig($repository, 'nckrtl/orbit:')->valid)->toBeFalse();
});
