<?php

declare(strict_types=1);

use App\Actions\SwampCredentials\RenewSwampCredentialsAction;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SwampCredentials\SwampGatewayCredential;
use App\Domain\SwampCredentials\SwampGatewayToken;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\SwampCredentials\GatewayProcessStarts;
use App\Infrastructure\SwampCredentials\SwampCredentialConfiguration;
use App\Infrastructure\SwampCredentials\SwampHostTokenInstaller;
use App\Infrastructure\SwampCredentials\SwampNodeTokens;
use App\Infrastructure\SwampCredentials\SwampNodeUnconvergedException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\SwampCredentials\SwampTokenReadCheck;
use App\Infrastructure\Tasks\SwampReaderEnvironment;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

uses(LazilyRefreshDatabase::class);

/** The Swamp Node entrypoint as the Gateway sees it; every secret is an obvious fake. */
final class FakeSwampNodeTokens extends SwampNodeTokens
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, array{name:string,principal:string|null,expiresAt:string|null,state:string}> */
    public array $access = [];

    /** @var array<string, array{name:string,expiresAt:string|null,state:string}> */
    public array $enrollment = [];

    /** @var array<string, mixed> */
    public array $held = ['driver' => '', 'workers' => []];

    public ?string $failList = null;

    public ?string $failMintKind = null;

    public bool $unconverged = false;

    public int $failListOnCall = 0;

    private int $listCalls = 0;

    public function __construct() {}

    public function token(string $name, string $principal, string $expires, string $state = 'active'): void
    {
        $this->access[$name] = ['name' => $name, 'principal' => $principal, 'expiresAt' => $expires, 'state' => $state];
    }

    #[Override]
    protected function run(array $arguments, float $timeout, string $code, bool $secret = false): string
    {
        $this->calls[] = implode(' ', $arguments);
        if ($this->unconverged) {
            throw new SwampNodeUnconvergedException(SwampNodeUnconvergedException::Code.': '.self::Entrypoint.' is missing on the Swamp Node; converge its swamp role.');
        }
        if ($arguments[0] === 'list') {
            $this->listCalls++;
            if ($this->failList !== null && ($this->failListOnCall === 0 || $this->listCalls === $this->failListOnCall)) {
                throw new RuntimeException($this->failList);
            }
        }
        if ($arguments[0] === 'mint') {
            if ($this->failMintKind === $arguments[1]) {
                throw new RuntimeException("mint {$arguments[1]} failed");
            }
            // As the entrypoint: today's name, minted once and revealed again by a rerun.
            $kind = SwampGatewayToken::from($arguments[1]);
            $name = $kind->base().'-20261121';
            $this->access[$name] ??= ['name' => $name, 'principal' => $kind->principal(), 'expiresAt' => '2026-12-21T12:00:00.000Z', 'state' => 'active'];

            return json_encode(['name' => $name, 'token' => $name.'.fake-secret-new', 'expired' => false, 'expiresAt' => '2026-12-21T12:00:00.000Z'], JSON_THROW_ON_ERROR);
        }
        if ($arguments[0] === 'revoke') {
            $this->access[$arguments[1]]['state'] = 'revoked';
        }

        return $arguments[0] === 'list' ? json_encode(['access' => array_values($this->access), 'enrollment' => array_values($this->enrollment),
            'held' => $this->held], JSON_THROW_ON_ERROR) : '';
    }
}

final class FakeSwampHostTokenInstaller extends SwampHostTokenInstaller
{
    /** @var list<string> */
    public array $installed = [];

    public function __construct(private readonly ?SwampReaderEnvironment $environment = null) {}

    #[Override]
    public function install(SwampGatewayToken $kind, SwampGatewayCredential $credential): void
    {
        $this->installed[] = $kind->value.':'.$credential->name;
        if ($this->environment !== null) {
            [$tokenKey, $nameKey, $expiryKey] = $kind->keys();
            $this->environment->replace($this->environment->contents(), [
                $tokenKey => $credential->token(),
                $nameKey => $credential->name,
                $expiryKey => $credential->expiresAt->format('Y-m-d\TH:i:s.u\Z'),
            ]);
        }
    }
}

final class FakeSwampTokenReadCheck extends SwampTokenReadCheck
{
    public bool $reads = true;

    #[Override]
    public function check(#[SensitiveParameter] string $token, array $values): void
    {
        if (! $this->reads) {
            throw new RuntimeException('refused');
        }
    }
}

final class FakeGatewayProcessStarts extends GatewayProcessStarts
{
    public ?int $startedAt = null;

    #[Override]
    public function allStartedAfter(int $time): bool
    {
        return $this->startedAt !== null && $this->startedAt > $time;
    }
}

final class FakeSwampCredentialConfiguration extends SwampCredentialConfiguration
{
    /** @var list<string> */
    public array $calls = [];

    public bool $fails = false;

    public function __construct() {}

    #[Override]
    public function serving(): void
    {
        $this->calls[] = 'serving';
        if ($this->fails) {
            throw new RuntimeException('configure failed');
        }
    }

    #[Override]
    public function candidate(): void
    {
        $this->calls[] = 'candidate';
    }
}

/** A shared env with read and run tokens; the layout and lock point at it. The serving configuration holds the same names. */
function swampCredentialsEnvironment(string $read = 'orbit-gateway-read-20261101', string $run = 'orbit-gateway-run'): string
{
    $base = sys_get_temp_dir().'/orbit-swamp-credentials-'.bin2hex(random_bytes(4));
    mkdir($base.'/shared', 0700, true);
    $path = $base.'/shared/gateway.env';
    file_put_contents($path, "APP_NAME=Orbit\nORBIT_SWAMP_READ_TOKEN={$read}.fake-secret-read\nORBIT_SWAMP_RUN_TOKEN={$run}.fake-secret-run\n");
    chmod($path, 0600);
    touch($path, now()->subDay()->getTimestamp());
    app()->instance(GatewayReleaseLayout::class, new GatewayReleaseLayout($base.'/current/apps/gateway'));
    app()->instance(GatewayReleaseLock::class, new GatewayReleaseLock($base.'/release.lock'));
    config(['swamp.read_token' => "{$read}.fake-secret-read", 'swamp.run_token' => "{$run}.fake-secret-run"]);

    return $path;
}

beforeEach(function (): void {
    $this->travelTo(now()->parse('2026-11-21T12:00:00Z'));
    $this->node = new FakeSwampNodeTokens;
    $this->node->token('orbit-gateway-read-20261101', 'user:orbit-gateway-read', '2026-12-01T00:00:00.000Z');
    $this->node->token('orbit-gateway-run', 'user:orbit-gateway', '2026-12-19T00:00:00.000Z');
    $this->reads = new FakeSwampTokenReadCheck;
    $this->processes = new FakeGatewayProcessStarts;
    $this->configuration = new FakeSwampCredentialConfiguration;
    $this->hostInstaller = new FakeSwampHostTokenInstaller;
    app()->instance(SwampNodeTokens::class, $this->node);
    app()->instance(SwampTokenReadCheck::class, $this->reads);
    app()->instance(GatewayProcessStarts::class, $this->processes);
    app()->instance(SwampCredentialConfiguration::class, $this->configuration);
    app()->instance(SwampHostTokenInstaller::class, $this->hostInstaller);
});

it('renews only a token within ten days by its live expiry, caches the configuration and keeps the old token', function (): void {
    $path = swampCredentialsEnvironment();

    expect(app(RenewSwampCredentialsAction::class)->execute())->toBe(['installed orbit-gateway-read-20261121', 'cached the configuration'])
        ->and(array_values(array_filter($this->node->calls, static fn (string $call): bool => $call !== 'list')))->toBe(['mint read'])
        ->and($this->configuration->calls)->toBe(['serving'])
        ->and($this->node->access['orbit-gateway-read-20261101']['state'])->toBe('active');
    $env = (string) file_get_contents($path);
    expect($env)->toContain("ORBIT_SWAMP_READ_TOKEN=orbit-gateway-read-20261121.fake-secret-new\n")
        ->toContain("ORBIT_SWAMP_READ_TOKEN_NAME=orbit-gateway-read-20261121\n")
        ->toContain("ORBIT_SWAMP_RUN_TOKEN=orbit-gateway-run.fake-secret-run\n")
        ->and(glob(dirname($path).'/gateway.env.before-swamp-*'))->toHaveCount(1);
});

it('finishes configuration on a rerun after a failed configure, without minting again', function (): void {
    swampCredentialsEnvironment();
    $this->configuration->fails = true;
    expect(fn () => app(RenewSwampCredentialsAction::class)->execute())->toThrow(RuntimeException::class, 'configure failed');
    $this->configuration->fails = false;

    expect(app(RenewSwampCredentialsAction::class)->execute())->toBe(['cached the configuration'])
        ->and(array_values(array_filter($this->node->calls, static fn (string $call): bool => str_starts_with($call, 'mint'))))->toBe(['mint read']);
});

it('leaves the env unchanged when the new token cannot read Swamp', function (): void {
    $path = swampCredentialsEnvironment();
    $before = file_get_contents($path);
    $this->reads->reads = false;

    expect(fn () => app(RenewSwampCredentialsAction::class)->execute())->toThrow(RuntimeException::class, 'could not read Swamp')
        ->and(file_get_contents($path))->toBe($before);
});

it('revokes a predecessor only once the serving configuration holds the new name and every process started after the env changed', function (): void {
    $path = swampCredentialsEnvironment('orbit-gateway-read-20261121');
    $this->node->token('orbit-gateway-read-20261121', 'user:orbit-gateway-read', '2026-12-21T12:00:00.000Z');
    // Out of family, another principal, and the out-of-scope worker token are never touched.
    $this->node->token('orbit-gateway-read-20261020', 'user:someone', '2026-11-25T00:00:00.000Z');
    $this->node->token('orbit-node-209-access', 'worker:orbit-node-209', '2026-11-25T00:00:00.000Z');
    $changed = filemtime($path);

    $this->processes->startedAt = $changed - 1;
    expect(app(RenewSwampCredentialsAction::class)->revokePredecessors($this->node->list()))->toBe([]);

    config(['swamp.read_token' => 'orbit-gateway-read-20261101.fake-secret-read']);
    $this->processes->startedAt = $changed + 1;
    expect(app(RenewSwampCredentialsAction::class)->revokePredecessors($this->node->list()))->toBe([]);

    config(['swamp.read_token' => 'orbit-gateway-read-20261121.fake-secret-read']);
    expect(app(RenewSwampCredentialsAction::class)->revokePredecessors($this->node->list()))->toBe(['revoked orbit-gateway-read-20261101'])
        ->and($this->node->access['orbit-gateway-read-20261020']['state'])->toBe('active')
        ->and($this->node->access['orbit-node-209-access']['state'])->toBe('active');
    // A later run finds the same facts and revokes nothing more.
    expect(app(RenewSwampCredentialsAction::class)->revokePredecessors($this->node->list()))->toBe([]);
});

it('a release renews the Swamp Node first, caches the candidate and gates on the generation each consumer holds', function (): void {
    swampCredentialsEnvironment('orbit-gateway-read-20261121', 'orbit-gateway-run-20261121');
    $this->node->token('orbit-gateway-read-20261121', 'user:orbit-gateway-read', '2026-12-21T12:00:00.000Z');
    $this->node->token('orbit-gateway-run-20261121', 'user:orbit-gateway', '2026-12-21T12:00:00.000Z');
    $this->node->token('serve-driver-20261121', 'user:nckrtl', '2026-12-21T12:00:00.000Z');
    $workers = [];
    foreach (['a', 'b', 'c'] as $slot) {
        // slot b holds a token that expires tomorrow, though its successor is minted and not yet installed.
        $this->node->token("swamp-role-209-{$slot}-access", 'worker:orbit-node-209', $slot === 'b' ? '2026-11-22T00:00:00.000Z' : '2026-12-21T00:00:00.000Z');
        $this->node->enrollment["swamp-role-209-{$slot}"] = ['name' => "swamp-role-209-{$slot}", 'expiresAt' => '2026-12-21T00:00:00.000Z', 'state' => 'enrolled'];
        $workers[] = ['slot' => $slot, 'access' => "swamp-role-209-{$slot}-access", 'enrollment' => "swamp-role-209-{$slot}"];
    }
    $this->node->token('swamp-role-209-b-access-20261121', 'worker:orbit-node-209', '2026-12-21T12:00:00.000Z');
    $this->node->held = ['driver' => 'serve-driver-20261121', 'workers' => $workers];

    expect(fn () => app(RenewSwampCredentialsAction::class)->execute(true))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('gateway.release_swamp_token_expiring')
            ->and($exception->getMessage())->toContain('slot b server.token (swamp-role-209-b-access)')->not->toContain('serve-driver')
            ->not->toContain('fake-secret');
    });
    expect($this->node->calls[0])->toBe('renew-node');

    $this->node->held['workers'][1]['access'] = 'swamp-role-209-b-access-20261121';
    expect(app(RenewSwampCredentialsAction::class)->execute(true))->toBe([]);

    // Unknown enrollment state fails closed.
    $this->node->enrollment['swamp-role-209-a']['state'] = 'pending';
    expect(fn () => app(RenewSwampCredentialsAction::class)->execute(true))->toThrow(ResourceOperationException::class, 'slot a enrollment.token');

    $this->node->enrollment = [];
    expect(fn () => app(RenewSwampCredentialsAction::class)->execute(true))->toThrow(ResourceOperationException::class, 'slot a enrollment.token');
});

it('a release caches the candidate when it installed a token', function (): void {
    swampCredentialsEnvironment();

    expect(fn () => app(RenewSwampCredentialsAction::class)->execute(true))->toThrow(ResourceOperationException::class, 'serve-driver')
        ->and($this->configuration->calls)->toBe(['candidate']);
});

it('raises assistance when renewal fails and an installed token expires within three days', function (): void {
    swampCredentialsEnvironment();
    $this->node->access['orbit-gateway-read-20261101']['expiresAt'] = '2026-11-23T00:00:00.000Z';
    $this->reads->reads = false;

    expect(fn () => app(RenewSwampCredentialsAction::class)->execute())->toThrow(RuntimeException::class, 'read token needs assistance');
});

it('in a release container installs due tokens hourly through the host installer and configures serving, while release mints for the host', function (): void {
    $path = swampCredentialsEnvironment();
    $base = dirname($path, 2);
    mkdir($base.'/.orbit', 0700, true);
    mkdir($base.'/shared/.gateway-release-environment', 0700, true);
    file_put_contents($base.'/.orbit/gateway-containers.json', '{}');
    $hostInstaller = new FakeSwampHostTokenInstaller(app(SwampReaderEnvironment::class));
    app()->instance(SwampHostTokenInstaller::class, $hostInstaller);

    expect(app(RenewSwampCredentialsAction::class)->mintForHost(SwampGatewayToken::Run))->toBeNull();
    $reveal = json_decode((string) app(RenewSwampCredentialsAction::class)->mintForHost(SwampGatewayToken::Read), true);
    expect($reveal)->toBe(['name' => 'orbit-gateway-read-20261121', 'token' => 'orbit-gateway-read-20261121.fake-secret-new',
        'expiresAt' => '2026-12-21T12:00:00.000000Z']);
    expect(fn () => app(RenewSwampCredentialsAction::class)->execute(true))
        ->toThrow(ResourceOperationException::class, 'still due after the host installation');

    // Hourly run in container mode installs through the host installer and runs serving configure.
    expect(app(RenewSwampCredentialsAction::class)->execute())->toBe(['installed orbit-gateway-read-20261121', 'cached the configuration'])
        ->and($hostInstaller->installed)->toBe(['read:orbit-gateway-read-20261121'])
        ->and($this->configuration->calls)->toBe(['serving'])
        ->and((string) file_get_contents($path))->toContain("ORBIT_SWAMP_READ_TOKEN=orbit-gateway-read-20261121.fake-secret-new\n");
});

it('names generation families for any gateway suffix and strips dated/legacy stamps for other families', function (): void {
    expect(SwampGatewayToken::family('orbit-gateway-read-20261121'))->toBe('orbit-gateway-read')
        ->and(SwampGatewayToken::family('orbit-gateway-read-20261121-2'))->toBe('orbit-gateway-read')
        ->and(SwampGatewayToken::family('orbit-gateway-read-manual'))->toBe('orbit-gateway-read')
        ->and(SwampGatewayToken::family('orbit-gateway-run-20261027'))->toBe('orbit-gateway-run')
        ->and(SwampGatewayToken::family('serve-driver-20261121T120000Z'))->toBe('serve-driver')
        ->and(SwampGatewayToken::family('swamp-role-209-a-access'))->toBe('swamp-role-209-a-access');
});

it('revokes manual-suffix predecessors with matching principal and safe handover', function (): void {
    $path = swampCredentialsEnvironment('orbit-gateway-read-20261121');
    $this->node->token('orbit-gateway-read-20261121', 'user:orbit-gateway-read', '2026-12-21T12:00:00.000Z');
    $this->node->token('orbit-gateway-read-manual', 'user:orbit-gateway-read', '2026-11-25T00:00:00.000Z');
    $this->node->token('orbit-gateway-read-other', 'user:someone-else', '2026-11-25T00:00:00.000Z');
    $this->processes->startedAt = filemtime($path) + 1;

    expect(app(RenewSwampCredentialsAction::class)->revokePredecessors($this->node->list()))
        ->toBe(['revoked orbit-gateway-read-20261101', 'revoked orbit-gateway-read-manual'])
        ->and($this->node->access['orbit-gateway-read-other']['state'])->toBe('active');
});

it('never revokes predecessors during release preflight', function (): void {
    $path = swampCredentialsEnvironment('orbit-gateway-read-20261121', 'orbit-gateway-run-20261121');
    $this->node->token('orbit-gateway-read-20261121', 'user:orbit-gateway-read', '2026-12-21T12:00:00.000Z');
    $this->node->token('orbit-gateway-run-20261121', 'user:orbit-gateway', '2026-12-21T12:00:00.000Z');
    $this->node->token('serve-driver-20261121', 'user:nckrtl', '2026-12-21T12:00:00.000Z');
    $workers = [];
    foreach (['a', 'b', 'c'] as $slot) {
        $this->node->token("swamp-role-209-{$slot}-access", 'worker:orbit-node-209', '2026-12-21T00:00:00.000Z');
        $this->node->enrollment["swamp-role-209-{$slot}"] = ['name' => "swamp-role-209-{$slot}", 'expiresAt' => '2026-12-21T00:00:00.000Z', 'state' => 'enrolled'];
        $workers[] = ['slot' => $slot, 'access' => "swamp-role-209-{$slot}-access", 'enrollment' => "swamp-role-209-{$slot}"];
    }
    $this->node->held = ['driver' => 'serve-driver-20261121', 'workers' => $workers];
    $this->processes->startedAt = filemtime($path) + 100;

    expect(app(RenewSwampCredentialsAction::class)->execute(true))->toBe([])
        ->and($this->node->access['orbit-gateway-read-20261101']['state'])->toBe('active')
        ->and(array_filter($this->node->calls, static fn (string $call): bool => str_starts_with($call, 'revoke')))->toBe([]);
});

it('checks schedule and non-inactive states in GatewayProcessStarts', function (): void {
    $changed = 1_700_000_000;
    $listedOutput = "orbit-gateway-horizon.service loaded deactivating stop-sigterm Horizon\norbit-gateway-schedule.service loaded active running Scheduler\n";
    $starts = [
        'orbit-gateway-horizon.service' => $changed - 10,
        'orbit-gateway-schedule.service' => $changed + 10,
    ];
    Process::fake(static function ($process) use (&$listedOutput, &$starts) {
        $argv = is_array($process->command) ? $process->command : explode(' ', (string) $process->command);
        if (($argv[1] ?? '') === 'list-units') {
            return Process::result(output: $listedOutput);
        }
        $unit = $argv[2] ?? '';

        return Process::result(output: '@'.($starts[$unit] ?? 0)."\n");
    });

    expect((new GatewayProcessStarts)->allStartedAfter($changed))->toBeFalse();
    Process::assertRan(static function ($process): bool {
        $argv = is_array($process->command) ? $process->command : explode(' ', (string) $process->command);

        return in_array('--state=active,activating,deactivating,reloading', $argv, true)
            && in_array('orbit-gateway-schedule.service', $argv, true) && in_array('orbit-gateway-web.service', $argv, true);
    });

    $listedOutput = "orbit-gateway-horizon.service loaded active running Horizon\norbit-gateway-schedule.service loaded active running Scheduler\n";
    $starts = [
        'orbit-gateway-horizon.service' => $changed + 10,
        'orbit-gateway-schedule.service' => $changed - 5,
    ];
    expect((new GatewayProcessStarts)->allStartedAfter($changed))->toBeFalse();

    $starts['orbit-gateway-schedule.service'] = $changed + 10;
    expect((new GatewayProcessStarts)->allStartedAfter($changed))->toBeTrue();

    // The web workers keep the read token in memory too: a reloading web that started before the env change blocks a revoke.
    $listedOutput .= "orbit-gateway-web.service loaded reloading reload Web\n";
    $starts['orbit-gateway-web.service'] = $changed - 1;
    expect((new GatewayProcessStarts)->allStartedAfter($changed))->toBeFalse();
    $starts['orbit-gateway-web.service'] = $changed + 1;
    expect((new GatewayProcessStarts)->allStartedAfter($changed))->toBeTrue();
});

it('applies configure for a published read token even when the run mint fails, and still reports the failure', function (): void {
    $path = swampCredentialsEnvironment();
    $this->node->access['orbit-gateway-run']['expiresAt'] = '2026-11-25T00:00:00.000Z';
    $this->node->failMintKind = 'run';

    expect(fn () => app(RenewSwampCredentialsAction::class)->execute())->toThrow(RuntimeException::class, 'mint run failed')
        ->and($this->configuration->calls)->toBe(['serving'])
        ->and((string) file_get_contents($path))->toContain("ORBIT_SWAMP_READ_TOKEN=orbit-gateway-read-20261121.fake-secret-new\n");
});

it('never leaks an undelimited secret from gateway.env through the release gate', function (): void {
    $path = swampCredentialsEnvironment();
    $canary = 'CANARY_SECRET_NO_DOT_GATEWAY';
    file_put_contents($path, "APP_NAME=Orbit\nORBIT_SWAMP_READ_TOKEN={$canary}\nORBIT_SWAMP_RUN_TOKEN={$canary}\n");
    chmod($path, 0600);
    $this->node->token('serve-driver-20261121', 'user:nckrtl', '2026-12-21T12:00:00.000Z');
    $workers = [];
    foreach (['a', 'b', 'c'] as $slot) {
        $this->node->token("swamp-role-209-{$slot}-access", 'worker:orbit-node-209', '2026-12-21T00:00:00.000Z');
        $this->node->enrollment["swamp-role-209-{$slot}"] = ['name' => "swamp-role-209-{$slot}", 'expiresAt' => '2026-12-21T00:00:00.000Z', 'state' => 'enrolled'];
        $workers[] = ['slot' => $slot, 'access' => "swamp-role-209-{$slot}-access", 'enrollment' => "swamp-role-209-{$slot}"];
    }
    $this->node->held = ['driver' => 'serve-driver-20261121', 'workers' => $workers];
    $this->reads->reads = false;
    // Bypass install by making env tokens non-due? They are due, so fail mint and check requireLiveTokens via container-mode or directly:
    // Use container mode where install throws? Instead, test requireLiveTokens with a valid read/run? No: put canary in held driver & env via reflection or container-off?
    // Even simpler: SwampGatewayToken::held() returns '' for canary, and if canary is in inventory.held.driver too:
    $this->node->held['driver'] = $canary;
    $this->node->access[''] = ['name' => '', 'principal' => null, 'expiresAt' => '2026-12-21T00:00:00.000Z', 'state' => 'active'];
    $this->reads->reads = true;

    expect(SwampGatewayToken::held($canary))->toBe('');
    expect(fn () => app(RenewSwampCredentialsAction::class)->execute(true))->toThrow(function (ResourceOperationException $exception) use ($canary): void {
        expect($exception->errorCode)->toBe('gateway.release_swamp_token_expiring')
            ->and($exception->getMessage())->toContain('serve-driver (not listed or malformed)')
            ->not->toContain($canary);
    });
});

it('raises near-expiry assistance for both Gateway tokens from installed expiry metadata when SSH inventory fails', function (): void {
    $path = swampCredentialsEnvironment();
    file_put_contents($path, implode("\n", [
        'APP_NAME=Orbit',
        'ORBIT_SWAMP_READ_TOKEN=orbit-gateway-read-20261101.fake-secret-read',
        'ORBIT_SWAMP_READ_TOKEN_EXPIRES_AT=2026-11-23T00:00:00.000000Z',
        'ORBIT_SWAMP_RUN_TOKEN=orbit-gateway-run-20261101.fake-secret-run',
        'ORBIT_SWAMP_RUN_TOKEN_EXPIRES_AT=2026-11-23T00:00:00.000000Z',
    ])."\n");
    chmod($path, 0600);
    app(TaskExtensionState::class)->enable();
    Project::query()->create(['slug' => 'orbit', 'name' => 'Orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $this->node->failList = 'swamp.token_list_failed: ssh unreachable';

    expect(fn () => app(RenewSwampCredentialsAction::class)->execute())->toThrow(RuntimeException::class, 'ssh unreachable');

    $titles = Task::topLevel()->orderBy('id')->pluck('title')->all();
    expect($titles)->toBe(['Renew the Gateway Swamp read token', 'Renew the Gateway Swamp run token']);
});

it('sends --mint-for-host errors strictly to stderr and never into stdout', function (): void {
    swampCredentialsEnvironment();
    $this->reads->reads = false;

    $stdout = new BufferedOutput;
    $stderr = new BufferedOutput;
    $io = new class($stdout, $stderr) extends BufferedOutput implements ConsoleOutputInterface
    {
        public function __construct(private readonly BufferedOutput $out, private readonly BufferedOutput $err)
        {
            parent::__construct();
        }

        public function write(string|iterable $messages, bool $newline = false, int $options = 0): void
        {
            $this->out->write($messages, $newline, $options);
        }

        public function getErrorOutput(): OutputInterface
        {
            return $this->err;
        }

        public function setErrorOutput(OutputInterface $error): void {}

        public function section(): ConsoleSectionOutput
        {
            throw new BadMethodCallException;
        }
    };

    $exit = app(Kernel::class)->handle(
        new StringInput('gateway:swamp-credentials --mint-for-host=read --no-interaction'),
        $io,
    );

    expect($exit)->toBe(1)
        ->and($stdout->fetch())->toBe('')
        ->and($stderr->fetch())->toContain('gateway.swamp_credentials_failed: The new orbit-gateway-read-20261121 could not read Swamp.');
});

it('ends without error when a release holds the release lock, and prints no secret', function (): void {
    swampCredentialsEnvironment();
    $code = null;

    app(GatewayReleaseLock::class)->run(function () use (&$code): void {
        $code = Artisan::call('gateway:swamp-credentials');
    });

    expect($code)->toBe(0)->and(Artisan::output())->toContain('release lock')->not->toContain('fake-secret');
});

it('reads exit 127 naming the entrypoint as an unconverged Swamp Node, and nothing else', function (): void {
    $missing = new CommandResult(127, '', 'bash: line 1: '.SwampNodeTokens::Entrypoint.': No such file or directory', 1, false);
    $inner = new CommandResult(127, '', 'timeout: failed to run command jq: No such file or directory', 1, false);

    expect(SwampNodeTokens::unconverged($missing))->toBeTrue()
        ->and(SwampNodeTokens::unconverged($inner))->toBeFalse()
        ->and(SwampNodeTokens::unconverged(new CommandResult(1, '', SwampNodeTokens::Entrypoint, 1, false)))->toBeFalse();
});

it('skips a release, its revoke and its host mint with one clear line on an unconverged Swamp Node, but fails the hourly run', function (): void {
    swampCredentialsEnvironment();
    $this->node->unconverged = true;

    foreach (['--release', '--revoke', '--mint-for-host=read'] as $option) {
        expect(Artisan::call('gateway:swamp-credentials '.$option.' --no-interaction'))->toBe(0, $option)
            ->and(Artisan::output())->not->toContain('fake-secret');
    }
    expect(Artisan::call('gateway:swamp-credentials --no-interaction'))->toBe(1)
        ->and(Artisan::output())->toContain('swamp.gateway_token_entrypoint_missing');
});
