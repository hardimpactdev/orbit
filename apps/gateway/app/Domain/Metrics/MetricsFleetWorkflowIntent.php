<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Routes\CaddyPublicationPlan;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Support\ValidatedData;

/** Complete public intent, captured once before native submission. */
final readonly class MetricsFleetWorkflowIntent
{
    /** @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    public function capture(array $plan): array
    {
        MetricsFleetCapture::assertDigest($plan);
        $publications = [];
        foreach (MetricsFleetCapture::members($plan) as $member) {
            if (! $member['worker']) {
                throw new ResourceOperationException('metrics.no_worker', 'Every Metrics participant requires its exact worker.', 409);
            }
            if ($member['caddy_managed']) {
                $publication = CaddyPublicationPlan::forNode(Node::query()->findOrFail($member['node_id']));
                if (isset($publication['error_code'])) {
                    throw new ResourceOperationException('caddy.render_failed', 'The admitted Metrics publication cannot be rendered.', 409);
                }
                $publications[] = $publication;
            }
        }

        return ['participant_node_ids' => array_column(MetricsFleetCapture::members($plan), 'node_id'),
            'metrics_fleet' => $plan, 'caddy_publications' => $publications];
    }

    /** Read immutable public capture; do not render or fetch intent during execution.
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function fromInput(array $input): array
    {
        return ['participant_node_ids' => ValidatedData::integerList($input['participant_node_ids']),
            'metrics_fleet' => ValidatedData::object($input['metrics_fleet']),
            'caddy_publications' => $input['caddy_publications']];
    }
}
