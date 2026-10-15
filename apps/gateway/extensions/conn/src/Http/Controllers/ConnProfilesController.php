<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Extensions\Conn\Actions\CreateConnProfileAction;
use Orbit\Extensions\Conn\Actions\PatchConnProfileSettingsAction;
use Orbit\Extensions\Conn\Actions\ReplaceConnProfileSettingsAction;
use Orbit\Extensions\Conn\Data\ConnProfileData;
use Orbit\Extensions\Conn\Data\ConnProfileSettingsData;
use Orbit\Extensions\Conn\Http\Requests\PatchConnProfileSettingsRequest;
use Orbit\Extensions\Conn\Http\Requests\ReplaceConnProfileSettingsRequest;
use Orbit\Extensions\Conn\Http\Requests\StoreConnProfileRequest;
use Orbit\Extensions\Conn\Models\ConnProfile;

final class ConnProfilesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ConnProfile::query()
                ->orderBy('name')
                ->get()
                ->map(static fn (ConnProfile $profile): array => ConnProfileData::fromModel($profile)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    public function store(StoreConnProfileRequest $request, CreateConnProfileAction $action): JsonResponse
    {
        return response()->json([
            'data' => ConnProfileData::fromModel($action->execute($request->payload()))->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    public function settings(Request $request, ConnProfile $profile): JsonResponse
    {
        return response()->json([
            'data' => ConnProfileSettingsData::fromModel($profile)->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    public function replaceSettings(
        ReplaceConnProfileSettingsRequest $request,
        ConnProfile $profile,
        ReplaceConnProfileSettingsAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => ConnProfileSettingsData::fromModel($action->execute($profile, $this->caller($request), $request->payload()))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    public function patchSettings(
        PatchConnProfileSettingsRequest $request,
        ConnProfile $profile,
        PatchConnProfileSettingsAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => ConnProfileSettingsData::fromModel($action->execute($profile, $this->caller($request), $request->payload()))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    private function caller(Request $request): Node
    {
        $node = $request->user();
        assert($node instanceof Node, description: 'Authenticated peer must be a Node.');

        return $node;
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
