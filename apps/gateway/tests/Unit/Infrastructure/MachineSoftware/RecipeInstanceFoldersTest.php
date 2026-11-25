<?php

declare(strict_types=1);

use App\Domain\Instances\ContainerServer;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceFolderPlan;
use App\Infrastructure\MachineSoftware\RecipeLoader;

/** The shipped recipe with `$change` applied to its decoded document. */
function recipeWithInstances(Closure $change): string
{
    $document = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/resources/machine-software/recipe.json'), true, flags: JSON_THROW_ON_ERROR);
    $change($document);

    return json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

it('reads the two shared folders, a tool part with the tool\'s own pin', function (): void {
    $recipe = new RecipeLoader()->decode(recipeWithInstances(static fn () => null));
    $node = $recipe->tools['node'];

    $parts = array_keys($recipe->instances->runtime);

    expect(array_values(array_filter($parts, static fn (string $name): bool => ! str_starts_with($name, 'browser-'))))
        ->toBe(['node', 'bun', 'deno', 'composer', 'ripgrep', 'laravel-lsp', 'phpactor', 'php-data-bridge', 'vite-plus', 'chrome-headless-shell'])
        ->and($recipe->instances->runtime['chrome-headless-shell'])->toEqual($recipe->instances->agent['chrome-headless-shell'])
        ->and($recipe->instances->runtime['vite-plus']->artifact)->toBe($recipe->tools['vp-cli']->artifacts['amd64'])
        ->and($recipe->instances->runtime['vite-plus']->version)->toBe($recipe->tools['vp']->version)
        ->and($recipe->instances->runtime['vite-plus']->bin)->toBe(['vp' => 'vp'])
        ->and($recipe->instances->vitePlus?->part)->toBe('vite-plus')
        ->and($recipe->instances->vitePlus?->vp)->toBe($recipe->tools['vp'])
        ->and($recipe->instances->vitePlus?->node)->toBe($node->version)
        ->and(array_keys($recipe->instances->agent))->toBe(['t3', 'claude-code', 'codex', 'antigravity', 'conn-server', 'chrome-headless-shell', 'agent-browser'])
        ->and($recipe->instances->runtime['node']->artifact)->toBe($node->artifacts['amd64'])
        ->and($recipe->instances->runtime['node']->version)->toBe($node->version)
        ->and($recipe->instances->agent['claude-code']->artifact->algorithm)->toBe('sha512')
        ->and($recipe->instances->agent['codex']->bin)->toBe(['codex' => 'package/vendor/x86_64-unknown-linux-musl/bin/codex'])
        // The release tarball holds the one CLI binary, `antigravity`; containers call it `agy`.
        ->and($recipe->instances->agent['antigravity']->artifact->location)->toBe('https://github.com/google-antigravity/antigravity-cli/releases/download/'
            .$recipe->instances->agent['antigravity']->version.'/agy_cli_linux_x64.tar.gz')
        ->and($recipe->instances->agent['antigravity']->artifact->algorithm)->toBe('sha256')
        ->and($recipe->instances->agent['antigravity']->bin)->toBe(['agy' => 'antigravity'])
        ->and($recipe->instances->agent['conn-server']->artifact->location)->toStartWith('https://github.com/nckrtl/conn/releases/download/conn-server-v'.$recipe->instances->agent['conn-server']->version.'/')
        ->and($recipe->instances->agent['conn-server']->bin)->toBe(['conn-server' => 'conn-server-'.$recipe->instances->agent['conn-server']->version.'-linux-x64/conn-server']);
});

it('ships the Conn preview browser as a complete ZIP in the agent folder', function (): void {
    $recipe = new RecipeLoader()->decode(recipeWithInstances(static fn () => null));
    $parts = array_column(InstanceFolderPlan::for($recipe->instances)['agent']['parts'], null, 'name');

    expect($recipe->instances->agent['conn-server']->version)->toBe('0.5.3')
        ->and($recipe->instances->agent['conn-server']->artifact->digest)->toBe('1a4561e3089ba298641b7e2483f805b03533a490b604cf1f14510110352daeb2')
        ->and($parts['chrome-headless-shell'])->toBe([
            'name' => 'chrome-headless-shell', 'version' => '157.0.8103.78',
            'url' => 'https://storage.googleapis.com/chrome-for-testing-public/157.0.8103.78/linux64/chrome-headless-shell-linux64.zip',
            'archive' => 'zip', 'sha256' => 'f90329720fb61e5a76da9b6b509b6ad12a484c9c5df0359403e935177c6d153a',
            'bin' => ['chrome-headless-shell' => 'chrome-headless-shell-linux64/chrome-headless-shell'],
        ]);
});

it('pins the Conn release\'s signed macOS arm64 zip, and a release before 0.5.0 without one', function (): void {
    $recipe = new RecipeLoader()->decode(recipeWithInstances(static fn () => null));
    $older = new RecipeLoader()->decode(recipeWithInstances(static function (array &$document): void {
        unset($document['instances']['agent']['conn-server']['macos_arm64']);
    }));

    expect($recipe->instances->agent['conn-server']->macApp?->artifact->location)->toBe('https://github.com/nckrtl/conn/releases/download/conn-server-v0.5.3/conn-server-0.5.3-macos-arm64.zip')
        ->and($recipe->instances->agent['conn-server']->macApp?->artifact->digest)->toBe('621306c3d65cdee68773fe2f47de1c1c33fae6ecd9223d3e635d9a14fa790bf6')
        ->and($recipe->instances->agent['conn-server']->macApp?->bundleId)->toBe('com.nckrtl.conn-server')
        ->and($recipe->instances->agent['conn-server']->macApp?->teamId)->toBe('9SVJ4SYB9B')
        ->and($older->instances->agent['conn-server']->macApp)->toBeNull();
});

it('pins the macOS arm64 zip next to the Linux tarball, and gives container hosts the same plan as without it', function (): void {
    $recipe = new RecipeLoader()->decode(recipeWithInstances(static fn () => null));
    $without = new RecipeLoader()->decode(recipeWithInstances(static function (array &$document): void {
        unset($document['instances']['agent']['conn-server']['macos_arm64']);
    }));
    $part = $recipe->instances->agent['conn-server'];

    expect($part->artifact)->toEqual($without->instances->agent['conn-server']->artifact)
        ->and($part->bin)->toBe($without->instances->agent['conn-server']->bin)
        ->and($part->macApp?->artifact->algorithm)->toBe('sha256')
        // Container hosts install the agent folder plan; it, and so the folder's version, has the Linux tarball alone.
        ->and(InstanceFolderPlan::for($recipe->instances))->toBe(InstanceFolderPlan::for($without->instances))
        ->and(InstanceFolderPlan::for($recipe->instances, shift: false))->toBe(InstanceFolderPlan::for($without->instances, shift: false));
});

it('refuses a macOS app pin that is not the release\'s signed arm64 zip of an agent folder part', function (Closure $change, string $error): void {
    expect(static fn () => new RecipeLoader()->decode(recipeWithInstances($change)))->toThrow(InvalidArgumentException::class, $error);
})->with([
    'another asset' => [static function (array &$document): void {
        $document['instances']['agent']['conn-server']['macos_arm64']['url'] = str_replace('-macos-arm64.zip', '-linux-x64.tar.gz', $document['instances']['agent']['conn-server']['macos_arm64']['url']);
    }, 'instances.agent.conn-server.macos_arm64.url: expected the release\'s conn-server-'],
    'another release' => [static function (array &$document): void {
        $document['instances']['agent']['conn-server']['macos_arm64']['url'] = 'https://github.com/nckrtl/conn/releases/download/conn-server-v0.0.1/conn-server-0.0.1-macos-arm64.zip';
    }, 'instances.agent.conn-server.macos_arm64.url: expected the exact version'],
    'no sha256' => [static function (array &$document): void {
        unset($document['instances']['agent']['conn-server']['macos_arm64']['sha256']);
    }, 'instances.agent.conn-server.macos_arm64: expected exactly fields url, sha256, bundle_id, team_id'],
    'no team id' => [static function (array &$document): void {
        $document['instances']['agent']['conn-server']['macos_arm64']['team_id'] = 'Developer ID';
    }, 'instances.agent.conn-server.macos_arm64.team_id: expected a ten-character Developer ID team id.'],
    'a runtime folder part' => [static function (array &$document): void {
        $document['instances']['runtime']['composer']['macos_arm64'] = $document['instances']['agent']['conn-server']['macos_arm64'];
    }, 'instances.runtime.composer.macos_arm64: expected only on an agent folder part.'],
]);

it('ships agent-browser as one pinned release binary in the agent folder, which opens the same headless Chrome', function (): void {
    $recipe = new RecipeLoader()->decode(recipeWithInstances(static fn () => null));
    $parts = array_column(InstanceFolderPlan::for($recipe->instances)['agent']['parts'], null, 'name');

    expect($parts['agent-browser'])->toBe([
        'name' => 'agent-browser', 'version' => '0.41.2',
        'url' => 'https://github.com/vercel-labs/agent-browser/releases/download/v0.41.2/agent-browser-linux-x64',
        'archive' => 'file', 'sha256' => 'c4351f3802ffa5254d8c69f20a52e2fcf56925c6016fcf4203db25a07d811379',
        'bin' => ['agent-browser' => 'agent-browser-linux-x64'],
    ])->and(InstanceContainer::browserEnvironment($recipe->instances))->toBe([
        'PLAYWRIGHT_BROWSERS_PATH' => '0',
        'AGENT_BROWSER_EXECUTABLE_PATH' => '/opt/orbit-agent/current/agent/chrome-headless-shell/chrome-headless-shell-linux64/chrome-headless-shell',
    ])->and(ContainerServer::Conn->dropIn($recipe->instances))->toContain('Environment=CONN_SERVER_BROWSER_PATH='.InstanceContainer::headlessChrome($recipe->instances)."\n");
});

it('pins the browser libraries and fonts as Debian packages of the recipe\'s Ubuntu snapshot, which give no program', function (): void {
    $recipe = new RecipeLoader()->decode(recipeWithInstances(static fn () => null));
    $browser = array_filter($recipe->instances->runtime, static fn (string $name): bool => str_starts_with($name, 'browser-'), ARRAY_FILTER_USE_KEY);
    $snapshot = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/resources/machine-software/recipe.json'), true, flags: JSON_THROW_ON_ERROR)['ubuntu']['snapshot'];

    // What a headless Chromium links and the Ubuntu image lacks, with their own dependencies; and one sans, serif, mono and emoji set.
    expect(array_keys($browser))->toContain('browser-libnss3', 'browser-libnspr4', 'browser-libgbm1', 'browser-libxkbcommon0',
        'browser-libasound2t64', 'browser-fonts-dejavu-core', 'browser-fonts-liberation', 'browser-fonts-noto-color-emoji')
        // The GPU drivers stay out: libgbm loads them only on demand, and a headless browser in a container has no GPU.
        ->not->toContain('browser-mesa-libgallium', 'browser-libllvm21');
    foreach ($browser as $name => $part) {
        expect($part->bin)->toBe([])
            ->and($part->artifact->algorithm)->toBe('sha256')
            ->and($part->artifact->location)->toStartWith("https://snapshot.ubuntu.com/ubuntu/{$snapshot}/pool/")
            ->and($part->artifact->location)->toEndWith('.deb');
    }
    expect(array_column(InstanceFolderPlan::for($recipe->instances)['runtime']['parts'], 'archive', 'name')['browser-libnss3'])->toBe('deb');
});

it('gives the runtime folder one trust module for Chromium, at its own path outside lib, with the exact library it needs', function (): void {
    $recipe = new RecipeLoader()->decode(recipeWithInstances(static fn () => null));
    $part = $recipe->instances->runtime['browser-p11-kit-modules'];
    $plan = array_column(InstanceFolderPlan::for($recipe->instances)['runtime']['parts'], null, 'name');

    // docs/reference/development-containers.md#trust: p11-kit's trust module reads the system bundle; the package pins its own
    // library at its own version, so the part's version is the one the container's libp11-kit0 must have.
    expect($part->version)->toBe('0.26.2-2')
        ->and($part->trustModule)->toBe(['path' => 'usr/lib/x86_64-linux-gnu/pkcs11/p11-kit-trust.so', 'library' => 'libp11-kit0'])
        ->and($part->artifact->location)->toBe('https://snapshot.ubuntu.com/ubuntu/20261103T000000Z/pool/main/p/p11-kit/p11-kit-modules_0.26.2-2_amd64.deb')
        ->and($part->artifact->digest)->toBe('633ac53737f9a284acc3df4d1d717857902fe04a222f19331c7b18092fe2448b')
        ->and($plan['browser-p11-kit-modules']['trust_module'])->toBe(['source' => 'usr/lib/x86_64-linux-gnu/pkcs11/p11-kit-trust.so',
            'path' => 'pkcs11/p11-kit-trust.so', 'library' => 'libp11-kit0'])
        // Only this part names a module; no other part changes its shape.
        ->and(array_keys(array_filter($plan, static fn (array $entry): bool => isset($entry['trust_module']))))->toBe(['browser-p11-kit-modules'])
        ->and(str_starts_with($plan['browser-p11-kit-modules']['trust_module']['path'], 'lib/'))->toBeFalse();
});

it('pins the official laravel/lsp PHAR as the runtime folder\'s `laravel-lsp`, which runs on the container\'s own `php`', function (): void {
    $lsp = new RecipeLoader()->decode(recipeWithInstances(static fn () => null))->instances->runtime['laravel-lsp'];

    expect($lsp->version)->toBe('0.0.32')
        ->and($lsp->bin)->toBe(['laravel-lsp' => 'laravel-lsp'])
        ->and($lsp->artifact->location)->toBe('https://github.com/laravel/lsp/releases/download/v0.0.32/laravel-lsp')
        ->and($lsp->artifact->algorithm)->toBe('sha256')
        ->and($lsp->artifact->digest)->toBe('86d43f017b2247f1da428891a84a7db66d1f4443a0858301fe3a0d38482c5a51');
});

it('pins the Phpactor PHAR as the runtime folder\'s `phpactor`, which a Laravel default\'s seed runs for its symbol index', function (): void {
    $phpactor = new RecipeLoader()->decode(recipeWithInstances(static fn () => null))->instances->runtime['phpactor'];

    expect($phpactor->version)->toBe('2026.11.22.0')
        ->and($phpactor->bin)->toBe(['phpactor' => 'phpactor.phar'])
        ->and($phpactor->artifact->location)->toBe('https://github.com/phpactor/phpactor/releases/download/2026.11.22.0/phpactor.phar')
        ->and($phpactor->artifact->algorithm)->toBe('sha256')
        ->and($phpactor->artifact->digest)->toBe('1ae84e18348754a94ad28b14d3ec341aded0d2c082be61e155bc33fc42690659')
        // Development tools only: the production runtime folder leaves both language-tool PHARs out.
        ->and($phpactor->devOnly)->toBeTrue()
        ->and(new RecipeLoader()->decode(recipeWithInstances(static fn () => null))->instances->runtime['laravel-lsp']->devOnly)->toBeTrue()
        ->and(new RecipeLoader()->decode(recipeWithInstances(static fn () => null))->instances->runtime['composer']->devOnly)->toBeFalse();
});

it('reads a part without `bin` as files that give the folder no program', function (): void {
    $bridge = new RecipeLoader()->decode(recipeWithInstances(static fn () => null))->instances->runtime['php-data-bridge'];

    expect($bridge->bin)->toBe([])
        ->and($bridge->version)->toBe('0.3.1')
        ->and($bridge->artifact->location)->toBe('https://github.com/nckrtl/php-data-bridge/releases/download/v0.3.1/php-data-bridge.tar.gz');
});

it('publishes Deno from the Node pin in the shared runtime and changes only that folder version', function (): void {
    $recipe = new RecipeLoader()->decode(recipeWithInstances(static fn () => null));
    $withoutDeno = new RecipeLoader()->decode(recipeWithInstances(static function (array &$recipe): void {
        unset($recipe['instances']['runtime']['deno']);
    }));

    $plan = InstanceFolderPlan::for($recipe->instances);
    $before = InstanceFolderPlan::for($withoutDeno->instances);
    $parts = array_column($plan['runtime']['parts'], null, 'name');
    $deno = $recipe->tools['deno'];

    expect($parts)->toHaveKey('deno');
    expect($parts['deno'])->toBe([
        'name' => 'deno', 'version' => $deno->version, 'url' => $deno->artifacts['amd64']->location,
        'archive' => 'zip', 'sha256' => $deno->artifacts['amd64']->digest, 'bin' => ['deno' => 'deno'],
    ]);
    expect($plan['runtime']['version'])->not->toBe($before['runtime']['version'])
        ->and($plan['agent'])->toBe($before['agent'])
        ->and($recipe->identity)->toBe($withoutDeno->identity);
});

it('keeps the shared folders out of the software identity, so a new T3 rebuilds no task image', function (): void {
    $before = new RecipeLoader()->decode(recipeWithInstances(static fn () => null));
    $after = new RecipeLoader()->decode(recipeWithInstances(static function (array &$recipe): void {
        $recipe['instances']['agent']['t3']['version'] = '0.1.8';
        $recipe['instances']['agent']['t3']['artifacts']['amd64'] = ['url' => 'https://github.com/pingdotgg/t3code/releases/download/v0.1.8/t3-0.1.8-linux-x64.tar.gz', 'sha256' => str_repeat('b', 64)];
    }));

    expect($after->identity)->toBe($before->identity)
        ->and($after->instances->agent['t3']->version)->toBe('0.1.8');
});

it('refuses a malformed folder part', function (Closure $change, string $message): void {
    expect(fn () => new RecipeLoader()->decode(recipeWithInstances($change)))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no instances' => [static function (array &$recipe): void {
        unset($recipe['instances']);
    }, 'expected exactly fields'],
    'a third folder' => [static function (array &$recipe): void {
        $recipe['instances']['extra'] = [];
    }, 'instances: expected exactly fields runtime, agent, php'],
    'a program twice' => [static function (array &$recipe): void {
        $recipe['instances']['agent']['codex']['bin']['claude'] = 'x';
    }, 'is not a new lower-case program name'],
    'a path out of the artifact' => [static function (array &$recipe): void {
        $recipe['instances']['runtime']['ripgrep']['bin']['rg'] = '../../etc/passwd';
    }, 'expected a relative path inside the artifact'],
    'a dev_only marker that is not true' => [static function (array &$recipe): void {
        $recipe['instances']['runtime']['phpactor']['dev_only'] = false;
    }, 'instances.runtime.phpactor.dev_only: expected true, or no field'],
    'no program' => [static function (array &$recipe): void {
        $recipe['instances']['runtime']['ripgrep']['bin'] = new stdClass;
    }, 'expected at least one program'],
    'a tool part without programs' => [static function (array &$recipe): void {
        unset($recipe['instances']['runtime']['node']['bin']);
    }, 'instances.runtime.node.bin: expected an object'],
    'a part without programs and with an unknown field' => [static function (array &$recipe): void {
        $recipe['instances']['runtime']['php-data-bridge']['extra'] = 'x';
    }, 'instances.runtime.php-data-bridge: expected exactly fields version, artifacts; got version, artifacts, extra.'],
    'an Ubuntu tool' => [static function (array &$recipe): void {
        $recipe['instances']['runtime']['node']['tool'] = 'composer';
    }, 'expected an artifact tool of the recipe'],
    'a channel URL' => [static function (array &$recipe): void {
        $recipe['instances']['agent']['t3']['artifacts']['amd64']['url'] = 'https://github.com/pingdotgg/t3code/releases/latest/download/t3-linux-x64.tar.gz';
    }, 'expected the exact version in an immutable release URL'],
    'a short digest' => [static function (array &$recipe): void {
        $recipe['instances']['agent']['t3']['artifacts']['amd64']['sha256'] = 'abc';
    }, 'expected 64 lowercase hexadecimal characters'],
    'Vite+ in the agent folder' => [static function (array &$recipe): void {
        $recipe['instances']['agent']['vite-plus'] = $recipe['instances']['runtime']['vite-plus'];
    }, "expected the runtime folder's one Vite+ part, with the one program vp"],
    'no Vite+' => [static function (array &$recipe): void {
        unset($recipe['instances']['runtime']['vite-plus']);
    }, 'instances.runtime: expected a Vite+ part, the recipe tool vp-cli.'],
    'Vite+ with a second program' => [static function (array &$recipe): void {
        $recipe['instances']['runtime']['vite-plus']['bin']['vpx'] = 'vp';
    }, "expected the runtime folder's one Vite+ part, with the one program vp"],
    'no trust module' => [static function (array &$recipe): void {
        unset($recipe['instances']['runtime']['browser-p11-kit-modules']['trust_module']);
    }, 'instances.runtime: expected exactly one part with a trust_module.'],
    'two trust modules' => [static function (array &$recipe): void {
        $recipe['instances']['runtime']['browser-libnss3']['trust_module'] = $recipe['instances']['runtime']['browser-p11-kit-modules']['trust_module'];
    }, 'instances.runtime: expected exactly one part with a trust_module.'],
    'a trust module in the agent folder' => [static function (array &$recipe): void {
        $recipe['instances']['agent']['p11-kit'] = $recipe['instances']['runtime']['browser-p11-kit-modules'];
    }, 'instances.agent.p11-kit.trust_module: expected a Debian package part of the runtime folder.'],
    'a trust module of a tarball' => [static function (array &$recipe): void {
        $recipe['instances']['runtime']['php-data-bridge']['trust_module'] = $recipe['instances']['runtime']['browser-p11-kit-modules']['trust_module'];
        unset($recipe['instances']['runtime']['browser-p11-kit-modules']);
    }, 'instances.runtime.php-data-bridge.trust_module: expected a Debian package part of the runtime folder.'],
    'a trust module out of the package' => [static function (array &$recipe): void {
        $recipe['instances']['runtime']['browser-p11-kit-modules']['trust_module']['path'] = '../../etc/p11-kit-trust.so';
    }, 'expected a relative path to a module inside the package'],
    'an empty folder' => [static function (array &$recipe): void {
        $recipe['instances']['agent'] = new stdClass;
    }, 'instances.agent: expected at least one part'],
]);
