<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Fleet\AdmitNodeRecipeAction;
use App\Data\Operations\OperationData;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fleet\AdmitNodeRecipeRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Target)]
final class FleetRecipesController extends Controller
{
    #[StartsOperation('fleet.recipe')]
    public function store(AdmitNodeRecipeRequest $request, Node $node, AdmitNodeRecipeAction $action, RecipeLoader $loader): JsonResponse
    {
        $recipe = $loader->load();
        if ($request->has('recipe') && $request->validated('recipe') !== $recipe->identity) {
            throw new ResourceOperationException('node.recipe_changed', 'The requested recipe does not match the serving recipe.', 409);
        }
        $caller = $request->getUserResolver()();
        $operation = $action->execute($node, $recipe, $request->boolean('automatic'),
            $request->validated('build_id') === null ? null : ValidatedData::integer($request->validated('build_id')),
            $caller instanceof Node ? $caller->name : null, $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }
}
