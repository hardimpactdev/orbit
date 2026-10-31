<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Fleet\AdmitDockerMigrationAction;
use App\Data\Operations\OperationData;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fleet\DockerMigrationRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Target)]
final class FleetDockerMigrationController extends Controller
{
    #[StartsOperation('fleet.docker_migrate')]
    public function store(DockerMigrationRequest $request, Node $node, RecipeLoader $recipes, AdmitDockerMigrationAction $admit): JsonResponse
    {
        $recipe = $recipes->load();
        if ($request->has('recipe') && $request->validated('recipe') !== $recipe->identity) {
            throw new ResourceOperationException('node.recipe_changed', 'The requested recipe differs from the serving recipe.', 409);
        }
        $caller = $request->getUserResolver()();
        $operation = $admit->execute($node, $recipe, ValidatedData::string($request->validated('mode')),
            $request->validated('fallback') === true, $caller instanceof Node ? $caller->name : null, $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }
}
