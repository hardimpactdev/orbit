<?php

declare(strict_types=1);

namespace App\Domain\ProductionSmoke;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;
use InvalidArgumentException;

/** Public input adapter for the shared native start; it performs no execution. */
final readonly class ProductionSmokeProjection implements DirectOperationProjection
{
    public const array Declaration = ['workflow' => 'automatic-production-smoke', 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'result', 'step' => 'result'], 'projection' => self::class];

    private const array ChangeFields = ['source', 'before_sha', 'after_sha', 'changed_paths', 'previous_release_id', 'orbit_sha', 'swamp_tree_sha', 'source_mirror_path', 'merge_mainline'];

    private const array SiteFields = ['instance_id', 'project_id', 'worker_node_id', 'mirror_path', 'commit', 'deployment_id', 'base_url'];

    /** The release/load owner supplies admitted metadata, never environment values.
     * @return array<string, mixed>
     */
    public function input(Operation $operation): array
    {
        $input = $operation->input;
        if ($operation->kind !== 'production.smoke' || ! is_int($operation->node_id)
            || ! is_array($input['change'] ?? null) || ! is_array($input['sites'] ?? null) || $input['sites'] === []) {
            throw new InvalidArgumentException('Production smoke needs an admitted change and site inventory.');
        }
        $sites = [];
        $ids = [];
        foreach ($input['sites'] as $site) {
            if (! is_array($site) || ! is_int($site['instance_id'] ?? null) || in_array($site['instance_id'], $ids, true)) {
                throw new InvalidArgumentException('Production smoke needs distinct Instance IDs.');
            }
            $ids[] = $site['instance_id'];
            $sites[] = array_intersect_key($site, array_flip(self::SiteFields));
        }

        return [
            'intent' => [
                'change' => array_intersect_key($input['change'], array_flip(self::ChangeFields)),
                'sites' => $sites,
            ],
            'execution_secrets' => [],
        ];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return app(DirectOperation::class)->project($operation, $data, ValidatedData::object($data['result']));
    }
}
