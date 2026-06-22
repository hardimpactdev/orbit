<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\OperationRun;
use App\Services\Operations\GatewayCliArtifactRelay;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final readonly class UpdateArtifactDownloadController
{
    public function __construct(
        private GatewayCliArtifactRelay $artifacts,
    ) {}

    public function __invoke(Request $request, OperationRun $operationRun, string $platform): BinaryFileResponse
    {
        $token = $request->query('token');

        try {
            $path = $this->artifacts->downloadPath(
                operationRun: $operationRun,
                platform: $platform,
                token: is_string($token) ? $token : null,
            );
        } catch (Throwable $throwable) {
            abort($throwable->getMessage() === 'The update artifact token is invalid.' ? 403 : 404);
        }

        return response()->download($path, "orbit-{$platform}");
    }
}
