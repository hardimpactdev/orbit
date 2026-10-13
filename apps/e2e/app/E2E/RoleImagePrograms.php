<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\RoleImage;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

/**
 * @phpstan-type Program array{name:string,user:string,arguments:list<string>,input:?string}
 *
 * The programs one role image runs, in order. Harness programs prepare the builder and run as root. Gateway
 * programs are what Node enrollment runs for the role's Orbit roles, rendered by the checkout's own Gateway
 * with `orbit:node-programs`; they run as `orbit` and start with `sudo`, as enrollment runs them over SSH.
 */
final readonly class RoleImagePrograms
{
    private const string IMAGE_RESOURCES = '/apps/e2e/resources/image/';

    /** A public key with no private half anywhere; it only fills the Gateway key slot while rendering. */
    public const string PLACEHOLDER_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPb2YoLP14UR+XxEQ1nPvQAlpRc7B9/4xS201KFQ3tyu orbit-e2e-role-image';

    public function __construct(private string $repositoryRoot) {}

    /** @return list<Program> */
    public function for(RoleImage $image): array
    {
        $programs = [];
        foreach ($image->harnessPrograms() as [$name, $script, $arguments]) {
            $programs[] = $this->harness($name, $script, $arguments);
        }
        foreach ($this->render($image->programRoles()) as $program) {
            if ($program['name'] === 'bootstrap' && ! $image->runsBootstrapProgram()) {
                continue;
            }
            $programs[] = $program;
        }

        return $programs;
    }

    /**
     * The SHA-256 of a program's exact arguments and input: the Gateway's task VM image manifest fingerprint
     * (`TaskVmBakedPrograms::fingerprint`). `orbit:node-programs` prints it for each program, and rendering
     * refuses a program whose fingerprint differs, so the two formats cannot drift apart.
     *
     * @param  Program  $program
     */
    public static function fingerprint(array $program): string
    {
        return hash('sha256', json_encode(
            ['arguments' => $program['arguments'], 'input' => $program['input']],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * The fingerprint of a whole program list, which the image records and `status` compares.
     *
     * @param  list<Program>  $programs
     */
    public static function listFingerprint(array $programs): string
    {
        return hash('sha256', json_encode(
            array_map(static fn (array $program): array => [$program['name'], self::fingerprint($program)], $programs),
            JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param  list<string>  $arguments
     * @return Program
     */
    private function harness(string $name, string $script, array $arguments): array
    {
        $input = file_get_contents($this->repositoryRoot.self::IMAGE_RESOURCES.$script);
        if (! is_string($input) || $input === '') {
            throw new RuntimeException("The role image script [{$script}] is missing.");
        }

        return ['name' => $name, 'user' => 'root', 'arguments' => ['bash', '-s', '--', ...$arguments], 'input' => $input];
    }

    /**
     * Renders the enrollment programs with a throwaway Orbit home. The bootstrap program embeds the Gateway's
     * SSH public key, so the home holds one fixed placeholder key: renders stay identical, which keeps the
     * fingerprint stable. Its private half was discarded, and the image build removes every authorized key
     * before it publishes the image.
     *
     * @param  list<string>  $roles
     * @return list<Program>
     */
    private function render(array $roles): array
    {
        GatewayEnvironment::ensure($this->repositoryRoot);
        $home = sys_get_temp_dir().'/orbit-e2e-programs-'.bin2hex(random_bytes(8));
        try {
            if (! mkdir($home.'/ssh', 0700, true) || file_put_contents($home.'/ssh/id_ed25519.pub', self::PLACEHOLDER_KEY."\n") === false) {
                throw new RuntimeException('Unable to create the throwaway Orbit home.');
            }
            $result = Process::timeout(120)->run([
                'env', '-i', 'PATH=/usr/local/bin:/usr/bin:/bin', "HOME={$home}", "ORBIT_HOME={$home}",
                PHP_BINARY, $this->repositoryRoot.'/apps/gateway/artisan', 'orbit:node-programs', ...$roles, '--json', '--no-interaction',
            ]);
        } finally {
            Process::run(['rm', '-rf', '--', $home]);
        }
        if ($result->failed()) {
            throw new RuntimeException(
                'orbit:node-programs '.implode(' ', $roles).' failed: '.trim($result->errorOutput()."\n".$result->output()),
            );
        }

        try {
            $decoded = json_decode(trim($result->output()), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('orbit:node-programs returned invalid JSON.', previous: $exception);
        }
        $programs = is_array($decoded) ? ($decoded['programs'] ?? null) : null;
        if (! is_array($programs) || ! array_is_list($programs) || $programs === []) {
            throw new RuntimeException('orbit:node-programs returned no programs.');
        }

        $rendered = [];
        foreach ($programs as $program) {
            if (
                ! is_array($program)
                || array_keys($program) !== ['name', 'arguments', 'input', 'fingerprint']
                || ! is_string($program['name'])
                || preg_match('/\A[a-z0-9-]{1,64}\z/D', $program['name']) !== 1
                || ! is_array($program['arguments'])
                || ! array_is_list($program['arguments'])
                || $program['arguments'] === []
                || array_filter($program['arguments'], static fn (mixed $argument): bool => ! is_string($argument)) !== []
                || ($program['input'] !== null && ! is_string($program['input']))
                || ! is_string($program['fingerprint'])
            ) {
                throw new RuntimeException('orbit:node-programs returned an invalid program.');
            }
            $arguments = array_values(array_filter($program['arguments'], is_string(...)));
            $entry = ['name' => $program['name'], 'user' => 'orbit', 'arguments' => $arguments, 'input' => $program['input']];
            if (! hash_equals($program['fingerprint'], self::fingerprint($entry))) {
                throw new RuntimeException("orbit:node-programs fingerprints [{$program['name']}] differently from the harness.");
            }
            $rendered[] = $entry;
        }

        return $rendered;
    }
}
