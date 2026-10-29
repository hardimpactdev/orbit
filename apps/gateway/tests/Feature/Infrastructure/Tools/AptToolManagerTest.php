<?php

declare(strict_types=1);

use App\Domain\Tools\DebianVersionNormalizer;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\Tools\AptToolManager;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

describe(AptToolManager::class, function (): void {
    it('supports only Linux nodes and identifies itself as APT', function (): void {
        [$manager] = apt_tool_manager();

        expect($manager->name())
            ->toBe(ToolManagerName::Apt)
            ->and($manager->supportsNode(apt_tool_node('linux')))
            ->toBeTrue()
            ->and($manager->supportsNode(apt_tool_node('darwin')))
            ->toBeFalse();
    });

    it('accepts only strict Debian package names', function (string $package, bool $valid): void {
        [$manager] = apt_tool_manager();

        expect($manager->validatePackage($package))->toBe($valid);
    })->with([
        'simple package' => ['jq', true],
        'versioned package name' => ['php8.5-cli', true],
        'plus in package name' => ['libc++1', true],
        'digit prefix with a letter' => ['1password-cli', true],
        'single character' => ['a', false],
        'single digit' => ['1', false],
        'digits only' => ['12', false],
        'long digits only' => [str_repeat('1', times: 128), false],
        'leading option' => ['-o', false],
        'uppercase' => ['Jq', false],
        'newline injection' => ["jq\nwhoami", false],
        'nul injection' => ["jq\0whoami", false],
        'oversized' => [str_repeat('a', times: 129), false],
        'architecture qualifier' => ['jq:amd64', false],
        'version qualifier' => ['jq=1.7', false],
        'wildcard' => ['jq*', false],
        'repository selector' => ['resolute/jq', false],
        'whitespace' => ['jq package', false],
        'empty' => ['', false],
    ]);

    it('normalizes raw Debian versions through the approved normalizer', function (): void {
        [$manager] = apt_tool_manager();

        expect($manager->normalizeVersion('1:2.4.3-1ubuntu2'))
            ->toBe('2.4.3')
            ->and($manager->normalizeVersion('1.0~rc1-1'))
            ->toBeNull();
    });

    it('reserves every PHP role package and the recipe shared PHP pins for the PHP role', function (string $package, bool $owned): void {
        [$manager] = apt_tool_manager();

        expect($manager->roleOwns($package))->toBe($owned);
    })->with([
        'a recipe extension' => ['php8.5-xml', true],
        'another PHP version' => ['php8.3-cli', true],
        'an extension outside the recipe' => ['php8.5-opentelemetry', true],
        'the shared common pin' => ['php-common', true],
        'the shared GD library' => ['libgd3', true],
        'a pure PHP library' => ['php-symfony-console', false],
        'an unrelated package' => ['jq', false],
    ]);

    it('keeps absence separate from an unverifiable installed package', function (): void {
        [$manager, $agent] = apt_tool_manager();
        $agent->answer('tool.apt-inventory', ['packages' => [
            ['name' => 'jq', 'version' => '1.7.1'], ['name' => 'unreadable', 'version' => null],
            ['name' => 'duplicate', 'version' => '1'], ['name' => 'duplicate', 'version' => '2'],
        ]]);
        $inventory = $manager->installedInventory(apt_tool_node());
        expect($inventory->versionFor('jq'))->toBe('1.7.1');
        expect($inventory->versionFor('absent'))->toBeNull();
        expect(fn () => $inventory->versionFor('unreadable'))->toThrow(ToolManagerException::class);
        expect(fn () => $inventory->versionFor('duplicate'))->toThrow(ToolManagerException::class);
    });

    it('refuses malformed typed replies without reporting absent packages', function (string $method, string $command, array $data): void {
        [$manager, $agent] = apt_tool_manager();
        $agent->answerMalformed($command, $data);
        expect(fn () => $manager->{$method}(apt_tool_node()))->toThrow(ToolManagerException::class);
    })->with([
        ['managerVersion', 'tool.apt-version', ['version' => null]],
        ['installedInventory', 'tool.apt-inventory', ['packages' => null]],
        ['installedInventory', 'tool.apt-inventory', ['packages' => [['name' => 'jq']]]],
    ]);

});

/** @return array{AptToolManager, FakeAgentCommandRunner} */
function apt_tool_manager(): array
{
    $agent = new FakeAgentCommandRunner;

    return [new AptToolManager($agent, new DebianVersionNormalizer(new SemverVersionNormalizer)), $agent];
}

function apt_tool_node(string $platform = 'linux'): Node
{
    return new Node([
        'name' => 'apt-tool-node',
        'status' => 'active',
        'platform' => $platform,
        'public_ssh_host' => '127.0.0.1',
        'user' => 'orbit',
        'wireguard_ip' => '10.8.0.43',
    ]);
}
