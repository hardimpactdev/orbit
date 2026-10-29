<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\PhpBuildInstaller;
use App\Infrastructure\MachineSoftware\VitePlusRuntimeLock;
use App\Models\Node;
use RuntimeException;

/** Compiles immutable recipe input without executing guest phases. */
final readonly class RecipeImageInput
{
    public function __construct(private AgentDelivery $delivery) {}

    /** @return array{identity: string, recipe: array{user: string, snapshot: string, architecture: string, tools: array<string, mixed>|object, vp_lock: string|null, git_packages: list<string>, artifact_mirror: string}, rust: array<string, mixed>|object, php: array<string, mixed>|null, agent_sha256: string, pi_sha256: string|null} */
    public function payload(Node|AgentGuest $guest, MachineRecipe $recipe, string $profile): array
    {
        $this->assertDelivery($guest, $recipe);
        $architecture = MachineRecipe::architecture($guest->architecture)
            ?? throw new TaskVmException(TaskVmError::ImageBuildFailed, 'The image architecture is unsupported.');
        $agent = $recipe->agentDigest($architecture);
        $names = array_unique([...$recipe->profile('base', $architecture), ...$recipe->profile($profile, $architecture)]);
        $data = json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_array($data['tools'] ?? null)) {
            throw new RuntimeException('The stored recipe tools are invalid.');
        }
        $tools = [];
        foreach ($names as $name) {
            if (! is_array($data['tools'][$name] ?? null)) {
                throw new RuntimeException('A selected recipe tool is missing.');
            }
            $tools[$name] = $data['tools'][$name];
            $tools[$name]['version'] = $recipe->tools[$name]->forArchitecture($architecture)->version;
        }
        $versions = array_map(strval(...), array_keys($recipe->phpExtensions(array_keys($tools))));
        $php = $versions === [] ? null : PhpBuildInstaller::input($recipe, $versions, in_array($profile, $recipe->phpPolicy->pcovProfiles, true), 'refuse', 'none', opentelemetry: in_array($profile, $recipe->phpPolicy->opentelemetryProfiles, true));
        if ($php !== null) {
            foreach ($versions as $version) {
                foreach ($recipe->php($version)->packagesFor(in_array($profile, $recipe->phpPolicy->opentelemetryProfiles, true)) as $package) {
                    unset($tools[$package]);
                }
            }
        }
        $rust = array_intersect_key($tools, array_flip(['rust', 'cargo', 'rust-std', 'clippy', 'rustfmt']));
        $pi = isset($tools['pi']) ? $recipe->tools['pi']->artifacts[$architecture]->digest : null;
        unset($tools['pi']);
        $tools = array_diff_key($tools, $rust);

        return ['identity' => $recipe->identity, 'recipe' => ['user' => 'orbit', 'snapshot' => $recipe->snapshot, 'architecture' => $architecture,
            'tools' => $tools === [] ? (object) [] : $tools, 'vp_lock' => isset($tools['vp']) ? VitePlusRuntimeLock::read($recipe->tools['vp']) : null,
            'git_packages' => $recipe->gitPackages, 'artifact_mirror' => $recipe->artifactMirror],
            'rust' => $rust === [] ? (object) [] : $rust, 'php' => $php, 'agent_sha256' => $agent, 'pi_sha256' => $pi];
    }

    public function assertDelivery(Node|AgentGuest $guest, MachineRecipe $recipe): void
    {
        if ($recipe->agentDigest($guest->architecture ?? throw new \LogicException('The image architecture is missing.')) !== $this->delivery->pin($guest)) {
            throw new TaskVmException(TaskVmError::ImageRecipeSuperseded, 'The stored recipe agent pin differs from the serving delivery. This build cannot continue.');
        }
    }
}
