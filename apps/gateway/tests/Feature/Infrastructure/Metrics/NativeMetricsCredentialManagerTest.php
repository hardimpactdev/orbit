<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCredentialOperationLock;
use App\Domain\Metrics\MetricsCredentialRuntime;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Infrastructure\Metrics\NativeMetricsCredentialOperationLock;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use App\Models\Setting;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

afterEach(function (): void {
    Str::createRandomStringsNormally();
});

describe(NativeMetricsCredentialManager::class, function (): void {
    it('creates one encrypted password and reuses it during convergence', function (): void {
        Str::createRandomStringsUsing(static fn (int $length): string => str_repeat('a', $length));
        $node = metricsCredentialNode();
        $manager = new NativeMetricsCredentialManager(
            app(SettingRepository::class),
            new MetricsCredentialRuntimeFake,
            app(MetricsCredentialOperationLock::class),
        );

        $first = $manager->passwordForConvergence($node);
        $second = $manager->passwordForConvergence($node);
        $stored = Setting::query()
            ->where('scope_type', SettingScopeType::Node->value)
            ->where('scope_id', $node->id)
            ->where('key', NativeMetricsCredentialManager::ActivePasswordKey)
            ->sole();

        expect($first)
            ->toBe(str_repeat('a', 32))
            ->and($second)
            ->toBe($first)
            ->and($stored->is_secret)
            ->toBeTrue()
            ->and($stored->value)
            ->not->toBe($first);
    });

    it('returns only an active password that Grafana verifies', function (): void {
        Str::createRandomStringsUsing(static fn (int $length): string => str_repeat('v', $length));
        $activePassword = Str::random(32);
        $node = metricsCredentialNode();
        $runtime = new MetricsCredentialRuntimeFake;
        $manager = new NativeMetricsCredentialManager(
            app(SettingRepository::class),
            $runtime,
            app(MetricsCredentialOperationLock::class),
        );
        metricsCredentialSettings()->put(
            metricsCredentialScope($node),
            NativeMetricsCredentialManager::ActivePasswordKey,
            $activePassword,
            SettingValueProtection::Secret,
        );

        $credentials = $manager->credentials();

        expect($credentials->toArray())
            ->toBe([
                'url' => 'https://metrics.orbit',
                'username' => 'admin',
                'password' => $activePassword,
            ])
            ->and($runtime->verified)
            ->toBe([[$node->id, $activePassword]]);
    });

    it('creates one initial password when another manager contends and then reuses authoritative state', function (): void {
        $generated = 0;
        Str::createRandomStringsUsing(static function (int $length) use (&$generated): string {
            $generated++;

            return str_repeat('i', $length);
        });
        $node = metricsCredentialNode();
        $settings = metricsCredentialSettings();
        $coordinator = new MetricsCredentialLockCoordinator;
        $owner = new NativeMetricsCredentialManager(
            $settings,
            new MetricsCredentialRuntimeFake,
            new MetricsCredentialOperationLockFake($coordinator, 'owner'),
        );
        $waiter = new NativeMetricsCredentialManager(
            $settings,
            new MetricsCredentialRuntimeFake,
            new MetricsCredentialOperationLockFake($coordinator, 'waiter'),
        );
        $refusal = null;
        $coordinator->pause = function () use ($waiter, $node, &$refusal): void {
            try {
                $waiter->passwordForConvergence($node);
            } catch (ResourceOperationException $exception) {
                $refusal = $exception;
            }
        };

        $created = $owner->passwordForConvergence($node);
        $reused = $waiter->passwordForConvergence($node);

        expect($refusal?->errorCode)
            ->toBe('metrics.credentials_busy')
            ->and($created)
            ->toBe(str_repeat('i', 32))
            ->and($reused)
            ->toBe($created)
            ->and($generated)
            ->toBe(1);
    });

    it('does not mutate credential state when native ownership acquisition is refused', function (): void {
        $node = metricsCredentialNode();
        $directory = sys_get_temp_dir().'/orbit-metrics-credential-state-'.Str::uuid();
        mkdir($directory, permissions: 0o700, recursive: true);
        $held = fopen($directory."/node-{$node->id}.lock", mode: 'c+');
        expect($held)->not->toBeFalse();
        flock($held, LOCK_EX);
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $manager = new NativeMetricsCredentialManager(
            metricsCredentialSettings(),
            new MetricsCredentialRuntimeFake,
            new NativeMetricsCredentialOperationLock(
                $directory,
                new CommandDeadline($clock),
                $clock,
                static function (int $microseconds) use (&$now): void {
                    $now += $microseconds / 1_000_000;
                },
            ),
        );

        try {
            expect(fn () => $manager->passwordForConvergence($node))
                ->toThrow(function (ResourceOperationException $exception): void {
                    expect($exception->errorCode)
                        ->toBe('metrics.credentials_busy')
                        ->and($exception->status)
                        ->toBe(409);
                });

            expect(metricsCredentialSettings()->get(
                metricsCredentialScope($node),
                NativeMetricsCredentialManager::ActivePasswordKey,
            ))
                ->toBeNull()
                ->and(round($now, 1))
                ->toBe(30.0);
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('purges only the active and pending credential settings', function (): void {
        $node = metricsCredentialNode();
        $manager = new NativeMetricsCredentialManager(
            app(SettingRepository::class),
            new MetricsCredentialRuntimeFake,
            app(MetricsCredentialOperationLock::class),
        );
        $scope = metricsCredentialScope($node);
        $settings = metricsCredentialSettings();
        $settings->put(
            $scope,
            NativeMetricsCredentialManager::ActivePasswordKey,
            'active',
            SettingValueProtection::Secret,
        );
        $settings->put(
            $scope,
            NativeMetricsCredentialManager::PendingPasswordKey,
            'pending',
            SettingValueProtection::Secret,
        );
        $settings->put($scope, 'metrics.exporter.preference', 'enabled');

        $manager->purge($node);

        expect($settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey))
            ->toBeNull()
            ->and($settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey))
            ->toBeNull()
            ->and($settings->get($scope, 'metrics.exporter.preference'))
            ->toBe('enabled');
    });

    it('fails closed when the assignment or verified credential is unavailable', function (): void {
        $runtime = new MetricsCredentialRuntimeFake;
        $manager = new NativeMetricsCredentialManager(
            app(SettingRepository::class),
            $runtime,
            app(MetricsCredentialOperationLock::class),
        );

        expect(fn () => $manager->credentials())
            ->toThrow(ResourceOperationException::class, 'Metrics is not assigned.');

        $node = metricsCredentialNode();
        expect(fn () => $manager->credentials())
            ->toThrow(ResourceOperationException::class, 'Grafana credentials are unavailable.');

        metricsCredentialSettings()->put(
            metricsCredentialScope($node),
            NativeMetricsCredentialManager::ActivePasswordKey,
            'unverified-password',
            SettingValueProtection::Secret,
        );
        $runtime->accepts = false;

        expect(fn () => $manager->credentials())
            ->toThrow(ResourceOperationException::class, 'Grafana rejected the active credential.');
    });
});

function metricsCredentialNode(): Node
{
    $node = Node::query()->create([
        'name' => 'metrics-credentials',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.73',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $node->roles()->create([
        'role' => RoleName::Metrics,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

function metricsCredentialSettings(): SettingRepository
{
    return app(SettingRepository::class);
}

function metricsCredentialScope(Node $node): SettingScope
{
    return new SettingScope(SettingScopeType::Node, $node->id);
}

final class MetricsCredentialRuntimeFake implements MetricsCredentialRuntime
{
    public bool $accepts = true;

    public ?RuntimeException $applyFailure = null;

    /** @var list<RuntimeException> */
    public array $verifyFailures = [];

    /**
     * What Grafana actually answers to. When the fake holds a credential it
     * accepts only that one, and `apply()` is the only thing that changes it,
     * which is how a partial reset leaves a pending credential unusable.
     */
    private ?string $held = null;

    /** @var list<array{int, string, string}> */
    public array $applied = [];

    /** @var list<array{int, string}> */
    public array $verified = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    public function apply(
        Node $node,
        #[SensitiveParameter]
        string $activePassword,
        #[SensitiveParameter]
        string $pendingPassword,
    ): void {
        $this->transactionLevels[] = DB::transactionLevel();

        if ($this->applyFailure instanceof RuntimeException) {
            throw $this->applyFailure;
        }

        $this->applied[] = [$node->id, $activePassword, $pendingPassword];

        if ($this->held !== null) {
            $this->held = $pendingPassword;
        }
    }

    public function holds(#[SensitiveParameter] string $credential): void
    {
        $this->held = $credential;
    }

    public function held(): ?string
    {
        return $this->held;
    }

    public function verify(Node $node, #[SensitiveParameter] string $password): bool
    {
        $this->transactionLevels[] = DB::transactionLevel();
        $this->verified[] = [$node->id, $password];

        $failure = array_shift($this->verifyFailures);

        if ($failure instanceof RuntimeException) {
            throw $failure;
        }

        if ($this->held !== null) {
            return hash_equals($this->held, $password);
        }

        return $this->accepts;
    }
}

final class MetricsCredentialLockCoordinator
{
    public ?int $owner = null;

    public ?Closure $pause = null;

    /** @var list<string> */
    public array $events = [];

    /** @var list<int> */
    public array $transactionLevels = [];
}

final readonly class MetricsCredentialOperationLockFake implements MetricsCredentialOperationLock
{
    public function __construct(
        private MetricsCredentialLockCoordinator $coordinator,
        private string $name,
    ) {}

    public function run(int $nodeId, Closure $operation): mixed
    {
        if ($this->coordinator->owner !== null) {
            $this->coordinator->events[] = "{$this->name}:refused";

            throw new ResourceOperationException(
                'metrics.credentials_busy',
                'Another Metrics credential operation is active for this Node. Retry the request.',
                409,
            );
        }

        $this->coordinator->owner = $nodeId;
        $this->coordinator->events[] = "{$this->name}:acquired";
        $this->coordinator->transactionLevels[] = DB::transactionLevel();
        $pause = $this->coordinator->pause;
        $this->coordinator->pause = null;

        try {
            $pause?->__invoke();

            return $operation();
        } finally {
            $this->coordinator->owner = null;
            $this->coordinator->events[] = "{$this->name}:released";
        }
    }
}
