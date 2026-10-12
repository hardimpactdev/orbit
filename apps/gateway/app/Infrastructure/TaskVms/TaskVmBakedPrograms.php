<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * The enrollment programs that a task VM's base image already ran. The image build runs each program and
 * records its fingerprint, the SHA-256 of its exact arguments and input, in {@see self::ManifestPath}.
 * `EnrollTaskVm` reads that manifest before enrollment and loads it here. Enrollment then skips a program
 * only while it renders the same fingerprint for the same name: the image ran that exact program, and each
 * program is idempotent. Any other program runs. A missing, unreadable or older manifest makes enrollment
 * run every program. Each skip logs an info line and each program that runs anyway logs a warning, so the
 * Gateway log shows which programs an image held.
 *
 * The binding is scoped, so each job starts with nothing loaded, and every other Node runs every program.
 */
final class TaskVmBakedPrograms
{
    /** Written by the image build, read before enrollment. Root owns it; it holds no secret. */
    public const string ManifestPath = '/var/lib/orbit-task-vm/image.json';

    public const int ManifestSchema = 1;

    public const string Bootstrap = 'bootstrap';

    public const string CaddyPackage = 'caddy-package';

    public const string AppDevPrerequisites = 'app-dev-prerequisites';

    public const string VpManager = 'vp-manager';

    public const string ComposerManager = 'composer-manager';

    /**
     * Prefix of the `app-dev` PHP programs: `app-dev-php-source` and one per PHP version, such as `app-dev-php-8-5`.
     * Enrollment does not run them; an Instance's route does, and finds the packages in place.
     */
    public const string AppDevPhp = 'app-dev-php';

    /** The stable Rust toolchain for `orbit`. Enrollment does not run it; it only serves Projects with Rust code. */
    public const string RustToolchain = 'rust-toolchain';

    private ?string $node = null;

    /** @var array<string, string> program name => fingerprint */
    private array $programs = [];

    /**
     * Loads the manifest that the VM's image holds, read from the guest before any code but cloud-init ran.
     * `$manifest` is null when the image has none, as an image built before manifests has none.
     */
    public function load(TaskVm $vm, ?string $manifest): void
    {
        $this->node = null;
        $this->programs = [];

        $programs = $manifest === null ? null : self::parse($manifest);
        if ($programs === null) {
            Log::warning('Task VM image has no usable manifest; enrollment runs every program.', [
                'task_vm' => $vm->name,
                'reason' => $manifest === null ? 'missing' : 'unreadable',
            ]);

            return;
        }

        $this->node = $vm->name;
        $this->programs = $programs;
    }

    /**
     * Whether `$node`'s image already ran `$command` as program `$name`, so enrollment may skip it. True
     * only for the Node of the loaded VM and an identical fingerprint.
     */
    public function holds(Node $node, string $name, RemoteCommand $command): bool
    {
        if ($this->node === null || $node->name !== $this->node) {
            return false;
        }

        $baked = $this->programs[$name] ?? null;
        $fingerprint = self::fingerprint($command);
        if ($baked !== null && $fingerprint !== null && hash_equals($baked, $fingerprint)) {
            Log::info('Task VM program skipped: its image ran it.', ['task_vm' => $this->node, 'program' => $name]);

            return true;
        }

        Log::warning('Task VM image did not run this program; enrollment runs it.', [
            'task_vm' => $this->node,
            'program' => $name,
            'reason' => $baked === null ? 'not in the image manifest' : 'the program changed since the image build',
        ]);

        return false;
    }

    public function stop(): void
    {
        $this->node = null;
        $this->programs = [];
    }

    /** The SHA-256 of the exact arguments and input, or null for a command with protected input, which never counts as baked. */
    public static function fingerprint(RemoteCommand $command): ?string
    {
        if ($command->protectedInput !== null) {
            return null;
        }

        return hash('sha256', json_encode(
            ['arguments' => $command->arguments, 'input' => $command->input],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * The manifest the image build writes for these programs.
     *
     * @param  array<string, RemoteCommand>  $programs
     */
    public static function manifest(array $programs): string
    {
        $fingerprints = [];
        foreach ($programs as $name => $command) {
            $fingerprint = self::fingerprint($command);
            if ($fingerprint !== null) {
                $fingerprints[$name] = $fingerprint;
            }
        }

        return json_encode(['schema' => self::ManifestSchema, 'programs' => $fingerprints], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    /** @return array<string, string>|null the programs of a schema 1 manifest, else null */
    private static function parse(string $manifest): ?array
    {
        try {
            $decoded = json_decode($manifest, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($decoded) || ($decoded['schema'] ?? null) !== self::ManifestSchema || ! is_array($decoded['programs'] ?? null)) {
            return null;
        }

        $programs = [];
        foreach ($decoded['programs'] as $name => $fingerprint) {
            if (! is_string($name) || preg_match('/\A[a-z0-9-]{1,64}\z/', $name) !== 1
                || ! is_string($fingerprint) || preg_match('/\A[0-9a-f]{64}\z/', $fingerprint) !== 1) {
                return null;
            }
            $programs[$name] = $fingerprint;
        }

        return $programs;
    }
}
