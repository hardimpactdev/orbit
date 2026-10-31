<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\MachineSoftware\VitePlusRuntimeLock;
use App\Infrastructure\Nodes\AgentCaddyPackage;

/** Only the admitted enrollment roles select software; execution receives immutable pins. */
final readonly class NodeEnrollmentToolInput
{
    public function __construct(private RecipeLoader $recipes) {}

    /**
     * @param  list<RoleName>  $roles
     * @return array{schema_version:1,node_id:int,user:string,recipe_identity:string,apt:array{snapshot:string,packages:list<array{name:string,version:string,artifact:array{url:string,algorithm:string,digest:string}|null}>},caddy:array<string,mixed>|null,javascript:array{user:string,tools:array<string,array{version:string,url:string,algorithm:string,digest:string,lock_sha256?:string}>,vp_lock:string}|null,composer:array{user:string,version:string}|null,browser:array{version:string,url:string,algorithm:string,digest:string}|null}
     */
    public function capture(NodeEnrollmentCandidate $candidate, array $roles): array
    {
        if ($candidate->platform !== 'linux' || ! in_array($candidate->architecture, [null, 'x86_64', 'amd64'], true)) {
            throw new ResourceOperationException('node.platform_unsupported', 'Enrollment tool installation requires Linux x86_64.', 422);
        }
        $user = $candidate->user;
        if ($user === 'root' || ! LinuxUserName::isValid($user)) {
            throw new ResourceOperationException('node.invalid_linux_user', 'Enrollment tools require the admitted managed account.', 422);
        }
        $recipe = $this->recipes->load();
        $tools = [];
        foreach ($roles === [] ? ['operator'] : array_map(static fn (RoleName $role): string => $role->value, $roles) as $profile) {
            foreach ($recipe->profile($profile, 'amd64') as $name) {
                $tools[$name] = $recipe->tools[$name]->forArchitecture('amd64');
            }
        }
        ksort($tools);
        $packages = [];
        $javascript = [];
        $browser = null;
        foreach ($tools as $name => $pin) {
            if (in_array($name, NodeRecipeProgram::JavascriptTools, true)) {
                $artifact = $pin->artifacts['amd64'];
                $javascript[$name] = ['version' => $pin->version, 'url' => $artifact->location,
                    'algorithm' => $artifact->algorithm, 'digest' => $artifact->digest,
                    ...($pin->lockSha256 === null ? [] : ['lock_sha256' => $pin->lockSha256])];
            } elseif ($name === 'chrome-headless-shell') {
                $artifact = $pin->artifacts['amd64'];
                $browser = ['version' => $pin->version, 'url' => $artifact->location,
                    'algorithm' => $artifact->algorithm, 'digest' => $artifact->digest];
            } elseif ($name !== 'caddy') {
                $artifact = $pin->artifacts['amd64'] ?? null;
                $packages[] = ['name' => $name, 'version' => $pin->version,
                    'artifact' => $artifact === null ? null : ['url' => $artifact->location,
                        'algorithm' => $artifact->algorithm, 'digest' => $artifact->digest]];
            }
        }

        return [
            'schema_version' => 1,
            'node_id' => $candidate->nodeId,
            'user' => $user,
            'recipe_identity' => $recipe->identity,
            'apt' => ['snapshot' => $recipe->snapshot, 'packages' => $packages],
            'caddy' => isset($tools['caddy']) ? AgentCaddyPackage::input() : null,
            'javascript' => $javascript === [] ? null : ['user' => $user, 'tools' => $javascript,
                'vp_lock' => VitePlusRuntimeLock::read($tools['vp'])],
            'browser' => $browser,
            'composer' => isset($tools['composer']) ? ['user' => $user, 'version' => $tools['composer']->version] : null,
        ];
    }
}
