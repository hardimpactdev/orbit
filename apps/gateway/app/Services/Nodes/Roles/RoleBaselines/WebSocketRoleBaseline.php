<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Data\Nodes\RoleSettings\WebSocketRoleSettings;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Operations\ReleaseManifestResolver;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\Tools\ToolCatalog;
use App\Services\WebSockets\WebSocketCertificateInstaller;
use App\Services\WebSockets\WebSocketRoleBaselineTiming;
use App\Services\WebSockets\WebSocketRuntimeContainer;
use App\Services\WebSockets\WebSocketRuntimeContainerManager;
use App\Services\WebSockets\WebSocketRuntimeContainerRenderer;
use App\Services\WebSockets\WebSocketRuntimeSourceInstaller;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class WebSocketRoleBaseline implements RoleBaseline
{
    use ManagesNodeToolBaseline;

    public function __construct(
        private readonly WebSocketRuntimeContainerRenderer $runtimeRenderer,
        private readonly WebSocketRuntimeContainerManager $runtimeManager,
        private readonly WebSocketCertificateInstaller $certificateInstaller,
        private readonly WebSocketRuntimeSourceInstaller $sourceInstaller,
        private readonly ReleaseManifestResolver $manifests,
        private readonly ?RemoteLocalExecutor $localExecutor = null,
        private readonly ?NodeRoleAssignments $nodeRoleAssignments = null,
        private readonly ?ToolCatalog $toolCatalog = null,
        private readonly ?WebSocketRoleBaselineTiming $timing = null,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        if ($this->nodeRoleAssignments()->nodeIsGateway($node)) {
            throw new RuntimeException('The websocket role cannot be assigned to a gateway node.');
        }

        if (! str_starts_with((string) $node->platform, 'ubuntu')) {
            throw new RuntimeException('The websocket role requires an Ubuntu host.');
        }

        if (! is_string($node->host) || trim($node->host) === '') {
            throw new RuntimeException('The websocket role requires a reachable host record.');
        }

        $this->timer()->measure('tools', fn () => $this->convergeTools($node, ['docker']));
        $this->ensureManifestRuntimeImage($node);
        $selfContainedImage = $this->timer()->measure('image', fn (): bool => $this->runtimeImageIsSelfContained(
            $node,
        ));
        $appKey = $selfContainedImage
            ? $this->timer()->measure('env', fn (): string => $this->ensureSelfContainedAppKey($node))
            : null;
        $container = $this->timer()->measure(
            'render',
            fn (): WebSocketRuntimeContainer => $this->runtimeContainerFor(
                $node,
                $assignment,
                $selfContainedImage,
                $appKey,
            ),
        );
        $this->timer()->measure('certificates', fn () => $this->certificateInstaller->ensureFor($node));

        if (! $selfContainedImage) {
            $this->timer()->measure('source-install', fn () => $this->sourceInstaller->install($node));
        }

        $this->timer()->measure('container-apply', fn () => $this->runtimeManager->apply($node, $container));
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        $containerName = $this->runtimeRenderer->containerName($node);

        if ($this->runtimeManager->remove($node, $containerName)) {
            return;
        }

        throw new RuntimeException("Failed to remove websocket runtime container '{$containerName}' on {$node->name}.");
    }

    private function runtimeContainerFor(
        Node $node,
        NodeRoleAssignment $assignment,
        bool $selfContainedImage,
        ?string $appKey,
    ): WebSocketRuntimeContainer {
        $settings = WebSocketRoleSettings::fromArray($assignment->settings ?? []);

        return $this->runtimeRenderer->render(
            node: $node,
            settings: $settings,
            sourcePath: $selfContainedImage ? null : WebSocketRuntimeContainer::SourceHostPath,
            appKey: $appKey,
        );
    }

    private function runtimeImageIsSelfContained(Node $node): bool
    {
        $result = $this->runWebSocketRuntimeAction(
            node: $node,
            action: 'image:is-self-contained',
        );

        if (! $result->successful()) {
            return false;
        }

        return RemoteShellSuccessData::fromJsonEnvelope($result)['self_contained'] === true;
    }

    private function ensureManifestRuntimeImage(Node $node): void
    {
        try {
            $manifest = $this->manifests->resolve();
        } catch (ConnectionException) {
            return;
        }

        $image = $manifest->roleImages['orbit-websocket'] ?? null;

        if (
            ! is_string($image)
            || trim($image) === ''
        ) {
            return;
        }

        $trimmedImage = trim($image);

        if (
            $image !== $trimmedImage
            || preg_match('/\A[^@\s]+@sha256:[0-9a-f]{64}\z/i', $image) !== 1
        ) {
            return;
        }

        $payload = ['image' => $trimmedImage];
        $artifact = $this->manifestRuntimeImageArtifact($manifest->snapshot());

        if ($artifact !== null) {
            $payload['artifact'] = $artifact;
        }

        /** @var RemoteShellResult $result */
        $result = $this->timer()->measure(
            'image-ensure',
            fn (): RemoteShellResult => $this->runWebSocketRuntimeAction(
                node: $node,
                action: 'image:ensure',
                payload: $payload,
            ),
        );

        if (! $result->successful()) {
            throw new RuntimeException("Could not install websocket runtime image on {$node->name}.");
        }

        $data = RemoteShellSuccessData::fromJsonEnvelope($result);

        if (($data['self_contained'] ?? null) !== true) {
            throw new RuntimeException("Websocket runtime image on {$node->name} is not self-contained.");
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{url: string, sha256: string}|null
     */
    private function manifestRuntimeImageArtifact(array $snapshot): ?array
    {
        $artifacts = $snapshot['role_image_artifacts'] ?? null;

        if (! is_array($artifacts) || ! array_key_exists('orbit-websocket', $artifacts)) {
            return null;
        }

        $artifact = $artifacts['orbit-websocket'];
        $url = is_array($artifact) ? $artifact['url'] ?? null : null;
        $sha256 = is_array($artifact) ? $artifact['sha256'] ?? null : null;

        if (
            ! is_string($url)
            || trim($url) === ''
            || ! is_string($sha256)
            || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1
        ) {
            throw new RuntimeException('Websocket runtime manifest image artifact is invalid.');
        }

        return ['url' => trim($url), 'sha256' => $sha256];
    }

    private function ensureSelfContainedAppKey(Node $node): string
    {
        $result = $this->runWebSocketRuntimeAction(
            node: $node,
            action: 'app-key:ensure',
        );

        $data = RemoteShellSuccessData::fromJsonEnvelope($result);
        /** @var mixed $appKey */
        $appKey = $data['app_key'] ?? null;

        if (! $result->successful() || ! is_string($appKey) || trim($appKey) === '') {
            throw new RuntimeException("Could not prepare websocket runtime app key on {$node->name}.");
        }

        return trim($appKey);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function runWebSocketRuntimeAction(Node $node, string $action, array $payload = []): RemoteShellResult
    {
        $transportOptions = [
            'metadata' => [
                'ORBIT_OPERATION_ID' => "websocket-runtime.{$action}",
            ],
            'timeout' => $action === 'image:ensure' ? 360 : 30,
            'throw' => false,
        ];

        if ($payload !== []) {
            $transportOptions['input'] = json_encode($payload, JSON_THROW_ON_ERROR);
        }

        return $this->localExecutor()->runInternal(
            node: $node,
            commandName: 'internal:websocket-runtime',
            arguments: [$action],
            transportOptions: $transportOptions,
        );
    }

    private function localExecutor(): RemoteLocalExecutor
    {
        return $this->localExecutor ?? app(RemoteLocalExecutor::class);
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return $this->nodeRoleAssignments ?? app(NodeRoleAssignments::class);
    }

    protected function toolCatalog(): ToolCatalog
    {
        return $this->toolCatalog ?? app(ToolCatalog::class);
    }

    private function timer(): WebSocketRoleBaselineTiming
    {
        return $this->timing ?? app(WebSocketRoleBaselineTiming::class);
    }
}
