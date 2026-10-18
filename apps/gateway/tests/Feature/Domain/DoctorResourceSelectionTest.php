<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\DoctorResourceKind;
use App\Domain\Doctor\DoctorResources;
use App\Domain\Doctor\NodeInspectionData;
use App\Models\Node;
use App\Models\RouteCustomProxy;
use Illuminate\Database\Eloquent\Collection;

it('selects only matching typed resources and preserves the collection', function (DoctorResourceKind $kind, string $key): void {
    $rows = new Collection([new RouteCustomProxy()->forceFill(['id' => 7, 'route_id' => 70]), new RouteCustomProxy()->forceFill(['id' => 8, 'route_id' => 80])]);
    $context = new DoctorNodeContext(new Node, new NodeInspectionData(true, null, null, null), resources: new DoctorResources($kind, [$key === 'id' ? 8 : 80]));

    $selected = $context->select($rows, $kind, $key);

    expect($selected)->toBeInstanceOf(Collection::class)->and($selected->pluck('id')->all())->toBe([8]);
    expect($context->select($rows, DoctorResourceKind::Inventory))->toBeEmpty();
})->with([
    'rows' => [DoctorResourceKind::Rows, 'id'],
    'attachments' => [DoctorResourceKind::Attachments, 'id'],
    'connections' => [DoctorResourceKind::Connections, 'id'],
    'route IDs of proxies' => [DoctorResourceKind::Rows, 'route_id'],
]);

it('keeps all rows for a complete inspection and all environments for their group', function (): void {
    $rows = new Collection([new Node()->forceFill(['id' => 1]), new Node()->forceFill(['id' => 2])]);
    $context = new DoctorNodeContext(new Node, new NodeInspectionData(true, null, null, null));
    $environments = new DoctorNodeContext(new Node, $context->inspection, resources: new DoctorResources(DoctorResourceKind::Environments));

    expect($context->select($rows))->toBe($rows)
        ->and($environments->select($rows))->toBeEmpty()
        ->and($environments->select($rows, DoctorResourceKind::Environments)->modelKeys())->toBe([1, 2]);
});
