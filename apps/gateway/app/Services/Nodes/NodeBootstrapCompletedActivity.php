<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\NodeBootstrap;
use Illuminate\Database\Eloquent\Model;

final readonly class NodeBootstrapCompletedActivity implements Loggable
{
    public function __construct(
        private NodeBootstrap $bootstrap,
    ) {}

    #[\Override]
    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    #[\Override]
    public function type(): string
    {
        return 'node.created';
    }

    #[\Override]
    public function subject(): ?Model
    {
        return $this->bootstrap->node()->first();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function properties(): array
    {
        $request = $this->bootstrap->request;
        /** @var mixed $roles */
        $roles = $request['--roles'] ?? null;

        return [
            'name' => is_string($request['name'] ?? null) ? $request['name'] : null,
            'roles' => is_string($roles) ? array_values(array_filter(explode(',', $roles))) : [],
            'tld' => is_string($request['--tld'] ?? null) ? $request['--tld'] : null,
            'template' => is_string($request['--template'] ?? null) ? $request['--template'] : null,
        ];
    }

    #[\Override]
    public function description(): ?string
    {
        /** @var mixed $name */
        $name = $this->properties()['name'];

        return is_string($name) ? "Created node {$name}." : null;
    }
}
