<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Git\GitRegistrationDiscovery;
use App\Services\Git\GitRegistrationFacts;
use App\Services\Git\NativeGitRegistrationDiscovery;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Instances\CreateInstanceRequest;
use Orbit\Sdk\Requests\Instances\DestroyInstanceRequest;
use Orbit\Sdk\Requests\Instances\DisableInstanceAutoDeployRequest;
use Orbit\Sdk\Requests\Instances\EnableInstanceAutoDeployRequest;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Requests\Instances\RegisterInstanceRequest;
use Orbit\Sdk\Requests\Instances\RenameInstanceRequest;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;
use Orbit\Sdk\Requests\Instances\UpdateInstanceRequest;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Support/InstanceSourceOutput.php';

beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=400');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
    $this->registrationGit = new class implements GitRegistrationDiscovery
    {
        public ?GitRegistrationFacts $facts;

        public function __construct()
        {
            $this->facts = new GitRegistrationFacts(
                path: '/work/acme',
                repositoryUrl: 'git@github.com:acme/acme.git',
            );
        }

        public function inspect(string $path): ?GitRegistrationFacts
        {
            return $this->facts;
        }
    };
    app()->instance(GitRegistrationDiscovery::class, $this->registrationGit);
});

describe('instance:rename', function (): void {
    it('sends the selected fields and renders the Instance in human and JSON modes', function (bool $json, array $fields): void {
        $mock = MockClient::global([RenameInstanceRequest::class => instance_mock_response()]);
        $arguments = ['instance' => 3, ...$fields];
        if ($json) {
            $arguments['--json'] = true;
        }
        expect(Artisan::call('instance:rename', $arguments))->toBe(0);
        $body = [];
        foreach ($fields as $key => $value) {
            $body[substr($key, 2)] = $value;
        }
        expect($mock->getLastRequest())->toBeInstanceOf(RenameInstanceRequest::class)
            ->and($mock->getLastRequest()?->body()->all())->toBe($body);
        if ($json) {
            expect(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('id', 5);
        } else {
            expect(instance_source_text(Artisan::output()))->toContain('Instance:', 'Selected branch');
        }
    })->with([false, true])->with([
        'branch' => [['--branch' => 't3code/login']],
        'domain' => [['--domain' => 'login.example.test']],
        'both' => [['--branch' => 't3code/login', '--domain' => 'login.example.test']],
    ]);

    it('requires a positive Instance ID and at least one field before HTTP', function (array $arguments, string $code): void {
        $mock = MockClient::global();
        expect(Artisan::call('instance:rename', [...$arguments, '--json' => true]))->toBe(1);
        expect(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('error.code', $code)
            ->and($mock->getLastPendingRequest())->toBeNull();
    })->with([
        'invalid ID' => [['instance' => 'abc', '--branch' => 't3code/login'], 'instance.id_invalid'],
        'no fields' => [['instance' => 3], 'validation.failed'],
        'empty branch with domain' => [['instance' => 3, '--branch' => '', '--domain' => 'login.example.test'], 'validation.failed'],
        'empty domain with branch' => [['instance' => 3, '--domain' => '', '--branch' => 't3code/login'], 'validation.failed'],
    ]);

    it('preserves HEAD refusal and correlation in JSON errors', function (): void {
        MockClient::global([RenameInstanceRequest::class => MockResponse::make([
            'error' => ['code' => 'instance.branch_not_checked_out', 'message' => 'HEAD is not on the requested branch.', 'details' => [], 'request_id' => 'a6cc838a-e4a2-42fb-b2e6-ffabbe9c0d07'],
        ], 409)]);
        expect(Artisan::call('instance:rename', ['instance' => 3, '--branch' => 't3code/login', '--json' => true]))->toBe(1);
        expect(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('error.code', 'instance.branch_not_checked_out');
    });
});

describe('instance:register', function (): void {
    it('refuses outside Git before sending a request', function (): void {
        $this->registrationGit->facts = null;
        $mockClient = MockClient::global();

        $this
            ->artisan('instance:register', ['--path' => '/tmp/not-git'])
            ->expectsOutputToContain('The current path is not a supported Git checkout or worktree.')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    });

    it('registers the source after explicit ownership consent', function (): void {
        $mockClient = MockClient::global([RegisterInstanceRequest::class => registration_mock_response()]);
        expect(Artisan::call('instance:register', ['--yes' => true]))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'Instance registered: default', 'ID 5', 'Selected branch main', 'Request ID '.instance_request_id(),
        );
        expect($mockClient->getLastRequest())->toBeInstanceOf(RegisterInstanceRequest::class)
            ->and($mockClient->getLastRequest()?->body()->all())->toBe(['source_path' => '/work/acme']);
    });

    it('tells a human that the Gateway registered the requested name as default', function (): void {
        MockClient::global([RegisterInstanceRequest::class => registration_mock_response()]);

        expect(Artisan::call('instance:register', ['--yes' => true, '--name' => 'main']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'Instance registered: default', 'Selected branch main',
        );
    });

    it('renders the canonical registered Instance name in JSON', function (): void {
        MockClient::global([RegisterInstanceRequest::class => registration_mock_response()]);

        expect(Artisan::call('instance:register', ['--json' => true, '--yes' => true, '--name' => 'main']))->toBe(0);
        expect(json_decode(trim(Artisan::output()), associative: true, flags: JSON_THROW_ON_ERROR))
            ->toHaveKey('name', 'default')
            ->toHaveKey('selected_branch', 'main');
    });

    it('registers with --json on an interactive terminal without a prompt or prose', function (): void {
        $mockClient = MockClient::global([
            RegisterInstanceRequest::class => registration_mock_response(),
        ]);

        $exitCode = Artisan::call('instance:register', ['--json' => true, '--yes' => true]);
        $output = trim(Artisan::output());

        expect($exitCode)->toBe(0);
        expect($output)
            ->toBe(registration_json())
            ->not->toContain("\n", 'Source:', 'Transfer this source to Orbit ownership?');
        expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(json_decode(registration_json(), associative: true, flags: JSON_THROW_ON_ERROR));
        expect($mockClient->getLastRequest()?->body()->all())->toBe([
            'source_path' => '/work/acme',
        ]);
    });

    it('omits inferred creation values when canonical repository lookup can resolve a different Project identity', function (): void {
        $this->registrationGit->facts = new GitRegistrationFacts(
            path: '/work/legacy-default',
            repositoryUrl: 'https://github.com/laravel/laravel.git',
        );
        $mockClient = MockClient::global([
            RegisterInstanceRequest::class => registration_mock_response(),
        ]);

        $this
            ->artisan('instance:register', [
                '--yes' => true,
                '--no-interaction' => true,
                '--json' => true,
            ])
            ->expectsOutput(registration_json())
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe([
            'source_path' => '/work/legacy-default',
        ]);
    });

    it('transports a root override for the existing Project', function (): void {
        $mockClient = MockClient::global([
            RegisterInstanceRequest::class => registration_mock_response(),
        ]);

        $this
            ->artisan('instance:register', [
                '--yes' => true,
                '--root' => 'web',
                '--no-interaction' => true,
                '--json' => true,
            ])
            ->expectsOutput(registration_json())
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe([
            'source_path' => '/work/acme',
            'root' => 'web',
        ]);
    });

    it('renders the Gateway refusal when no Project owns the repository', function (): void {
        MockClient::global([
            RegisterInstanceRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'instance.project_missing',
                    'message' => 'No Project owns repository [git@github.com:acme/acme.git]. Create it with `orbit project:create` first.',
                    'request_id' => 'request-id',
                ],
            ], 422),
        ]);

        $exitCode = Artisan::call('instance:register', ['--yes' => true, '--no-interaction' => true, '--json' => true]);

        expect($exitCode)->toBe(1)
            ->and(json_decode(trim(Artisan::output()), associative: true, flags: JSON_THROW_ON_ERROR)['error']['code'])
            ->toBe('instance.project_missing');
    });

    it('transports include worktrees and omits inferred values for a selected Project', function (): void {
        $mockClient = MockClient::global([
            RegisterInstanceRequest::class => registration_mock_response(),
        ]);

        $this
            ->artisan('instance:register', [
                '--yes' => true,
                '--project' => '3',
                '--include-worktrees' => true,
                '--name' => 'feature',
                '--domain' => 'feature.test',
                '--json' => true,
                '--no-interaction' => true,
            ])
            ->expectsOutput(registration_json())
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe([
            'source_path' => '/work/acme',
            'include_worktrees' => true,
            'project_id' => 3,
            'instance_name' => 'feature',
            'domain' => 'feature.test',
        ]);
    });

    it('rejects the removed hostname option', function (): void {
        $mockClient = MockClient::global();
        $tester = new CommandTester(app(Kernel::class)->all()['instance:register']);

        expect($tester->execute([
            '--hostname' => 'feature.test',
            '--json' => true,
            '--no-interaction' => true,
        ], ['interactive' => false]))->toBe(1);
        expect(json_decode(trim($tester->getDisplay()), associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => [
                'code' => 'input.invalid',
                'message' => 'The "--hostname" option does not exist.',
                'request_id' => null,
            ],
        ]);
        expect($mockClient->getLastPendingRequest())->toBeNull();
    });
});

describe('credential-bearing registration origins', function (): void {
    it('refuses a credential-bearing discovered origin without printing it', function (bool $json): void {
        $userinfo = Str::random(12).':'.Str::random(24);
        $this->registrationGit->facts = new GitRegistrationFacts(
            path: '/work/acme',
            repositoryUrl: "https://{$userinfo}@example.test/acme.git",
        );
        $mockClient = MockClient::global();

        $this
            ->artisan('instance:register', [
                '--yes' => true,
                '--json' => $json,
                '--no-interaction' => true,
            ])
            ->expectsOutputToContain('not a supported Git checkout or worktree')
            ->doesntExpectOutputToContain($userinfo)
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    })->with(['interactive output' => false, 'JSON output' => true]);

    it('rejects a credential-bearing native Git origin during local discovery', function (): void {
        $directory = sys_get_temp_dir().'/orbit-cli-origin-'.Str::uuid();
        $userinfo = Str::random(12).':'.Str::random(24);
        $files = new Filesystem;
        $files->ensureDirectoryExists($directory);

        try {
            $commands = [
                ['git', 'init', '--initial-branch=main', $directory],
                ['git', '-C',   $directory,              'config',   'user.email', 'orb105@example.test'],
                ['git', '-C',   $directory,              'config',   'user.name',  'ORB-105'],
            ];
            file_put_contents(filename: $directory.'/README.md', data: "test\n");
            $commands[] = ['git', '-C', $directory, 'add', 'README.md'];
            $commands[] = ['git', '-C', $directory, 'commit', '-m', 'Initial'];
            $commands[] = [
                'git',
                '-C',
                $directory,
                'remote',
                'add',
                'origin',
                "https://{$userinfo}@example.test/acme.git",
            ];

            foreach ($commands as $command) {
                new Process($command)->mustRun();
            }

            expect(new NativeGitRegistrationDiscovery()->inspect($directory))->toBeNull();
        } finally {
            $files->deleteDirectory($directory);
        }
    });
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('instance:create', function (): void {
    it('documents the development contract and directs production to instance:clone', function (): void {
        $this
            ->artisan('help', ['command_name' => 'instance:create'])
            ->expectsOutputToContain('Create a development Instance on an app-dev Node.')
            ->expectsOutputToContain('default, or the Project default branch name, means the default development source')
            ->expectsOutputToContain('New production Instances require a candidate. Use instance:clone.')
            ->assertExitCode(0);
    });

    it('creates an Instance with inherited root as JSON', function (): void {
        $mockClient = MockClient::global(instance_operation_responses(CreateInstanceRequest::class, 'instance.create'));

        $this
            ->artisan('instance:create', [
                'project' => '3',
                'node' => '2',
                'name' => 'dev',
                '--json' => true,
            ])
            ->expectsOutput(instance_operation_json('instance.create'))
            ->assertExitCode(0);

        $request = $mockClient->getRecordedResponses()[0]->getPendingRequest()->getRequest();

        expect($mockClient->getRecordedResponses()[0]->getPendingRequest()->getUrl())
            ->toBe('https://10.44.0.1/api/v1/instances')
            ->and($request)
            ->toBeInstanceOf(CreateInstanceRequest::class)
            ->and($request?->body()->all())
            ->toBe(['project_id' => 3, 'node_id' => 2, 'name' => 'dev']);
    });

    it('transports an optional root override without execution controls', function (): void {
        $mockClient = MockClient::global(instance_operation_responses(CreateInstanceRequest::class, 'instance.create'));

        $this
            ->artisan('instance:create', [
                'project' => '3',
                'node' => '2',
                'name' => 'dev',
                '--root' => 'site/public',
            ])
            ->assertExitCode(0);

        expect($mockClient->getRecordedResponses()[0]->getPendingRequest()->getRequest()?->body()->all())->toBe([
            'project_id' => 3,
            'node_id' => 2,
            'name' => 'dev',
            'root' => 'site/public',
        ]);
    });

    it('transports an optional Route domain without local policy validation', function (): void {
        $mockClient = MockClient::global(instance_operation_responses(CreateInstanceRequest::class, 'instance.create'));

        $this
            ->artisan('instance:create', [
                'project' => '3',
                'node' => '2',
                'name' => 'dev',
                '--domain' => 'Odd_Value',
            ])
            ->assertExitCode(0);

        expect($mockClient->getRecordedResponses()[0]->getPendingRequest()->getRequest()?->body()->all())->toBe([
            'project_id' => 3,
            'node_id' => 2,
            'name' => 'dev',
            'domain' => 'Odd_Value',
        ]);
    });

    it('rejects the removed hostname option', function (): void {
        $mockClient = MockClient::global();
        $tester = new CommandTester(app(Kernel::class)->all()['instance:create']);

        expect($tester->execute([
            'project' => '3',
            'node' => '2',
            'name' => 'dev',
            '--hostname' => 'Odd_Value',
            '--json' => true,
        ], ['interactive' => false]))->toBe(1);
        expect(json_decode(trim($tester->getDisplay()), associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => [
                'code' => 'input.invalid',
                'message' => 'The "--hostname" option does not exist.',
                'request_id' => null,
            ],
        ]);
        expect($mockClient->getLastPendingRequest())->toBeNull();
    });

    it('transports an optional branch without local policy validation', function (): void {
        $mockClient = MockClient::global(instance_operation_responses(CreateInstanceRequest::class, 'instance.create'));

        $this
            ->artisan('instance:create', [
                'project' => '3',
                'node' => '2',
                'name' => 'default',
                '--branch' => 'release',
            ])
            ->assertExitCode(0);

        expect($mockClient->getRecordedResponses()[0]->getPendingRequest()->getRequest()?->body()->all())->toBe([
            'project_id' => 3,
            'node_id' => 2,
            'name' => 'default',
            'branch' => 'release',
        ]);
    });

    it('reports the created Instance for humans', function (): void {
        MockClient::global(instance_operation_responses(CreateInstanceRequest::class, 'instance.create'));

        expect(Artisan::call('instance:create', ['project' => '3', 'node' => '2', 'name' => 'dev']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'Instance creation succeeded.', 'Operation: 901', 'Request ID: '.instance_request_id(),
        );
    });

    it('renders the production candidate refusal for humans', function (): void {
        $mock = MockClient::global([CreateInstanceRequest::class => MockResponse::make([
            'error' => ['code' => 'instance.candidate_required', 'message' => 'Production Instances require instance:clone.'],
        ], 409, ['X-Orbit-Request-Id' => instance_request_id()])]);

        expect(Artisan::call('instance:create', ['project' => '3', 'node' => '2', 'name' => 'production']))->toBe(1);
        expect(instance_source_text(Artisan::output()))->toContain(
            'Production Instances require instance:clone.', 'Request ID: '.instance_request_id(),
        );
        expect($mock->getRecordedResponses())->toHaveCount(1);
    });
});

describe('instance:list', function (): void {
    it('lists Instances as JSON', function (): void {
        MockClient::destroyGlobal();
        MockClient::global([
            ListInstancesRequest::class => MockResponse::make([
                'data' => [instance_payload()],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);
        $expected = json_encode([
            'instances' => [[
                ...instance_payload(),
                'route' => [...instance_route_payload(), 'request_id' => instance_request_id()],
                'routes' => [[...instance_route_payload(), 'request_id' => instance_request_id()]],
            ]],
            'request_id' => instance_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this
            ->artisan('instance:list', ['--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(0);
    });

    it('lists Instance source identity for humans', function (): void {
        MockClient::global([
            ListInstancesRequest::class => MockResponse::make([
                'data' => [instance_payload()],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);

        expect(Artisan::call('instance:list'))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'ID PROJECT NODE VITE PORT NAME SOURCE LAYOUT ROOT SELECTED BRANCH BRANCH OVERRIDE ROUTE DOMAIN URL STATUS REMOVAL',
            '5 3 2 — dev checkout public dev — dev.orbit.test https://dev.orbit.test active —',
            'Request ID: '.instance_request_id(),
        );
    });

    it('lists bounded unfinished removal progress for humans and JSON', function (): void {
        $payload = instance_payload(removal: removal_progress_payload());
        MockClient::global([
            ListInstancesRequest::class => MockResponse::make([
                'data' => [$payload],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);

        expect(Artisan::call('instance:list'))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain('normal 0/1 completed; 1 remaining; runtime_cleanup; failed runtime_cleanup (instance.runtime_interrupted)');

        MockClient::destroyGlobal();
        MockClient::global([
            ListInstancesRequest::class => MockResponse::make([
                'data' => [$payload],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);
        $this
            ->artisan('instance:list', ['--json' => true])
            ->expectsOutputToContain('"removal":{"operation_id":"0198e15d-16c4-7855-8eb2-182b53ad28bb"')
            ->assertExitCode(0);
    });
});

describe('instance:update', function (): void {
    it('shows the accepted deployment branch separately from the unchanged source branch', function (): void {
        $payload = instance_payload();
        $payload['selected_branch'] = 'main';
        $payload['deployment_branch'] = 'stored-release';
        $mock = MockClient::global([
            UpdateInstanceRequest::class => instance_mock_response(payload: $payload),
        ]);

        expect(Artisan::call('instance:update', ['instance' => '5', '--branch' => 'release/next']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain('Deployment branch stored-release Selected branch main');
        expect($mock->getLastRequest()?->body()->all())->toBe(['branch' => 'release/next']);
    });

    it('updates the deployment branch without sending steps', function (): void {
        $mock = MockClient::global([
            UpdateInstanceRequest::class => instance_mock_response(),
        ]);

        $this
            ->artisan('instance:update', ['instance' => '5', '--branch' => 'release/next', '--json' => true])
            ->expectsOutput(instance_json())
            ->assertExitCode(0);

        expect($mock->getLastRequest())
            ->toBeInstanceOf(UpdateInstanceRequest::class)
            ->and($mock->getLastRequest()?->body()->all())->toBe(['branch' => 'release/next']);
    });
    it('updates shared paths without changing the branch and renders the accepted paths', function (string $raw, ?array $paths, array $effective, bool $json): void {
        $payload = instance_payload();
        $payload['shared_paths'] = $effective;
        $mock = MockClient::global([UpdateInstanceRequest::class => instance_mock_response(payload: $payload)]);
        $arguments = ['instance' => '5', '--shared-paths' => $raw, '--json' => $json];

        expect(Artisan::call('instance:update', $arguments))->toBe(0);
        expect($mock->getLastRequest()?->body()->all())->toBe(['shared_paths' => $paths]);
        if ($json) {
            expect(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['shared_paths'])->toBe($effective);
        } else {
            expect(instance_source_text(Artisan::output()))->toContain('Shared paths '.($effective === [] ? 'None' : implode(', ', $effective)))
                ->not->toContain('Deployment branch');
        }
    })->with([
        'replace' => ['["storage","var/uploads"]', ['storage', 'var/uploads'], ['storage', 'var/uploads']],
        'disable' => ['[]', [], []],
        'default' => ['null', null, ['storage']],
    ])->with([false, true]);

    it('updates the branch and shared paths in one request', function (): void {
        $mock = MockClient::global([UpdateInstanceRequest::class => instance_mock_response()]);

        expect(Artisan::call('instance:update', ['instance' => '5', '--branch' => 'release', '--shared-paths' => '[]', '--json' => true]))->toBe(0);
        expect($mock->getLastRequest()?->body()->all())->toBe(['branch' => 'release', 'shared_paths' => []]);
    });

    it('refuses malformed shared path input before sending a request', function (?string $raw): void {
        $mock = MockClient::global([UpdateInstanceRequest::class => instance_mock_response()]);

        expect(Artisan::call('instance:update', ['instance' => '5', '--shared-paths' => $raw, '--json' => true]))->toBe(1);
        expect(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR))
            ->toMatchArray(['error' => ['code' => 'instance.shared_paths_invalid', 'message' => 'Shared paths must be a JSON list of directory paths or null.', 'request_id' => null]]);
        $mock->assertNothingSent();
    })->with(['', 'storage', '{}', '{"0":"storage"}', '[false]', '[null]', '["storage",]', 'no value' => [null]]);

    it('refuses an empty branch even when shared paths are supplied', function (): void {
        $mock = MockClient::global([UpdateInstanceRequest::class => instance_mock_response()]);

        expect(Artisan::call('instance:update', ['instance' => '5', '--branch' => '', '--shared-paths' => '[]', '--json' => true]))->toBe(1);
        expect(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('instance.branch_required');
        $mock->assertNothingSent();
    });

    it('requires a branch or shared paths before sending a request', function (): void {
        $mock = MockClient::global([UpdateInstanceRequest::class => instance_mock_response()]);

        expect(Artisan::call('instance:update', ['instance' => '5', '--json' => true]))->toBe(1);
        expect(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('instance.update_required');
        $mock->assertNothingSent();
    });
});

describe('instance:auto-deploy:enable and disable', function (): void {
    it('turns automatic deployment on, shows the gate, and sends no body', function (): void {
        $payload = [...instance_payload(), 'production_user' => 'orbit-app-3', 'auto_deploy' => instance_auto_deploy_payload(['enabled' => true, 'gate' => 'Required checks', 'result' => 'up_to_date', 'commit' => str_repeat('c', 40)])];
        $mock = MockClient::global([EnableInstanceAutoDeployRequest::class => instance_mock_response(payload: $payload)]);

        expect(Artisan::call('instance:auto-deploy:enable', ['instance' => '5']))->toBe(0);

        expect(instance_source_text(Artisan::output()))->toContain('Auto deploy on, after Required checks passes', 'Auto deploy state up_to_date cccccccccccc')
            ->and($mock->getLastRequest())->toBeInstanceOf(EnableInstanceAutoDeployRequest::class);
    });

    it('warns when every commit deploys without a merge check', function (): void {
        $warning = 'The Project has no merge_check, so every new commit on the branch deploys without a check.';
        $payload = [...instance_payload(), 'production_user' => 'orbit-app-3', 'auto_deploy' => instance_auto_deploy_payload(['enabled' => true, 'warning' => $warning])];
        MockClient::global([EnableInstanceAutoDeployRequest::class => instance_mock_response(payload: $payload)]);

        expect(Artisan::call('instance:auto-deploy:enable', ['instance' => '5']))->toBe(0);

        expect(instance_source_text(Artisan::output()))->toContain('Auto deploy on, without a merge check', $warning);
    });

    it('turns automatic deployment off', function (): void {
        $mock = MockClient::global([DisableInstanceAutoDeployRequest::class => instance_mock_response(payload: instance_payload())]);

        expect(Artisan::call('instance:auto-deploy:disable', ['instance' => '5']))->toBe(0);

        expect(instance_source_text(Artisan::output()))->toContain('Auto deploy off')
            ->and($mock->getLastRequest())->toBeInstanceOf(DisableInstanceAutoDeployRequest::class);
    });

    it('refuses an update without a field before it sends anything', function (): void {
        $mock = MockClient::global([UpdateInstanceRequest::class => instance_mock_response()]);

        $this->artisan('instance:update', ['instance' => '5', '--json' => true])
            ->expectsOutputToContain('instance.update_required')
            ->assertExitCode(1);

        expect($mock->getLastRequest())->toBeNull();
    });

    it('shows a paused production Instance and how to resume it', function (): void {
        $payload = [...instance_payload(), 'production_user' => 'orbit-app-3', 'production_home' => '/home/orbit-app-3', 'auto_deploy' => instance_auto_deploy_payload([
            'enabled' => true, 'gate' => 'Required checks', 'result' => 'paused', 'commit' => str_repeat('a', 40),
            'pause' => ['reason' => 'rollback', 'deployment_id' => 41, 'commit' => str_repeat('a', 40), 'paused_at' => '2026-10-15T10:00:00+00:00'],
        ])];
        MockClient::global([ShowInstanceRequest::class => instance_mock_response(payload: $payload), ListProcessesRequest::class => no_processes_response()]);

        expect(Artisan::call('instance:show', ['instance' => '5']))->toBe(0);

        expect(instance_source_text(Artisan::output()))->toContain('Auto deploy paused rollback (deployment 41); run instance:auto-deploy:enable 5 to resume');
    });
});

describe('instance:show', function (): void {
    it('shows attached SQL and Redis targets in human and JSON output without credentials', function (bool $json): void {
        $sql = [
            'kind' => 'database', 'driver' => 'pgsql', 'id' => 14, 'name' => 'food-catalog',
            'node' => ['id' => 3, 'name' => 'services'], 'host' => '10.44.0.3', 'port' => 5434,
            'database' => 'food_catalog', 'username' => 'catalog_reader', 'prefix' => 'FOOD_CATALOG',
            'server' => ['id' => 9, 'name' => 'catalog-postgres'],
        ];
        $redis = [
            'kind' => 'redis', 'driver' => 'redis', 'id' => 15, 'name' => 'cache',
            'node' => ['id' => 2, 'name' => 'beast'], 'host' => '10.44.0.2', 'port' => 6379,
            'database' => null, 'username' => null, 'prefix' => 'REDIS', 'server' => null,
        ];
        $payload = [...instance_payload(), 'attached_targets' => [
            [...$sql, 'password' => 'attachment-password-sentinel', 'vault_key' => 'attachment-vault-sentinel'],
            $redis,
        ]];
        MockClient::global([ShowInstanceRequest::class => instance_mock_response(payload: $payload), ListProcessesRequest::class => no_processes_response()]);

        expect(Artisan::call('instance:show', ['instance' => '5', '--json' => $json]))->toBe(0);
        $output = Artisan::output();
        expect($output)->not->toContain('attachment-password-sentinel', 'attachment-vault-sentinel');
        if ($json) {
            expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['attached_targets'])->toBe([$sql, $redis]);
        } else {
            expect(instance_source_text($output))->toContain('Attached targets', 'pgsql', 'food-catalog', 'services', '10.44.0.3:5434', 'food_catalog', 'catalog_reader', 'FOOD_CATALOG', 'catalog-postgres', 'redis', 'cache', 'beast', '10.44.0.2:6379');
        }
    })->with([false, true]);

    it('shows an empty attached target section and array when none are attached', function (): void {
        MockClient::global([ShowInstanceRequest::class => instance_mock_response(), ListProcessesRequest::class => no_processes_response()]);

        expect(Artisan::call('instance:show', ['instance' => '5']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain('Attached targets', 'No attached targets.');
        expect(Artisan::call('instance:show', ['instance' => '5', '--json' => true]))->toBe(0);
        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['attached_targets'])->toBe([]);
    });

    it('shows an Instance as JSON', function (): void {
        MockClient::global([ShowInstanceRequest::class => instance_mock_response(), ListProcessesRequest::class => no_processes_response()]);

        $this
            ->artisan('instance:show', ['instance' => '5', '--json' => true])
            ->expectsOutput(instance_json())
            ->assertExitCode(0);
    });

    it('shows retained Git paths and times in human and JSON output', function (): void {
        $payload = instance_payload();
        $payload['retained_git_path'] = '/home/orbit/apps/orbit-docs/dev.orbit-retired-git';
        $payload['retained_git_at'] = '2026-11-10T19:00:00+00:00';
        MockClient::global([ShowInstanceRequest::class => instance_mock_response(payload: $payload), ListProcessesRequest::class => no_processes_response()]);

        expect(Artisan::call('instance:show', ['instance' => '5']))->toBe(0);
        expect(instance_source_text(Artisan::output()))
            ->toContain('Retained Git /home/orbit/apps/orbit-docs/dev.orbit-retired-git', 'Git retention started 2026-11-10T19:00:00+00:00');

        expect(Artisan::call('instance:show', ['instance' => '5', '--json' => true]))->toBe(0);
        $data = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($data['retained_git_path'])->toBe($payload['retained_git_path'])
            ->and($data['retained_git_at'])->toBe($payload['retained_git_at']);
    });

    it('shows Instance source details for humans', function (): void {
        $payload = instance_payload();
        $payload['deployment_branch'] = 'release/next';
        MockClient::global([ShowInstanceRequest::class => instance_mock_response(payload: $payload), ListProcessesRequest::class => no_processes_response()]);

        expect(Artisan::call('instance:show', ['instance' => '5']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'Instance: dev ID 5 Project orbit-docs Node beast Status active',
            'Project orbit-docs',
            'Node beast',
            'Source layout checkout',
            'Checkout /home/orbit/apps/orbit-docs/dev',
            'Root override —',
            'Effective root public',
            'Selected branch dev',
            'Deployment branch release/next',
            'Branch override —',
            'Domain dev.orbit.test',
            'URL https://dev.orbit.test',
            'No Processes.',
        );
    });

    it('prints deploy steps in phase and placement order', function (): void {
        $payload = instance_payload();
        $payload['deploy_steps'] = [[
            'name' => 'migrate',
            'phase' => 'before_activation',
            'command' => 'php artisan migrate --force',
            'timeout_seconds' => 300,
        ]];
        MockClient::global([ShowInstanceRequest::class => instance_mock_response(payload: $payload), ListProcessesRequest::class => no_processes_response()]);

        expect(Artisan::call('instance:show', ['instance' => '5']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'NAME',
            'migrate',
            'before_activation',
            '300',
        );
    });

    it('shows bounded unfinished removal progress for humans', function (): void {
        $payload = instance_payload(removal: removal_progress_payload(force: true));
        MockClient::destroyGlobal();
        MockClient::global([ShowInstanceRequest::class => instance_mock_response(payload: $payload), ListProcessesRequest::class => no_processes_response()]);

        expect(Artisan::call('instance:show', ['instance' => '5']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'Instance: dev ID 5 Project orbit-docs Node beast Status removing',
            'Removal mode forced',
            'Removal progress 0/1 completed; 1 remaining',
            'Removal step runtime_cleanup',
            'Removal failed step runtime_cleanup',
            'Removal error code instance.runtime_interrupted',
        );
    });

    it('shows bounded unfinished removal progress as JSON', function (): void {
        $payload = instance_payload(removal: removal_progress_payload(force: true));
        MockClient::global([ShowInstanceRequest::class => instance_mock_response(payload: $payload), ListProcessesRequest::class => no_processes_response()]);

        $expected = json_encode([
            ...$payload,
            'route' => [...instance_route_payload(), 'request_id' => instance_request_id()],
            'routes' => [[...instance_route_payload(), 'request_id' => instance_request_id()]],
            'request_id' => instance_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this
            ->artisan('instance:show', ['instance' => '5', '--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(0);
    });
});

describe('instance:destroy', function (): void {
    it('keeps typed worktree refusal details in human and JSON output', function (bool $json): void {
        $details = ['dependents' => [['id' => 69, 'name' => 'default', 'project_id' => 12]],
            'other_dependents' => 2, 'unowned_paths' => ['/work/unowned tree']];
        MockClient::global([DestroyInstanceRequest::class => MockResponse::make(['error' => [
            'code' => 'instance.worktree_dependents', 'message' => 'Other worktrees depend on Git data in this checkout.',
            'details' => $details, 'request_id' => instance_request_id(),
        ]], 409, ['X-Orbit-Request-Id' => instance_request_id()])]);
        expect(Artisan::call('instance:destroy', ['instance' => '5', '--yes' => true, '--force' => true, '--json' => $json]))->toBe(1);
        if ($json) {
            $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            expect($output['error']['details'])->toBe($details)->and($output['error']['code'])->toBe('instance.worktree_dependents');
        } else {
            expect(instance_source_text(Artisan::output()))->toContain('default (#69, Project #12)',
                'other_dependents: 2', '/work/unowned tree', instance_request_id());
        }
    })->with([false, true]);

    it('removes an Instance in normal mode by default', function (): void {
        $mockClient = MockClient::global(instance_operation_responses(DestroyInstanceRequest::class, 'instance.destroy'));

        $this
            ->artisan('instance:destroy', ['--yes' => true, 'instance' => '5', '--json' => true])
            ->expectsOutput(instance_operation_json('instance.destroy'))
            ->assertExitCode(0);

        expect($mockClient->getRecordedResponses()[0]->getPendingRequest()->getRequest()->body()->all())->toBeEmpty();
    });

    it('transports explicit force and renders bounded progress', function (): void {
        $mockClient = MockClient::global(instance_operation_responses(DestroyInstanceRequest::class, 'instance.destroy', overrides: ['input' => ['force' => true]]));

        expect(Artisan::call('instance:destroy', ['--yes' => true, 'instance' => '5', '--force' => true]))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'Instance removal succeeded.', 'Operation: 901', 'Request ID: '.instance_request_id(),
        );

        expect($mockClient->getRecordedResponses()[0]->getPendingRequest()->getRequest()->body()->all())->toBe(['force' => true]);
    });

    it('preserves bounded failed removal progress in human and JSON results', function (): void {
        $overrides = ['status' => 'failed', 'current_step' => 'Stop, remove Processes',
            'failed_step' => 'Stop, remove Processes', 'error_code' => 'instance.removal_incomplete'];
        MockClient::global(instance_operation_responses(DestroyInstanceRequest::class, 'instance.destroy', ['removed' => false, 'failed_step' => 'Stop, remove Processes', 'error_code' => 'instance.removal_incomplete'], $overrides));

        expect(Artisan::call('instance:destroy', ['--yes' => true, 'instance' => '5']))->toBe(1);
        expect(instance_source_text(Artisan::output()))->toContain(
            'Instance removal failed.', 'Failed step: Stop, remove Processes',
            'Error code: instance.removal_incomplete', 'Operation: 901', 'Request ID: '.instance_request_id(),
        );

        MockClient::destroyGlobal();
        MockClient::global(instance_operation_responses(DestroyInstanceRequest::class, 'instance.destroy', ['removed' => false, 'failed_step' => 'Stop, remove Processes', 'error_code' => 'instance.removal_incomplete'], $overrides));
        $this->artisan('instance:destroy', ['--yes' => true, 'instance' => '5', '--json' => true])
            ->expectsOutput(instance_operation_json('instance.destroy', ['removed' => false, 'failed_step' => 'Stop, remove Processes', 'error_code' => 'instance.removal_incomplete'], $overrides))
            ->assertExitCode(1);
    });
});

it('rejects invalid Instance IDs before making an API request', function (string $command, string $instanceId): void {
    $mockClient = MockClient::global();

    $this
        ->artisan($command, ['instance' => $instanceId])
        ->expectsOutputToContain('Instance ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'show zero' => ['instance:show', '0'],
    'destroy negative' => ['instance:destroy', '-1'],
    'update zero' => ['instance:update', '0'],
]);

it('does not register replaced instance lifecycle names', function (): void {
    expect(Artisan::all())->not->toHaveKeys(['instance:new', 'instance:remove']);
});

it('rejects invalid parent IDs before creating an Instance', function (
    string $projectId,
    string $nodeId,
    string $message,
): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('instance:create', ['project' => $projectId, 'node' => $nodeId, 'name' => 'dev'])
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'invalid app' => ['0', '2', 'Project ID must be a positive integer.'],
    'invalid node' => ['3', '-1', 'Node ID must be a positive integer.'],
]);

describe('route web root', function (): void {
    it('lists the Routes of an Instance with their web roots', function (): void {
        $payload = instance_payload();
        $payload['routes'][] = [...instance_route_payload(), 'id' => 12, 'domain' => 'docs.dev.orbit.test', 'provenance' => 'explicit', 'web_root' => 'apps/docs/public'];
        MockClient::global([ShowInstanceRequest::class => instance_mock_response(payload: $payload), ListProcessesRequest::class => no_processes_response()]);

        expect(Artisan::call('instance:show', ['instance' => '5']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'ROUTE',
            'WEB ROOT',
            'dev.orbit.test',
            'Instance root',
            'docs.dev.orbit.test',
            'apps/docs/public',
        );
    });
});

function no_processes_response(): MockResponse
{
    return MockResponse::make(['data' => [], 'meta' => ['request_id' => instance_request_id()]]);
}
