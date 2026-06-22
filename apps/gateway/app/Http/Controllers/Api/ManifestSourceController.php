<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Requests\Api\ManifestUpdateApiRequest;
use App\Models\Node;
use App\Services\Operations\ReleaseManifestSourceManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('*', servingNode: ServingNode::Gateway)]
final class ManifestSourceController implements Loggable
{
    private ?Node $activitySubject = null;

    private ?string $activityAction = null;

    private ?string $activityUrl = null;

    public function update(
        ManifestUpdateApiRequest $request,
        ReleaseManifestSourceManager $manifestSources,
    ): JsonResponse {
        $this->captureActivitySubject($request);
        $this->activityAction = 'updated';

        $manifest = $manifestSources->update($request->url());
        $this->activityUrl = $manifest['url'];

        return response()->json([
            'success' => [
                'data' => [
                    'manifest' => $manifest,
                ],
            ],
        ]);
    }

    public function destroy(Request $request, ReleaseManifestSourceManager $manifestSources): JsonResponse
    {
        $this->captureActivitySubject($request);
        $this->activityAction = 'removed';

        $manifest = $manifestSources->remove();
        $this->activityUrl = $manifest['url'];

        return response()->json([
            'success' => [
                'data' => [
                    'manifest' => $manifest,
                ],
            ],
        ]);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return match ($this->activityAction) {
            'removed' => 'api:DELETE /manifest',
            default => 'api:PUT /manifest',
        };
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return array_filter([
            'action' => $this->activityAction,
            'manifest_url' => $this->activityUrl,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function description(): ?string
    {
        return null;
    }

    private function captureActivitySubject(Request $request): void
    {
        /** @var mixed $caller */
        $caller = $request->user();

        $this->activitySubject = $caller instanceof Node ? $caller : null;
    }
}
