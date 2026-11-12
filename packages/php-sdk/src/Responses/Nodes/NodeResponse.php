<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Support\GatewayErrorCode;
use SensitiveParameter;

final readonly class NodeResponse
{
    /**
     * @param  list<string>  $roles
     * @param  list<NodeRoleAssignmentResponse>  $assignments
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $status,
        public string $publicSshHost,
        public int $publicSshPort,
        public string $user,
        public ?string $wireguardIp,
        public array $roles,
        public string $requestId,
        public ?int $clusterId = null,
        public ?string $platform = null,
        public ?string $architecture = null,
        public ?string $lanIp = null,
        public ?string $sshHostFingerprint = null,
        public ?string $failedStep = null,
        public ?string $errorCode = null,
        public ?string $tld = null,
        public ?string $wireguardPublicKey = null,
        public ?string $dnsServerOverride = null,
        public ?NodeAccessResponse $access = null,
        public ?NodeSettings $settings = null,
        public ?NodeUpdatingResponse $updating = null,
        public ?NodeAgentResponse $agent = null,
        public ?string $clusterName = null,
        public array $assignments = [],
        public bool $rebootHold = false,
        public bool $imageRefreshHold = false,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            rebootHold: ($data['reboot_hold'] ?? false) === true,
            imageRefreshHold: ($data['image_refresh_hold'] ?? false) === true,
            clusterId: is_int($data['cluster_id'] ?? null) ? $data['cluster_id'] : null,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            platform: is_string($data['platform'] ?? null) ? $data['platform'] : null,
            architecture: is_string($data['architecture'] ?? null) ? $data['architecture'] : null,
            tld: is_string($data['tld'] ?? null) ? $data['tld'] : null,
            publicSshHost: is_string($data['public_ssh_host'] ?? null) ? $data['public_ssh_host'] : '',
            publicSshPort: is_int($data['public_ssh_port'] ?? null) ? $data['public_ssh_port'] : 0,
            user: is_string($data['user'] ?? null) && $data['user'] !== '' ? $data['user'] : 'orbit',
            wireguardIp: is_string($data['wireguard_ip'] ?? null) ? $data['wireguard_ip'] : null,
            lanIp: is_string($data['lan_ip'] ?? null) ? $data['lan_ip'] : null,
            wireguardPublicKey: is_string($data['wireguard_public_key'] ?? null)
                ? $data['wireguard_public_key']
                : null,
            dnsServerOverride: is_string($data['dns_server_override'] ?? null)
                ? $data['dns_server_override']
                : null,
            sshHostFingerprint: is_string($data['ssh_host_fingerprint'] ?? null)
                ? $data['ssh_host_fingerprint']
                : null,
            failedStep: is_string($data['failed_step'] ?? null) ? $data['failed_step'] : null,
            errorCode: GatewayErrorCode::fromTransport($data['error_code'] ?? null),
            roles: self::stringList($data['roles'] ?? []),
            requestId: $requestId,
            access: is_array($data['access'] ?? null) ? NodeAccessResponse::fromGatewayData($data['access']) : null,
            settings: self::settings($data['settings'] ?? null),
            updating: NodeUpdatingResponse::tryFromGatewayData($data['updating'] ?? null),
            agent: NodeAgentResponse::tryFromGatewayData($data['agent'] ?? null),
            clusterName: is_string($data['cluster_name'] ?? null) ? $data['cluster_name'] : null,
            assignments: self::assignmentList(array_key_exists('assignments', $data) ? $data['assignments'] : [], $requestId),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'cluster_id' => $this->clusterId,
            'name' => $this->name,
            'status' => $this->status,
            'platform' => $this->platform,
            'architecture' => $this->architecture,
            'tld' => $this->tld,
            'public_ssh_host' => $this->publicSshHost,
            'public_ssh_port' => $this->publicSshPort,
            'user' => $this->user,
            'wireguard_ip' => $this->wireguardIp,
            'lan_ip' => $this->lanIp,
            'reboot_hold' => $this->rebootHold,
            'image_refresh_hold' => $this->imageRefreshHold,
            'wireguard_public_key' => $this->wireguardPublicKey,
            'dns_server_override' => $this->dnsServerOverride,
            'ssh_host_fingerprint' => $this->sshHostFingerprint,
            'failed_step' => $this->failedStep,
            'error_code' => $this->errorCode,
            'roles' => $this->roles,
            'settings' => $this->settings?->toArray(),
            'updating' => $this->updating?->toArray(),
            'agent' => $this->agent?->toArray(),
            'cluster_name' => $this->clusterName,
            'assignments' => array_map(
                static fn (NodeRoleAssignmentResponse $assignment): array => $assignment->toArray(),
                $this->assignments,
            ),
            'request_id' => $this->requestId,
        ];

        if ($this->access instanceof NodeAccessResponse) {
            $data['access'] = $this->access->toArray();
        }

        return $data;
    }

    /** @return list<NodeRoleAssignmentResponse> */
    private static function assignmentList(mixed $value, string $requestId): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new GatewayApiException('Gateway response contains invalid node role assignments.', requestId: $requestId);
        }

        $result = [];

        foreach ($value as $item) {
            if (! self::stringKeyedMap($item)) {
                throw new GatewayApiException('Gateway response contains an invalid node role assignment.', requestId: $requestId);
            }

            $result[] = NodeRoleAssignmentResponse::fromGatewayData($item, $requestId);
        }

        return $result;
    }

    /** @phpstan-assert-if-true array<string, mixed> $value */
    private static function stringKeyedMap(mixed $value): bool
    {
        return is_array($value)
            && ! array_is_list($value)
            && array_all(array_keys($value), static fn (int|string $key): bool => is_string($key));
    }

    private static function settings(mixed $value): ?NodeSettings
    {
        if (! is_array($value)) {
            return null;
        }

        $settings = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            $settings[$key] = $item;
        }

        return NodeSettings::fromGatewayData($settings);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $result[] = $item;
        }

        return $result;
    }
}
