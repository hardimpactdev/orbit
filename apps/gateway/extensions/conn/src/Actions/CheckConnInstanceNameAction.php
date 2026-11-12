<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Domain\Instances\DevelopmentInstanceCreateAdmission;
use App\Domain\Instances\InstanceName;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Project;
use Orbit\Extensions\Conn\Data\ConnInstanceNameCheckData;
use Orbit\Extensions\Conn\Domain\ConnInstanceDomains;
use Orbit\Extensions\Conn\Domain\ConnInstanceNameReason;
use Orbit\Extensions\Conn\Domain\ConnInstanceNames;

final readonly class CheckConnInstanceNameAction
{
    public function __construct(private ConnInstanceNames $names, private ConnInstanceDomains $domains, private RouteStateResolver $routes, private DevelopmentInstanceCreateAdmission $admission) {}

    /** The request has already checked access and found the Project's ready default. */
    public function execute(Project $project, Instance $default, string $name): ConnInstanceNameCheckData
    {
        $reason = match (true) {
            ! InstanceName::isValid($name) => ConnInstanceNameReason::Invalid,
            $this->names->isDefault($project, $name) => ConnInstanceNameReason::Default,
            $this->names->occupied($project, $name) !== null => ConnInstanceNameReason::Taken,
            default => null,
        };

        $domain = $reason === ConnInstanceNameReason::Invalid ? null : $this->domains->domain($project, $default, $name);
        if ($reason === null && $domain !== null) {
            try {
                $this->admission->assertRouteDomain($domain);
            } catch (ResourceOperationException $exception) {
                if ($exception->errorCode !== 'route.domain_invalid') {
                    throw $exception;
                }
                $reason = ConnInstanceNameReason::DomainInvalid;
                $domain = null;
            }
        }
        if ($reason === null && $domain === null && $project->type->isWebServing()) {
            $node = $default->node;
            $tld = $this->routes->forNode($node, loadedCluster: $node->relationLoaded('cluster') ? $node->cluster : null)->effectiveTld;
            if ($tld !== null && $tld !== '') {
                $reason = ConnInstanceNameReason::DomainInvalid;
            }
        }

        return new ConnInstanceNameCheckData($name, $reason === null, $reason, $domain);
    }
}
