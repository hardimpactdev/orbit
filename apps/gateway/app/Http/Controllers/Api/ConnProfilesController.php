<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Conn\CreateConnProfileAction;
use App\Actions\Conn\PatchConnProfileSettingsAction;
use App\Actions\Conn\ReplaceConnProfileSettingsAction;
use App\Data\Conn\ConnProfileData;
use App\Data\Conn\ConnProfileSettingsData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conn\PatchConnProfileSettingsRequest;
use App\Http\Requests\Conn\ReplaceConnProfileSettingsRequest;
use App\Http\Requests\Conn\StoreConnProfileRequest;
use App\Models\ConnProfile;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
