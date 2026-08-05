<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal app source path probe command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects a missing operation token before probing the path', function (): void {
        [$exitCode, $output] = run_internal_app_source_path_probe_command([
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

    it('rejects relative paths after token validation', function (): void {
        [$exitCode, $output] = run_internal_app_source_path_probe_command([
            'path' => 'apps/docs',
            '--operation-token' => app_source_path_probe_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'App source path must be an absolute path.',
                ['field' => 'path'],
            ));
    });

    it('reports whether the absolute path is a directory', function (): void {
        $path = sys_get_temp_dir().'/orbit-app-source-path-'.bin2hex(random_bytes(8));
        mkdir($path);

        try {
            [$exitCode, $output] = run_internal_app_source_path_probe_command([
                'path' => $path,
                '--operation-token' => app_source_path_probe_signed_operation_token(),
                '--json' => true,
            ]);
        } finally {
            rmdir($path);
        }

        expect($exitCode)
            ->toBe(0)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::success([
                'path' => $path,
                'exists' => true,
            ]));
    });

    it('resolves symlinks and reports whether they stay inside an absolute boundary', function (): void {
        $boundary = sys_get_temp_dir().'/orbit-app-source-boundary-'.bin2hex(random_bytes(8));
        $release = "{$boundary}/releases/20260729_100713_219";
        $outside = sys_get_temp_dir().'/orbit-app-source-outside-'.bin2hex(random_bytes(8));
        mkdir($release, recursive: true);
        mkdir($outside);
        symlink($release, "{$boundary}/live");
        symlink($outside, "{$boundary}/escaped");
        $resolvedRelease = realpath($release);
        $resolvedOutside = realpath($outside);
        assert(is_string($resolvedRelease));
        assert(is_string($resolvedOutside));

        try {
            [$insideExitCode, $insideOutput] = run_internal_app_source_path_probe_command([
                'path' => "{$boundary}/live",
                '--boundary' => $boundary,
                '--operation-token' => app_source_path_probe_signed_operation_token(),
                '--json' => true,
            ]);
            [$outsideExitCode, $outsideOutput] = run_internal_app_source_path_probe_command([
                'path' => "{$boundary}/escaped",
                '--boundary' => $boundary,
                '--operation-token' => app_source_path_probe_signed_operation_token(),
                '--json' => true,
            ]);
        } finally {
            unlink("{$boundary}/live");
            unlink("{$boundary}/escaped");
            rmdir($release);
            rmdir("{$boundary}/releases");
            rmdir($boundary);
            rmdir($outside);
        }

        expect($insideExitCode)
            ->toBe(0)
            ->and(json_decode($insideOutput, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::success([
                'path' => "{$boundary}/live",
                'exists' => true,
                'resolved_path' => $resolvedRelease,
                'within_boundary' => true,
            ]))
            ->and($outsideExitCode)
            ->toBe(0)
            ->and(json_decode($outsideOutput, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::success([
                'path' => "{$boundary}/escaped",
                'exists' => true,
                'resolved_path' => $resolvedOutside,
                'within_boundary' => false,
            ]));
    });
});

function app_source_path_probe_signed_operation_token(
    string $id = 'app-source-path-probe',
    string $node = 'app-dev',
    string $command = 'internal:app-source-path:probe',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: 'gateway-secret',
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_app_source_path_probe_command(array $parameters): array
{
    $output = new BufferedOutput;
    $exitCode = Artisan::all()['internal:app-source-path:probe']->run(new ArrayInput($parameters), $output);

    return [$exitCode, trim($output->fetch())];
}
