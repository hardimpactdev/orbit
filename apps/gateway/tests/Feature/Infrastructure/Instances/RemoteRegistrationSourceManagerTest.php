<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Registration\RegistrationSourceFacts;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\AgentCommands\AgentTransport;
use App\Infrastructure\AgentCommands\AgentTransports;
use App\Infrastructure\AgentCommands\NativeAgentCommandRunner;
use App\Infrastructure\AgentCommands\SshAgentTransport;
use App\Infrastructure\Instances\RemoteRegistrationSourceManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Support\LocalAgentBinary;
use Tests\Support\SeparateFilesystem;

pest()->group('privileged');

it('resumes relocation from each durable cross-filesystem checkpoint', function (string $checkpoint): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $gitBefore = orb105_git_state($fixture['source']);
        $manifestBefore = orb105_complete_manifest($fixture['source']);
        $stage = $fixture['destination'].'.orbit-stage-'.$fixture['instance']->id;

        if ($checkpoint === 'incomplete-stage') {
            orb105_copy_tree($fixture['source'], $stage);
            file_put_contents($stage.'/README.md', "partial stage\n");
        } elseif ($checkpoint === 'complete-stage') {
            orb105_copy_tree($fixture['source'], $stage);
        } else {
            orb105_copy_tree($fixture['source'], $fixture['destination']);

            if ($checkpoint === 'destination-only') {
                new Filesystem()->deleteDirectory($fixture['source']);
            }
        }

        $fixture['manager']->relocate($fixture['instance'], $facts);

        expect(file_exists($fixture['source']))
            ->toBeFalse()
            ->and(file_exists($stage))
            ->toBeFalse()
            ->and(orb105_git_state($fixture['destination']))
            ->toBe($gitBefore)
            ->and(orb105_complete_manifest($fixture['destination']))
            ->toBe($manifestBefore)
            ->and(
                $fixture['instance']
                    ->refresh()
                    ->only([
                        'registration_relocation_state',
                        'registration_authoritative_path',
                    ]),
            )
            ->toBe([
                'registration_relocation_state' => 'relocated',
                'registration_authoritative_path' => $fixture['destination'],
            ]);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
})->with([
    'incomplete stage with the verified original' => 'incomplete-stage',
    'complete verified stage with the verified original' => 'complete-stage',
    'verified destination with a duplicate original' => 'destination-and-original',
    'verified destination after original removal before the database checkpoint' => 'destination-only',
]);

describe('in-place registration with shared refs', function (): void {
    it('adopts a managed worktree while unrelated refs change', function (): void {
        $fixture = orb918_in_place_fixture();

        try {
            $facts = $fixture['manager']->inspect($fixture['node'], $fixture['destination'], false)[0];
            $identity = stat($fixture['destination']);
            $gitLink = file_get_contents($fixture['destination'].'/.git');
            $transport = new Orb918ConcurrentRefsSshExecutor;
            $manager = orb105_registration_manager($transport);

            $manager->relocate($fixture['instance'], $facts);

            expect($fixture['instance']->refresh()->registration_relocation_state)->toBe('relocated')
                ->and(stat($fixture['destination'])['ino'])->toBe($identity['ino'])
                ->and(file_get_contents($fixture['destination'].'/.git'))->toBe($gitLink)
                ->and($fixture['manager']->inspect($fixture['node'], $fixture['destination'], false)[0]->sourceDigest)->toBe($facts->sourceDigest)
                ->and(trim(orb105_git($fixture['source'], ['rev-parse', 'refs/t3/test/918/1'])->stdout))->toBe($facts->commit)
                ->and($transport->operations)->not->toContain('prepare', 'cleanup');
        } finally {
            orb105_remove_relocation_fixture($fixture);
        }
    });

    it('resumes an interrupted adoption after unrelated refs change', function (string $checkpoint): void {
        $fixture = orb918_in_place_fixture();
        file_put_contents($fixture['destination'].'/.env', "APP_KEY=private\n");
        chmod($fixture['destination'].'/.env', 0o644);
        $transport = new Orb918InterruptAdoptionSshExecutor($checkpoint);
        $manager = orb105_registration_manager($transport);

        try {
            $facts = $manager->inspect($fixture['node'], $fixture['destination'], false)[0];
            expect(fn () => $manager->relocate($fixture['instance'], $facts))->toThrow(RuntimeConvergenceException::class);
            orb105_git($fixture['source'], ['update-ref', 'refs/t3/test/retry', $facts->commit]);

            if ($checkpoint === 'adopt') {
                $manager->validateRelocationRecovery($fixture['node'], $facts, $fixture['destination']);
            } else {
                $manager->validateRetained($fixture['node'], $facts, $fixture['destination']);
            }
            $manager->relocate($fixture['instance']->refresh(), $facts);

            expect($fixture['instance']->refresh()->registration_relocation_state)->toBe('relocated')
                ->and(fileperms($fixture['destination'].'/.env') & 0o007)->toBe(0)
                ->and($transport->operations)->not->toContain('prepare', 'cleanup');
        } finally {
            orb105_remove_relocation_fixture($fixture);
        }
    })->with(['adopt', 'adopt-finalize']);

    it('refuses changed source state before adoption', function (string $change): void {
        $fixture = orb918_in_place_fixture();

        try {
            $facts = $fixture['manager']->inspect($fixture['node'], $fixture['destination'], false)[0];
            if ($change === 'index') {
                orb105_git($fixture['destination'], ['update-index', '--assume-unchanged', 'README.md']);
            } elseif ($change === 'head') {
                orb105_git($fixture['destination'], ['commit', '--allow-empty', '-m', 'Source changed']);
            } else {
                file_put_contents($fixture['destination'].'/README.md', 'changed');
            }

            expect(fn () => $fixture['manager']->validateRelocationRecovery($fixture['node'], $facts, $fixture['destination']))->toThrow(ResourceOperationException::class);
            expect(fn () => $fixture['manager']->relocate($fixture['instance'], $facts))->toThrow(RuntimeConvergenceException::class)
                ->and(is_dir($fixture['destination']))->toBeTrue();
        } finally {
            orb105_remove_relocation_fixture($fixture);
        }
    })->with(['head', 'index', 'working-tree']);
});

it('resumes a relocated checkout after unrelated repository refs change', function (): void {
    $fixture = orb105_relocation_fixture(false);
    $manager = orb105_registration_manager(new Orb105InterruptAfterPrepareSshExecutor);

    try {
        $facts = $manager->inspect($fixture['node'], $fixture['source'], false)[0];
        expect(fn () => $manager->relocate($fixture['instance'], $facts))->toThrow(RuntimeConvergenceException::class);
        orb105_git($fixture['destination'], ['update-ref', 'refs/t3/test/relocation-retry', $facts->commit]);

        $manager->validateRelocationRecovery($fixture['node'], $facts, $fixture['destination']);
        $manager->relocate($fixture['instance']->refresh(), $facts);

        expect($fixture['instance']->refresh()->registration_relocation_state)->toBe('relocated')
            ->and(is_dir($fixture['source']))->toBeFalse()
            ->and(trim(orb105_git($fixture['destination'], ['rev-parse', 'HEAD'])->stdout))->toBe($facts->commit);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses a relocated checkout whose own HEAD changes during the move', function (): void {
    $fixture = orb105_relocation_fixture(false);

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $manager = orb105_registration_manager(new Orb918ChangeHeadAfterRenameSshExecutor);

        expect(fn () => $manager->relocate($fixture['instance'], $facts))->toThrow(RuntimeConvergenceException::class)
            ->and(is_dir($fixture['destination']))->toBeTrue()
            ->and($fixture['instance']->refresh()->registration_relocation_state)->toBe('relocating');
        expect(fn () => $manager->validateRelocationRecovery($fixture['node'], $facts, $fixture['destination']))->toThrow(ResourceOperationException::class);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('reports the configured origin, not the insteadOf rewrite Git applies', function (): void {
    $fixture = orb105_relocation_fixture(false);
    orb105_run(['git', '-C', $fixture['source'], 'config', 'url.git@example.test:.insteadOf', 'https://example.test/']);

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];

        expect($facts->repositoryUrl)->toBe('https://example.test/acme.git');
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('infers the public web root of a Laravel or Symfony checkout', function (?string $entryPoint, ?string $root): void {
    $fixture = orb105_relocation_fixture(false);
    mkdir($fixture['source'].'/public');
    file_put_contents($fixture['source'].'/composer.json', "{}\n");

    if (is_string($entryPoint)) {
        file_put_contents($fixture['source'].'/'.$entryPoint, "#!/usr/bin/env php\n");
    }

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];

        expect($facts->inferredRoot)->toBe($root);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
})->with([
    'Laravel' => ['artisan', 'public'],
    'Symfony' => ['bin/console', 'public'],
    'plain Composer' => [null, null],
]);

it('fails closed when an incomplete stage has no verified original', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $stage = $fixture['destination'].'.orbit-stage-'.$fixture['instance']->id;
        orb105_copy_tree($fixture['source'], $stage);
        file_put_contents($stage.'/README.md', "partial stage\n");
        new Filesystem()->deleteDirectory($fixture['source']);

        expect(fn () => $fixture['manager']->relocate($fixture['instance'], $facts))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            })
            ->and(is_dir($stage))
            ->toBeTrue()
            ->and(file_exists($fixture['destination']))
            ->toBeFalse()
            ->and(
                $fixture['instance']
                    ->refresh()
                    ->only([
                        'registration_relocation_state',
                        'registration_authoritative_path',
                    ]),
            )
            ->toBe([
                'registration_relocation_state' => 'relocating',
                'registration_authoritative_path' => $fixture['source'],
            ]);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('resumes after an emitted same-filesystem rename outruns its database checkpoint', function (): void {
    $fixture = orb105_relocation_fixture(crossFilesystem: false);

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $gitBefore = orb105_git_state($fixture['source']);
        $manifestBefore = orb105_complete_manifest($fixture['source']);
        $interruptingManager = orb105_registration_manager(new Orb105InterruptAfterPrepareSshExecutor);

        expect(fn () => $interruptingManager->relocate($fixture['instance'], $facts))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            })
            ->and(file_exists($fixture['source']))
            ->toBeFalse()
            ->and(orb105_git_state($fixture['destination']))
            ->toBe($gitBefore)
            ->and(orb105_complete_manifest($fixture['destination']))
            ->toBe($manifestBefore)
            ->and($fixture['instance']->refresh()->registration_relocation_state)
            ->toBe('relocating')
            ->and($fixture['instance']->registration_authoritative_path)
            ->toBe($fixture['source']);

        $fixture['manager']->validateRelocationRecovery(
            $fixture['node'],
            $facts,
            $fixture['destination'],
        );
        $fixture['manager']->relocate($fixture['instance']->refresh(), $facts);

        expect(file_exists($fixture['source']))
            ->toBeFalse()
            ->and(orb105_git_state($fixture['destination']))
            ->toBe($gitBefore)
            ->and(orb105_complete_manifest($fixture['destination']))
            ->toBe($manifestBefore)
            ->and($fixture['instance']->refresh()->registration_relocation_state)
            ->toBe('relocated')
            ->and($fixture['instance']->registration_authoritative_path)
            ->toBe($fixture['destination']);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('resumes a partially renamed included worktree set from retained evidence', function (): void {
    $fixture = orb105_relocation_fixture(crossFilesystem: false);

    try {
        $linkedSource = $fixture['source_root'].'/source/feature';
        $linkedDestination = $fixture['destination_root'].'/managed/acme/feature';
        orb105_run(['git', '-C', $fixture['source'], 'worktree', 'add', '-b', 'feature', $linkedSource]);
        $files = new Filesystem;
        $files->ensureDirectoryExists($linkedSource.'/many');
        // The rename acknowledgement, not tree size, holds the partial-move checkpoint.
        for ($index = 0; $index < 3; $index++) {
            file_put_contents($linkedSource.'/many/'.$index, "retained\n");
        }

        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], true);
        $requestId = $fixture['instance']->registration_request_id;
        $linked = Instance::query()->create([
            'project_id' => $fixture['instance']->project_id,
            'node_id' => $fixture['node']->id,
            'name' => 'feature',
            'source_layout' => 'worktree',
            'checkout_path' => $linkedDestination,
            'branch' => 'feature',
            'starting_commit' => collect($facts)->firstWhere('path', $linkedSource)?->commit,
            'registration_original_path' => $linkedSource,
            'registration_request_id' => $requestId,
            'registration_repository_url' => 'https://example.test/acme.git',
            'registration_repository_identity' => 'example.test/acme',
            'registration_relocation_state' => 'reserved',
            'registration_authoritative_path' => $linkedSource,
            'status' => 'reserved',
        ]);
        $members = array_map(
            static fn (RegistrationSourceFacts $fact): array => [
                'instance' => $fact->path === $fixture['source'] ? $fixture['instance'] : $linked,
                'facts' => $fact,
            ],
            $facts,
        );
        $manifests = [];
        foreach ($facts as $fact) {
            $manifests[$fact->path] = orb105_preserved_manifest($fact->path);
        }
        $interrupting = orb105_registration_manager(new Orb105InterruptPartialPrepareSshExecutor(
            movedSource: $linkedSource,
            unmovedSource: $fixture['source'],
        ));

        expect(fn () => $interrupting->relocateSet($members))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            })
            ->and(file_exists($linkedSource))
            ->toBeFalse()
            ->and(is_dir($linkedDestination))
            ->toBeTrue()
            ->and(is_dir($fixture['source']))
            ->toBeTrue()
            ->and($fixture['instance']->refresh()->registration_relocation_state)
            ->toBe('relocating')
            ->and($linked->refresh()->registration_relocation_state)
            ->toBe('relocating');

        foreach ($facts as $fact) {
            $fixture['manager']->validateRelocationRecovery(
                $fixture['node'],
                $fact,
                $fact->path === $linkedSource ? $linkedDestination : $fixture['source'],
            );
        }

        $fixture['manager']->relocateSet($members);

        expect(file_exists($fixture['source']))
            ->toBeFalse()
            ->and(file_exists($linkedSource))
            ->toBeFalse()
            ->and(orb105_preserved_manifest($fixture['destination']))
            ->toBe($manifests[$fixture['source']])
            ->and(orb105_preserved_manifest($linkedDestination))
            ->toBe($manifests[$linkedSource])
            ->and($fixture['instance']->refresh()->registration_relocation_state)
            ->toBe('relocated')
            ->and($linked->refresh()->registration_relocation_state)
            ->toBe('relocated');
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses unsafe checkout and Git administration modes before relocation', function (string $target): void {
    $fixture = orb105_relocation_fixture();

    try {
        chmod($target === 'checkout' ? $fixture['source'] : $fixture['source'].'/.git', 0o777);

        expect(fn () => $fixture['manager']->inspect($fixture['node'], $fixture['source'], false))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.source_invalid');
            })
            ->and(is_dir($fixture['source']))
            ->toBeTrue()
            ->and(file_exists($fixture['destination']))
            ->toBeFalse()
            ->and($fixture['instance']->refresh()->registration_relocation_state)
            ->toBe('reserved');
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
})->with(['checkout', 'Git administration']);

it('refuses an invalid second non-Composer worktree before any included source moves', function (): void {
    $fixture = orb105_relocation_fixture(crossFilesystem: false);

    try {
        $linkedSource = $fixture['source_root'].'/source/feature';
        orb105_run(['git', '-C', $fixture['source'], 'worktree', 'add', '-b', 'feature', $linkedSource]);
        chmod($linkedSource.'/.git', 0o666);

        expect(fn () => $fixture['manager']->inspect($fixture['node'], $fixture['source'], true))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.source_invalid');
            })
            ->and(is_dir($fixture['source']))
            ->toBeTrue()
            ->and(is_dir($linkedSource))
            ->toBeTrue()
            ->and(file_exists($fixture['destination']))
            ->toBeFalse()
            ->and(file_exists($fixture['destination_root'].'/managed/acme/feature'))
            ->toBeFalse();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses a non-Composer checkout outside the resolved managed group', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $manager = orb105_registration_manager(new Orb105LocalSshExecutor, managedGroup: 'root');

        expect(fn () => $manager->inspect($fixture['node'], $fixture['source'], false))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.source_invalid');
            })
            ->and(is_dir($fixture['source']))
            ->toBeTrue()
            ->and(file_exists($fixture['destination']))
            ->toBeFalse();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('persists relocation checkpoints without publishing dirty migration fields', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        Instance::query()
            ->whereKey($fixture['instance']->id)
            ->update([
                'name' => 'main',
                'checkout_path' => $fixture['source'],
            ]);
        $fixture['instance']->refresh();
        $fixture['instance']->name = 'default';
        $fixture['instance']->checkout_path = $fixture['destination'];

        $fixture['manager']->relocate($fixture['instance'], $facts);

        expect($fixture['instance']
            ->refresh()
            ->only([
                'name',
                'checkout_path',
                'registration_relocation_state',
                'registration_authoritative_path',
            ]))->toBe([
                'name' => 'main',
                'checkout_path' => $fixture['source'],
                'registration_relocation_state' => 'relocated',
                'registration_authoritative_path' => $fixture['destination'],
            ]);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('does not reapply source-digest relocation after the managed destination becomes authoritative', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $fixture['manager']->relocate($fixture['instance'], $facts);
        file_put_contents($fixture['destination'].'/.env', "APP_URL=https://managed.test\n");
        file_put_contents($fixture['destination'].'/README.md', "normal development edit\n", FILE_APPEND);
        $afterEdits = orb105_complete_manifest($fixture['destination']);

        $fixture['manager']->validateRetained($fixture['node'], $facts, $fixture['destination']);
        $fixture['manager']->relocate($fixture['instance']->refresh(), $facts);

        expect(orb105_complete_manifest($fixture['destination']))
            ->toBe($afterEdits)
            ->and(file_exists($fixture['source']))
            ->toBeFalse()
            ->and($fixture['instance']->refresh()->registration_relocation_state)
            ->toBe('relocated')
            ->and($fixture['instance']->registration_authoritative_path)
            ->toBe($fixture['destination']);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses a replacement repository at a retained authoritative destination', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $fixture['manager']->relocate($fixture['instance'], $facts);
        orb105_run([
            'git',
            '-C',
            $fixture['destination'],
            'remote',
            'set-url',
            'origin',
            'https://example.test/replacement.git',
        ]);

        expect(fn () => $fixture['manager']->validateRetained(
            $fixture['node'],
            $facts,
            $fixture['destination'],
        ))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.registration_conflict');
        });
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('resumes an actual interruption during original cleanup from the verified destination', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $files = new Filesystem;
        $files->ensureDirectoryExists($fixture['source'].'/cleanup');
        file_put_contents($fixture['source'].'/cleanup/00000-trigger', "trigger\n");
        // The unlink acknowledgement holds cleanup before the remaining entries are removed.
        for ($index = 1; $index <= 3; $index++) {
            file_put_contents(
                $fixture['source'].'/cleanup/'.str_pad((string) $index, 5, '0', STR_PAD_LEFT),
                "retained\n",
            );
        }
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $manifestBefore = orb105_complete_manifest($fixture['source']);
        $interruptingManager = orb105_registration_manager(
            new Orb105InterruptingCleanupSshExecutor(
                $fixture['source'],
                $fixture['source'].'/cleanup/00000-trigger',
            ),
        );

        expect(fn () => $interruptingManager->relocate($fixture['instance'], $facts))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            });

        $partialCount = count(glob($fixture['source'].'/cleanup/*') ?: []);
        $checkpoint = $fixture['instance']->refresh();
        expect(is_dir($fixture['source']))
            ->toBeTrue()
            ->and($partialCount)
            ->toBeLessThan(4)
            ->toBeGreaterThan(0)
            ->and(orb105_complete_manifest($fixture['source']))
            ->not
            ->toBe($manifestBefore)
            ->and(orb105_complete_manifest($fixture['destination']))
            ->toBe($manifestBefore)
            ->and($checkpoint->registration_relocation_state)
            ->toBe('original_cleanup')
            ->and($checkpoint->registration_authoritative_path)
            ->toBe($fixture['destination'])
            ->and($checkpoint->registration_source_device)
            ->toBeInt()
            ->and($checkpoint->registration_source_inode)
            ->toBeInt();

        $instanceId = $checkpoint->id;
        $fixture['manager']->relocate($checkpoint, $facts);

        expect(file_exists($fixture['source']))
            ->toBeFalse()
            ->and(orb105_complete_manifest($fixture['destination']))
            ->toBe($manifestBefore)
            ->and($fixture['instance']->refresh()->id)
            ->toBe($instanceId)
            ->and($fixture['instance']->registration_relocation_state)
            ->toBe('relocated')
            ->and($fixture['instance']->registration_authoritative_path)
            ->toBe($fixture['destination']);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('refuses cleanup when the original path was replaced after destination verification', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];
        $sourceIdentity = stat($fixture['source']);
        orb105_copy_tree($fixture['source'], $fixture['destination']);
        $fixture['instance']->update([
            'registration_relocation_state' => 'original_cleanup',
            'registration_authoritative_path' => $fixture['destination'],
            'registration_source_device' => $sourceIdentity['dev'],
            'registration_source_inode' => $sourceIdentity['ino'],
        ]);
        new Filesystem()->deleteDirectory($fixture['source']);
        new Filesystem()->ensureDirectoryExists($fixture['source']);
        file_put_contents($fixture['source'].'/unrelated.txt', "unrelated\n");

        expect(fn () => $fixture['manager']->relocate($fixture['instance']->refresh(), $facts))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.registration_incomplete');
            })
            ->and(file_get_contents($fixture['source'].'/unrelated.txt'))
            ->toBe("unrelated\n")
            ->and(is_dir($fixture['destination']))
            ->toBeTrue();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

it('restores Laravel URL files and the stable directory timestamps they touch', function (string $relative): void {
    $fixture = orb105_relocation_fixture();

    try {
        $files = new Filesystem;
        $application = $fixture['source'].$relative;
        $files->ensureDirectoryExists($application.'/bootstrap/cache');
        file_put_contents($application.'/.env', "APP_URL=https://before.test\n");
        file_put_contents($application.'/bootstrap/cache/config.php', "<?php return ['url' => 'before'];\n");
        $fixture['instance']->update(['checkout_path' => $fixture['source'], 'root' => ltrim($relative.'/public', '/')]);
        $before = orb105_complete_manifest($fixture['source']);

        $fixture['manager']->prepareLaravelRollback($fixture['instance']);
        file_put_contents($application.'/.env.next', "APP_URL=https://after.test\n");
        rename($application.'/.env.next', $application.'/.env');
        file_put_contents($application.'/bootstrap/cache/config.php.next', "<?php return ['url' => 'after'];\n");
        rename(
            $application.'/bootstrap/cache/config.php.next',
            $application.'/bootstrap/cache/config.php',
        );
        $fixture['manager']->prepareLaravelRollback($fixture['instance']);
        $fixture['manager']->restoreLaravelConfiguration($fixture['instance']);

        expect(orb105_complete_manifest($fixture['source']))->toBe($before);
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
})->with(['root public' => '', 'nested Laravel' => '/server/web']);

it('refuses an incomplete Laravel rollback receipt without replacing it', function (): void {
    $fixture = orb105_relocation_fixture();

    try {
        $receipt = dirname($fixture['source']).'/.orbit-registration-url-'.$fixture['instance']->id;
        new Filesystem()->ensureDirectoryExists($receipt);
        file_put_contents($receipt.'/0', "partial\n");
        $fixture['instance']->update(['checkout_path' => $fixture['source']]);

        expect(fn () => $fixture['manager']->prepareLaravelRollback($fixture['instance']))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.laravel_rollback_failed');
            })
            ->and(file_get_contents($receipt.'/0'))
            ->toBe("partial\n")
            ->and(file_exists($receipt.'/manifest'))
            ->toBeFalse();
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
});

/**
 * @return array{manager: RemoteRegistrationSourceManager, node: Node, instance: Instance, source_root: string, destination_root: string, source: string, destination: string}
 */
function orb105_relocation_fixture(bool $crossFilesystem = true): array
{
    $token = (string) Str::uuid();
    $sourceRoot = $crossFilesystem
        ? SeparateFilesystem::path('orbit-orb105-'.$token)
        : sys_get_temp_dir().'/orbit-orb105-'.$token;
    $destinationRoot = $crossFilesystem
        ? sys_get_temp_dir().'/orbit-orb105-'.$token
        : $sourceRoot;
    $source = $sourceRoot.($crossFilesystem ? '/acme' : '/source/acme');
    $destination = $destinationRoot.'/managed/acme/default';
    $files = new Filesystem;
    $files->ensureDirectoryExists($source);
    $files->ensureDirectoryExists(dirname($destination));

    if ($crossFilesystem) {
        expect(stat($sourceRoot)['dev'])->not->toBe(stat($destinationRoot)['dev']);
    } else {
        expect(stat($sourceRoot)['dev'])->toBe(stat($destinationRoot)['dev']);
    }

    orb105_run(['git', 'init', '--initial-branch=main', $source]);
    orb105_run(['git', '-C', $source, 'config', 'user.email', 'orb105@example.test']);
    orb105_run(['git', '-C', $source, 'config', 'user.name', 'ORB-105']);
    orb105_run(['git', '-C', $source, 'config', 'orbit.fixture', 'preserved']);
    file_put_contents($source.'/README.md', "initial\n");
    $files->ensureDirectoryExists($source.'/bin');
    file_put_contents($source.'/bin/run', "#!/bin/sh\nexit 0\n");
    chmod($source.'/bin/run', 0o750);
    symlink('README.md', $source.'/readme-link');
    orb105_run(['git', '-C', $source, 'add', '.']);
    orb105_run(['git', '-C', $source, 'commit', '-m', 'Initial']);
    orb105_run(['git', '-C', $source, 'remote', 'add', 'origin', 'https://example.test/acme.git']);
    file_put_contents($source.'/README.md', "dirty\n", FILE_APPEND);
    file_put_contents($source.'/staged.txt', "staged\n");
    orb105_run(['git', '-C', $source, 'add', 'staged.txt']);
    file_put_contents($source.'/untracked.txt', "untracked\n");

    $node = Node::query()->create([
        'name' => 'orb105-local',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => 'test',
        'public_ssh_host' => '192.0.2.105',
        'wireguard_ip' => '127.0.0.1',
        'user' => orb105_effective_user(),
        'settings' => ['apps' => ['path' => $destinationRoot.'/managed']],
    ]);
    orbit_test_set_app_placement_role($node, production: false);
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => null,
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'source_layout' => 'checkout',
        'checkout_path' => $destination,
        'branch' => 'main',
        'registration_original_path' => $source,
        'registration_request_id' => (string) Str::uuid(),
        'registration_primary' => true,
        'registration_repository_url' => 'https://example.test/acme.git',
        'registration_repository_identity' => 'example.test/acme',
        'registration_relocation_state' => 'reserved',
        'registration_authoritative_path' => $source,
        'status' => 'reserved',
    ]);
    $manager = orb105_registration_manager(new Orb105LocalSshExecutor);

    return
        compact(
            'manager',
            'node',
            'instance',
            'sourceRoot',
            'destinationRoot',
            'source',
            'destination',
        )
        + [
            'source_root' => $sourceRoot,
            'destination_root' => $destinationRoot,
        ];
}

/** Returns the account that creates the fixture files, not the PHP script's owner. */
function orb105_effective_user(): string
{
    $user = posix_getpwuid(posix_geteuid());

    if (! is_array($user) || ! is_string($user['name'] ?? null)) {
        throw new RuntimeException('The test process user is unavailable.');
    }

    return $user['name'];
}

/**
 * Returns the group that owns the fixture files: the test process's primary group.
 *
 * Ubuntu gives each managed account a private group with the account's name, but macOS puts users in staff.
 */
function orb918_in_place_fixture(): array
{
    $fixture = orb105_relocation_fixture(false);
    orb105_git($fixture['source'], ['worktree', 'add', '-b', 'task-918', $fixture['destination']]);
    $fixture['instance']->update([
        'source_layout' => 'worktree',
        'branch' => 'task-918',
        'registration_original_path' => $fixture['destination'],
        'registration_authoritative_path' => $fixture['destination'],
    ]);

    return $fixture;
}

function orb105_primary_group(): string
{
    $group = posix_getgrgid(posix_getegid());

    if (! is_array($group) || ! is_string($group['name'] ?? null)) {
        throw new RuntimeException('The test process group is unavailable.');
    }

    return $group['name'];
}

function orb105_registration_manager(
    SshExecutor $executor,
    ?string $managedGroup = null,
): RemoteRegistrationSourceManager {
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/dev/null';
        }

        public function publicKey(): string
        {
            return 'unused';
        }
    };
    $knownHosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/dev/null';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $accounts = new class($managedGroup) implements ManagedUserAccountResolver
    {
        public function __construct(
            private readonly ?string $managedGroup,
        ) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount($node->user, $this->managedGroup ?? orb105_primary_group(), '/tmp');
        }
    };
    $transports = new class($executor, $keys, $knownHosts) extends AgentTransports
    {
        public function __construct(private readonly SshExecutor $ssh, private readonly SshKeyProvider $keys, private readonly KnownHostsStore $knownHosts) {}

        public function for(Node $node): AgentTransport
        {
            return new SshAgentTransport($node, $this->ssh, $this->keys, $this->knownHosts);
        }
    };
    $agents = new NativeAgentCommandRunner(app(AgentContract::class), LocalAgentBinary::delivery(orb105_agent_directory().'/delivery'), $transports);

    return new RemoteRegistrationSourceManager($agents, $accounts);
}

/** @param array{source_root: string, destination_root: string} $fixture */
function orb105_remove_relocation_fixture(array $fixture): void
{
    $files = new Filesystem;
    $files->deleteDirectory($fixture['source_root']);
    $files->deleteDirectory($fixture['destination_root']);
}

function orb105_copy_tree(string $source, string $destination): void
{
    $result = orb105_run(['cp', '-a', '--', $source, $destination]);
    expect($result->succeeded())->toBeTrue($result->stderr);
}

/** @return array<string, string> */
function orb105_git_state(string $path): array
{
    return [
        'head' => trim(orb105_git($path, ['rev-parse', 'HEAD'])->stdout),
        'branch' => trim(orb105_git($path, ['symbolic-ref', '-q', 'HEAD'])->stdout),
        'index' => hash_file('sha256', $path.'/.git/index'),
        'status' => orb105_git($path, ['status', '--porcelain=v2', '--untracked-files=all'])->stdout,
        'config' => orb105_git($path, ['config', '--local', '--null', '--list'])->stdout,
        'refs' => orb105_git($path, ['show-ref', '--head'])->stdout,
    ];
}

/** @param non-empty-list<string> $arguments */
function orb105_git(string $path, array $arguments): CommandResult
{
    return orb105_run(['env', 'GIT_OPTIONAL_LOCKS=0', 'git', '-C', $path, ...$arguments]);
}

/** @return list<array<string, int|string|null>> */
function orb105_complete_manifest(string $path): array
{
    $script = <<<'PYTHON'
        import hashlib, json, os, pathlib, stat, sys
        root = pathlib.Path(sys.argv[1])
        rows = []
        for entry in [root, *sorted(root.rglob('*'))]:
            info = entry.lstat()
            relative = '.' if entry == root else entry.relative_to(root).as_posix()
            kind = 'link' if entry.is_symlink() else 'file' if entry.is_file() else 'directory'
            rows.append({
                'path': relative,
                'type': kind,
                'mode': stat.S_IMODE(info.st_mode),
                'uid': info.st_uid,
                'gid': info.st_gid,
                'size': info.st_size if kind != 'directory' else None,
                'mtime_ns': info.st_mtime_ns,
                'content': hashlib.sha256(entry.read_bytes()).hexdigest() if kind == 'file' else None,
                'target': os.readlink(entry) if kind == 'link' else None,
            })
        print(json.dumps(rows, sort_keys=True, separators=(',', ':')))
        PYTHON;
    $result = orb105_run(['python3', '-c', $script, $path]);
    expect($result->succeeded())->toBeTrue($result->stderr);

    return json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, int|string|null>> */
function orb105_preserved_manifest(string $path): array
{
    return array_values(array_filter(
        orb105_complete_manifest($path),
        static fn (array $entry): bool => $entry['path'] !== '.git'
        && ! str_starts_with((string) $entry['path'], '.git/'),
    ));
}

/** @param non-empty-list<string> $arguments */
function orb105_run(array $arguments): CommandResult
{
    return new NativeProcessRunner(maxOutputBytes: 16_777_216)->run(new ProcessInvocation($arguments));
}

/** The scratch directory of the built agent's delivery and locks in these tests. */
function orb105_agent_directory(): string
{
    $directory = sys_get_temp_dir().'/orbit-registration-agent-'.getmypid();
    if (! is_dir($directory)) {
        mkdir($directory, 0o700, true);
    }

    return $directory;
}

/**
 * An agent command that the runner sent: the local arguments of the built binary, the envelope, and its input.
 *
 * @return array{arguments: list<string>, envelope: string, input: array<string, mixed>}
 */
function orb105_agent_call(RemoteCommand $command): array
{
    $arguments = LocalAgentBinary::localArguments($command->arguments) ?? throw new LogicException('Registration runs only agent commands.');
    $envelope = (string) stream_get_contents($command->protectedInput?->stream());
    $command->protectedInput?->close();

    return ['arguments' => $arguments, 'envelope' => $envelope, 'input' => json_decode($envelope, true, flags: JSON_THROW_ON_ERROR)['input']];
}

/** @param array{arguments: list<string>, envelope: string} $call */
function orb105_run_agent(array $call): CommandResult
{
    $process = new SymfonyProcess($call['arguments'], null, LocalAgentBinary::environment(orb105_agent_directory()), $call['envelope'], 120);
    $process->run();

    return new CommandResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 1, false);
}

/** A run that a stopped process ended: no result reaches the Gateway. */
function orb105_lost(): CommandResult
{
    return new CommandResult(137, '', 'Interrupted', 1, false);
}

/** @param array<string, mixed> $input */
function orb105_operation(array $input): string
{
    return (string) ($input['operation'] ?? '');
}

final readonly class Orb105LocalSshExecutor implements SshExecutor
{
    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        return orb105_run_agent(orb105_agent_call($command));
    }
}

/** Other agents move refs in the source while the adoption or the move runs; the digest leaves refs out. */
final class Orb918ConcurrentRefsSshExecutor implements SshExecutor
{
    /** @var list<string> */
    public array $operations = [];

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $call = orb105_agent_call($command);
        $operation = orb105_operation($call['input']);
        $this->operations[] = $operation;
        if (in_array($operation, ['adopt', 'prepare'], true)) {
            $member = $call['input']['members'][0];
            orb105_git($member['source'], ['update-ref', 'refs/t3/test/918/1', $member['commit']]);
            orb105_git($member['source'], ['update-ref', 'refs/heads/another-agent', $member['commit']]);
        }

        return orb105_run_agent($call);
    }
}

/** The process stops after the step finished, before its result reaches the Gateway. */
final class Orb918InterruptAdoptionSshExecutor implements SshExecutor
{
    private bool $interrupted = false;

    /** @var list<string> */
    public array $operations = [];

    public function __construct(private readonly string $checkpoint = 'adopt') {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $call = orb105_agent_call($command);
        $operation = orb105_operation($call['input']);
        $this->operations[] = $operation;
        $result = orb105_run_agent($call);
        if (! $this->interrupted && ($operation === $this->checkpoint || $operation === 'prepare') && $result->succeeded()) {
            $this->interrupted = true;

            return orb105_lost();
        }

        return $result;
    }
}

/** Another program commits in the checkout just after it moved, so the move must not count as complete. */
final readonly class Orb918ChangeHeadAfterRenameSshExecutor implements SshExecutor
{
    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $call = orb105_agent_call($command);
        if (orb105_operation($call['input']) === 'prepare') {
            $member = $call['input']['members'][0];
            rename($member['source'], $member['destination']);
            orb105_run(['git', '-C', $member['destination'], '-c', 'user.name=Orbit', '-c', 'user.email=orbit@example.test', 'commit', '--allow-empty', '-m', 'Concurrent source change']);
        }

        return orb105_run_agent($call);
    }
}

final class Orb105InterruptAfterPrepareSshExecutor implements SshExecutor
{
    private bool $interrupted = false;

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $call = orb105_agent_call($command);
        $result = orb105_run_agent($call);

        if (! $this->interrupted && orb105_operation($call['input']) === 'prepare' && $result->succeeded()) {
            $this->interrupted = true;

            return orb105_lost();
        }

        return $result;
    }
}

/** The process stops right after it moved one member of the set, before any other. */
final class Orb105InterruptPartialPrepareSshExecutor implements SshExecutor
{
    private bool $interrupted = false;

    public function __construct(
        private readonly string $movedSource,
        private readonly string $unmovedSource,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $call = orb105_agent_call($command);
        if ($this->interrupted || orb105_operation($call['input']) !== 'prepare') {
            return orb105_run_agent($call);
        }

        $this->interrupted = true;
        foreach ($call['input']['members'] as $member) {
            if ($member['source'] === $this->movedSource) {
                rename($member['source'], $member['destination']);
            }
        }
        expect(file_exists($this->movedSource))->toBeFalse()
            ->and(is_dir($this->unmovedSource))->toBeTrue();

        return orb105_lost();
    }
}

/** The process stops after cleanup removed one file of the original, before the rest. */
final class Orb105InterruptingCleanupSshExecutor implements SshExecutor
{
    private bool $interrupted = false;

    public function __construct(
        private readonly string $source,
        private readonly string $trigger,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $call = orb105_agent_call($command);
        if ($this->interrupted || orb105_operation($call['input']) !== 'cleanup') {
            return orb105_run_agent($call);
        }

        $this->interrupted = true;
        unlink($this->trigger);
        expect(file_exists($this->trigger))->toBeFalse()
            ->and(is_dir($this->source))->toBeTrue();

        return orb105_lost();
    }
}

it('closes a relocated Instance environment to other local users after the last verification', function (string $relative): void {
    $fixture = orb105_relocation_fixture();

    try {
        $fixture['instance']->update(['root' => ltrim($relative.'/public', '/')]);
        new Filesystem()->ensureDirectoryExists($fixture['source'].$relative);
        file_put_contents($fixture['source'].$relative.'/.env', "APP_KEY=secret\n");
        chmod($fixture['source'].$relative.'/.env', 0o664);
        $facts = $fixture['manager']->inspect($fixture['node'], $fixture['source'], false)[0];

        $fixture['manager']->relocate($fixture['instance'], $facts);

        expect(fileperms($fixture['destination'].$relative.'/.env') & 0o777)->toBe(0o660)
            ->and($fixture['instance']->refresh()->registration_relocation_state)->toBe('relocated');
    } finally {
        orb105_remove_relocation_fixture($fixture);
    }
})->with(['root public' => '', 'nested Laravel' => '/server/web']);
