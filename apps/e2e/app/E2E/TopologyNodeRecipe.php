<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\MountPath;
use App\E2E\Value\TopologyNode;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

/** Reads public metadata. Bootstrap and enrollment effects use their product workflows. */
final readonly class TopologyNodeRecipe
{
    public const string PLACEHOLDER_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPb2YoLP14UR+XxEQ1nPvQAlpRc7B9/4xS201KFQ3tyu orbit-e2e-node-recipe';

    public function __construct(private string $repositoryRoot) {}

    /** @return array{node:array<string,mixed>,bootstrap:array<string,mixed>,recipe:array<string,mixed>,agent:array{path:string,sha256:string}} */
    public function for(TopologyNode $node, string $architecture = 'x86_64'): array
    {
        GatewayEnvironment::ensure($this->repositoryRoot);
        $home = sys_get_temp_dir().'/orbit-e2e-recipe-'.bin2hex(random_bytes(8));
        try {
            if (! mkdir($home.'/ssh', 0700, true) || file_put_contents($home.'/ssh/id_ed25519.pub', self::PLACEHOLDER_KEY."\n") === false) {
                throw new RuntimeException('Unable to create the throwaway Orbit home.');
            }
            $result = Process::timeout(120)->run([
                'env', '-i', 'PATH=/usr/local/bin:/usr/bin:/bin', "HOME={$home}", "ORBIT_HOME={$home}",
                PHP_BINARY, $this->repositoryRoot.'/apps/gateway/artisan', 'orbit:node-recipe', ...$node->roles,
                '--architecture='.$architecture, '--json', '--no-interaction',
            ]);
        } finally {
            Process::run(['rm', '-rf', '--', $home]);
        }
        if ($result->failed()) {
            throw new RuntimeException('orbit:node-recipe failed: '.trim($result->errorOutput()."\n".$result->output()));
        }
        try {
            $decoded = json_decode(trim($result->output()), false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('orbit:node-recipe returned invalid JSON.', previous: $exception);
        }
        $expected = ['platform' => 'linux', 'architecture' => $architecture, 'user' => 'orbit', 'home' => '/home/orbit', 'roles' => $node->roles];
        if (! $decoded instanceof \stdClass || count(get_object_vars($decoded)) !== 5 || array_diff(['schema_version', 'node', 'bootstrap', 'recipe', 'agent'], array_keys(get_object_vars($decoded))) !== []
            || $decoded->schema_version !== 1 || ! $decoded->node instanceof \stdClass || get_object_vars($decoded->node) !== $expected
            || ! $decoded->bootstrap instanceof \stdClass || ! $decoded->recipe instanceof \stdClass
            || get_object_vars($decoded->bootstrap) === [] || get_object_vars($decoded->recipe) === []) {
            throw new RuntimeException('orbit:node-recipe returned invalid Node or recipe metadata.');
        }
        $agent = $decoded->agent instanceof \stdClass ? get_object_vars($decoded->agent) : null;
        $prefix = $this->repositoryRoot.'/';
        if (! is_array($agent) || array_keys($agent) !== ['path', 'sha256'] || ! is_string($agent['path'])
            || ! str_starts_with($agent['path'], $prefix) || preg_match('#(?:^|/)\.\.?(?:/|$)#', $agent['path']) === 1
            || ! is_string($agent['sha256']) || preg_match('/\A[0-9a-f]{64}\z/D', $agent['sha256']) !== 1) {
            throw new RuntimeException('orbit:node-recipe returned no observer inside the worktree.');
        }

        return ['node' => $expected, 'bootstrap' => get_object_vars($decoded->bootstrap), 'recipe' => get_object_vars($decoded->recipe),
            'agent' => ['path' => MountPath::GUEST_SOURCE.'/'.substr($agent['path'], strlen($prefix)), 'sha256' => $agent['sha256']]];
    }
}
