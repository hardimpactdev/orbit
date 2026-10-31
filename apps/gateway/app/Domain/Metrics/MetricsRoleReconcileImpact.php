<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Shared\ResourceOperationException;
use App\Support\ValidatedData;

/** Metrics owns whether a role transition changes any desired fleet effect. */
final readonly class MetricsRoleReconcileImpact
{
    public function __construct(private MetricsFleetCapture $capture) {}

    /** @param array<string,mixed> $before Immutable owner capture before the role intent.
     * @param  array<string,mixed>  $after  Immutable owner capture after the role intent.
     * @return array{required:bool,before_sha256:string,after_sha256:string}
     */
    public function compare(array $before, array $after): array
    {
        $current = $this->capture->capture();
        $inventory = array_column(MetricsFleetCapture::members($current), 'node_id');
        foreach ([$before, $after] as $candidate) {
            MetricsFleetCapture::assertDigest($candidate);
            if (array_column(MetricsFleetCapture::members($candidate), 'node_id') !== $inventory) {
                throw new ResourceOperationException('metrics.snapshot_changed', 'A role comparison must retain the complete owning fleet.', 409);
            }
        }
        if (! hash_equals($this->desiredHash($current), $this->desiredHash($after))) {
            throw new ResourceOperationException('metrics.snapshot_changed', 'The post-role desired fleet changed before comparison.', 409);
        }
        $beforeHash = $this->desiredHash($before);
        $afterHash = $this->desiredHash($after);

        return ['required' => ! hash_equals($beforeHash, $afterHash), 'before_sha256' => $beforeHash, 'after_sha256' => $afterHash];
    }

    /** @param array<string,mixed> $capture */
    private function desiredHash(array $capture): string
    {
        MetricsFleetCapture::assertDigest($capture);
        $members = MetricsFleetCapture::members($capture);
        $ids = ValidatedData::integerList(array_column($members, 'node_id'));
        if (count($ids) !== count(array_unique($ids))) {
            throw new ResourceOperationException('metrics.snapshot_changed', 'The Metrics capture repeats a Node.', 422);
        }
        // Availability and executable delivery do not change the desired monitoring configuration.
        // Every member and its selection remains in this comparison, including worker-free Nodes.
        unset($capture['digest']);
        foreach ($members as &$member) {
            unset($member['worker'], $member['agent_sha256'], $member['selection_reason']);
        }
        unset($member);
        $capture['fleet'] = $members;

        return MetricsFleetCapture::digest($capture);
    }
}
