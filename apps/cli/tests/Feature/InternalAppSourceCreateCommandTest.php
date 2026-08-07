<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal app source create command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $originalPath = getenv('PATH');
        $this->originalPath = $originalPath === false ? '' : $originalPath;
    });

    afterEach(function (): void {
        putenv("PATH={$this->originalPath}");

        foreach (app_source_owned_paths() as $path) {
            if (str_contains($path, '/orbit-app-source-bin-')) {
                delete_app_source_fake_bin($path);

                continue;
            }

            delete_app_source_checkout($path);
        }

        app_source_owned_paths(reset: true);
    });

    it('rejects a missing operation token before creating source', function (): void {
        [$exitCode, $output] = run_internal_app_source_create_command([
            'user' => 'orbit',
            'path' => '/home/orbit/apps/docs',
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('clones an existing repository with fixed argv', function (): void {
        $bin = install_app_source_fake_bin();

        [$exitCode, $output] = run_internal_app_source_create_command([
            'user' => 'orbit',
            'path' => '/home/orbit/apps/docs',
            '--repository' => 'hardimpact/docs',
            '--operation-token' => app_source_create_signed_operation_token(),
            '--json' => true,
        ]);

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['path'])
            ->toBe('/home/orbit/apps/docs')
            ->and($payload['success']['data']['commands'])
            ->toHaveCount(2)
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('install -d -m 755 -o orbit -g orbit /home/orbit/apps')
            ->toContain('gh repo clone hardimpact/docs /home/orbit/apps/docs')
            ->toContain('GH_HOST=github.com GH_PROMPT_DISABLED=1 GIT_TERMINAL_PROMPT=0 gh repo clone')
            ->not->toContain('gh repo create');
    });

    it('creates a private repository from a template before cloning it', function (): void {
        $bin = install_app_source_fake_bin();

        [$exitCode, $output] = run_internal_app_source_create_command([
            'user' => 'orbit',
            'path' => '/home/orbit/apps/docs',
            '--template-repository' => 'hardimpact/laravel-template',
            '--new-repository' => 'hardimpact/docs',
            '--operation-token' => app_source_create_signed_operation_token(),
            '--json' => true,
        ]);

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $calls = file_get_contents("{$bin}/calls.log");

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['repository'])
            ->toBe('hardimpact/docs')
            ->and($payload['success']['data']['template_repository'])
            ->toBe('hardimpact/laravel-template')
            ->and($calls)
            ->toContain('gh repo create hardimpact/docs --private --template hardimpact/laravel-template')
            ->toContain('GH_HOST=github.com GH_PROMPT_DISABLED=1 GIT_TERMINAL_PROMPT=0 gh repo create')
            ->toContain('gh repo clone hardimpact/docs /home/orbit/apps/docs')
            ->not->toContain('--public');

        expect(strpos($calls, 'gh repo create'))->toBeLessThan(strpos($calls, 'gh repo clone'));
    });

    it('clones a non-GitHub repository with terminal prompting disabled', function (): void {
        $bin = install_app_source_fake_bin();

        [$exitCode] = run_internal_app_source_create_command([
            'user' => 'orbit',
            'path' => '/home/orbit/apps/docs',
            '--repository' => 'https://git.example.com/hardimpact/docs.git',
            '--operation-token' => app_source_create_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain(
                'GIT_TERMINAL_PROMPT=0 git clone https://git.example.com/hardimpact/docs.git /home/orbit/apps/docs',
            )
            ->not->toContain('gh repo clone');
    });

    it('rejects incomplete or conflicting source plans before creating the parent directory', function (array $source): void {
        $bin = install_app_source_fake_bin();

        [$exitCode, $output] = run_internal_app_source_create_command([
            'user' => 'orbit',
            'path' => '/home/orbit/apps/docs',
            ...$source,
            '--operation-token' => app_source_create_signed_operation_token(),
            '--json' => true,
        ]);

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'])
            ->toBe('validation_failed')
            ->and(is_file("{$bin}/calls.log") ? file_get_contents("{$bin}/calls.log") : '')
            ->toBe('');
    })->with([
        'missing source' => [[]],
        'template without destination' => [[
            '--template-repository' => 'hardimpact/laravel-template',
        ]],
        'destination without template' => [[
            '--new-repository' => 'hardimpact/docs',
        ]],
        'clone and template branches' => [[
            '--repository' => 'hardimpact/docs',
            '--template-repository' => 'hardimpact/laravel-template',
            '--new-repository' => 'hardimpact/new-docs',
        ]],
        'malformed clone repository' => [[
            '--repository' => 'not-a-repository',
        ]],
        'credential-bearing clone repository' => [[
            '--repository' => 'https://secret-token@git.example.com/docs.git',
        ]],
        'clone repository with a token query' => [[
            '--repository' => 'https://git.example.com/docs.git?token=secret',
        ]],
        'github template URL instead of shorthand' => [[
            '--template-repository' => 'https://github.com/hardimpact/laravel-template.git',
            '--new-repository' => 'hardimpact/docs',
        ]],
    ]);

    it('reuses a matching checkout case-insensitively without cloning it again', function (): void {
        $bin = install_app_source_fake_bin();
        $path = sys_get_temp_dir().'/orbit-app-source-checkout-'.bin2hex(random_bytes(8));
        app_source_owned_paths($path);
        mkdir($path);
        mkdir("{$path}/.git");
        file_put_contents("{$path}/README.md", 'existing checkout');
        file_put_contents("{$bin}/git-origin", 'git@github.com:HardImpact/Docs.git');

        [$exitCode] = run_internal_app_source_create_command([
            'user' => 'orbit',
            'path' => $path,
            '--repository' => 'hardimpact/docs',
            '--operation-token' => app_source_create_signed_operation_token(),
            '--json' => true,
        ]);

        $calls = file_get_contents("{$bin}/calls.log");

        expect($exitCode)
            ->toBe(0)
            ->and($calls)
            ->toContain("git -C {$path} remote get-url origin")
            ->not->toContain('gh repo clone');
    });

    it('verifies template provenance before reusing a matching checkout', function (): void {
        $bin = install_app_source_fake_bin();
        $path = sys_get_temp_dir().'/orbit-app-source-checkout-'.bin2hex(random_bytes(8));
        app_source_owned_paths($path);
        mkdir($path);
        mkdir("{$path}/.git");
        file_put_contents("{$path}/README.md", 'existing checkout');
        file_put_contents("{$bin}/git-origin", 'git@github.com:HardImpact/Docs.git');
        file_put_contents("{$bin}/gh-template", 'HardImpact/Laravel-Template');

        [$exitCode] = run_internal_app_source_create_command([
            'user' => 'orbit',
            'path' => $path,
            '--template-repository' => 'hardimpact/laravel-template',
            '--new-repository' => 'hardimpact/docs',
            '--operation-token' => app_source_create_signed_operation_token(),
            '--json' => true,
        ]);

        $calls = file_get_contents("{$bin}/calls.log");

        expect($exitCode)
            ->toBe(0)
            ->and($calls)
            ->toContain("git -C {$path} remote get-url origin")
            ->toContain('gh repo view hardimpact/docs --json templateRepository,visibility')
            ->not->toContain('gh repo create')
            ->not->toContain('gh repo clone');

        expect(strpos($calls, 'git -C'))->toBeLessThan(strpos($calls, 'gh repo view'));
    });

    it('rejects an unverified repository before reusing its matching checkout', function (
        string $template,
        string $visibility,
    ): void {
        $bin = install_app_source_fake_bin();
        $path = sys_get_temp_dir().'/orbit-app-source-checkout-'.bin2hex(random_bytes(8));
        app_source_owned_paths($path);
        mkdir($path);
        mkdir("{$path}/.git");
        file_put_contents("{$path}/README.md", 'existing checkout');
        file_put_contents("{$bin}/git-origin", 'git@github.com:hardimpact/docs.git');
        file_put_contents("{$bin}/gh-template", $template);
        file_put_contents("{$bin}/gh-visibility", $visibility);

        [$exitCode, $output] = run_internal_app_source_create_command([
            'user' => 'orbit',
            'path' => $path,
            '--template-repository' => 'hardimpact/laravel-template',
            '--new-repository' => 'hardimpact/docs',
            '--operation-token' => app_source_create_signed_operation_token(),
            '--json' => true,
        ]);

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $calls = file_get_contents("{$bin}/calls.log");

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'])
            ->toBe('app_source_create_failed')
            ->and($calls)
            ->toContain('gh repo view hardimpact/docs --json templateRepository,visibility')
            ->not->toContain('gh repo create')
            ->not->toContain('gh repo clone');
    })->with([
        'wrong template' => ['someone/other-template', 'PRIVATE'],
        'public repository' => ['hardimpact/laravel-template', 'PUBLIC'],
    ]);

    it('continues a template retry when existing provenance matches case-insensitively', function (): void {
        $bin = install_app_source_fake_bin();
        touch("{$bin}/gh-create-fail");
        file_put_contents("{$bin}/gh-template", 'HardImpact/Laravel-Template');

        [$exitCode] = run_internal_app_source_create_command([
            'user' => 'orbit',
            'path' => '/home/orbit/apps/docs',
            '--template-repository' => 'hardimpact/laravel-template',
            '--new-repository' => 'hardimpact/docs',
            '--operation-token' => app_source_create_signed_operation_token(),
            '--json' => true,
        ]);

        $calls = file_get_contents("{$bin}/calls.log");

        expect($exitCode)
            ->toBe(0)
            ->and($calls)
            ->toContain('gh repo create hardimpact/docs --private --template hardimpact/laravel-template')
            ->toContain('gh repo view hardimpact/docs --json templateRepository,visibility')
            ->toContain('gh repo clone hardimpact/docs /home/orbit/apps/docs');
    });

    it('rejects a template retry when the existing repository has different provenance', function (): void {
        $bin = install_app_source_fake_bin();
        touch("{$bin}/gh-create-fail");
        file_put_contents("{$bin}/gh-template", 'someone/other-template');

        [$exitCode, $output] = run_internal_app_source_create_command([
            'user' => 'orbit',
            'path' => '/home/orbit/apps/docs',
            '--template-repository' => 'hardimpact/laravel-template',
            '--new-repository' => 'hardimpact/docs',
            '--operation-token' => app_source_create_signed_operation_token(),
            '--json' => true,
        ]);

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $calls = file_get_contents("{$bin}/calls.log");

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'])
            ->toBe('app_source_create_failed')
            ->and($calls)
            ->toContain('gh repo view hardimpact/docs --json templateRepository,visibility')
            ->not->toContain('gh repo clone');
    });

    it('rejects a template retry when the existing repository is public', function (): void {
        $bin = install_app_source_fake_bin();
        touch("{$bin}/gh-create-fail");
        file_put_contents("{$bin}/gh-template", 'hardimpact/laravel-template');
        file_put_contents("{$bin}/gh-visibility", 'PUBLIC');

        [$exitCode, $output] = run_internal_app_source_create_command([
            'user' => 'orbit',
            'path' => '/home/orbit/apps/docs',
            '--template-repository' => 'hardimpact/laravel-template',
            '--new-repository' => 'hardimpact/docs',
            '--operation-token' => app_source_create_signed_operation_token(),
            '--json' => true,
        ]);

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $calls = file_get_contents("{$bin}/calls.log");

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'])
            ->toBe('app_source_create_failed')
            ->and($calls)
            ->toContain('gh repo view hardimpact/docs --json templateRepository,visibility')
            ->not->toContain('gh repo clone');
    });
});

function app_source_create_signed_operation_token(
    string $id = 'app-source-create',
    string $node = 'app-dev',
    string $command = 'internal:app-source:create',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: app_source_create_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function app_source_create_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_app_source_create_command(array $parameters): array
{
    $output = new BufferedOutput;
    $command = Artisan::all()['internal:app-source:create'];
    $exitCode = $command->run(new ArrayInput($parameters), $output);

    return [$exitCode, trim($output->fetch())];
}

/**
 * Track only the paths this process created. A glob over the shared temp dir
 * would delete directories belonging to other parallel Pest shards mid-test.
 *
 * @return list<string>
 */
function app_source_owned_paths(?string $add = null, bool $reset = false): array
{
    static $paths = [];

    if ($reset) {
        $paths = [];

        return [];
    }

    if ($add !== null) {
        $paths[] = $add;
    }

    return $paths;
}

function install_app_source_fake_bin(): string
{
    $dir = sys_get_temp_dir().'/orbit-app-source-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);
    app_source_owned_paths($dir);

    file_put_contents("{$dir}/sudo", <<<'PHP'
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
        exit(0);
        PHP);
    chmod(filename: "{$dir}/sudo", permissions: 0o755);

    file_put_contents("{$dir}/gh", <<<'PHP'
        #!/usr/bin/env php
        <?php
        $arguments = array_slice($argv, 1);
        $gitTerminalPrompt = getenv('GIT_TERMINAL_PROMPT');
        $environment = sprintf(
            'GH_HOST=%s GH_PROMPT_DISABLED=%s GIT_TERMINAL_PROMPT=%s',
            getenv('GH_HOST') ?: '',
            getenv('GH_PROMPT_DISABLED') ?: '',
            $gitTerminalPrompt === false ? '' : $gitTerminalPrompt,
        );
        file_put_contents(__DIR__.'/calls.log', $environment.' gh '.implode(' ', $arguments).PHP_EOL, FILE_APPEND);

        if (($arguments[0] ?? null) === 'repo' && ($arguments[1] ?? null) === 'create' && is_file(__DIR__.'/gh-create-fail')) {
            fwrite(STDERR, "repository already exists\n");
            exit(1);
        }

        if (($arguments[0] ?? null) === 'repo' && ($arguments[1] ?? null) === 'view') {
            $template = is_file(__DIR__.'/gh-template') ? file_get_contents(__DIR__.'/gh-template') : false;

            if ($template === false || $template === '') {
                exit(1);
            }

            $visibility = is_file(__DIR__.'/gh-visibility') ? file_get_contents(__DIR__.'/gh-visibility') : 'PRIVATE';
            fwrite(STDOUT, json_encode([
                'templateRepository' => ['nameWithOwner' => trim($template)],
                'visibility' => trim($visibility),
            ], JSON_THROW_ON_ERROR).PHP_EOL);
        }

        exit(0);
        PHP);
    chmod(filename: "{$dir}/gh", permissions: 0o755);

    file_put_contents("{$dir}/git", <<<'PHP'
        #!/usr/bin/env php
        <?php
        $arguments = array_slice($argv, 1);
        $gitTerminalPrompt = getenv('GIT_TERMINAL_PROMPT');
        file_put_contents(
            __DIR__.'/calls.log',
            'GIT_TERMINAL_PROMPT='.($gitTerminalPrompt === false ? '' : $gitTerminalPrompt).' git '.implode(' ', $arguments).PHP_EOL,
            FILE_APPEND,
        );
        $origin = is_file(__DIR__.'/git-origin') ? file_get_contents(__DIR__.'/git-origin') : false;

        if ($origin !== false && $origin !== '') {
            fwrite(STDOUT, $origin.PHP_EOL);
        }

        exit(0);
        PHP);
    chmod(filename: "{$dir}/git", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function delete_app_source_fake_bin(string $path): void
{
    delete_app_source_file("{$path}/sudo");
    delete_app_source_file("{$path}/gh");
    delete_app_source_file("{$path}/git");
    delete_app_source_file("{$path}/git-origin");
    delete_app_source_file("{$path}/gh-create-fail");
    delete_app_source_file("{$path}/gh-template");
    delete_app_source_file("{$path}/gh-visibility");
    delete_app_source_file("{$path}/calls.log");

    if (is_dir($path)) {
        rmdir($path);
    }
}

function delete_app_source_checkout(string $path): void
{
    delete_app_source_file("{$path}/README.md");

    if (is_dir("{$path}/.git")) {
        rmdir("{$path}/.git");
    }

    if (is_dir($path)) {
        rmdir($path);
    }
}

function delete_app_source_file(string $path): void
{
    if (! is_file($path)) {
        return;
    }

    unlink($path);
}
