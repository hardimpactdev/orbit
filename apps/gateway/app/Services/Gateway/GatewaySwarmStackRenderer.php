<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Enums\Gateway\GatewayExposureMode;
use InvalidArgumentException;

final readonly class GatewaySwarmStackRenderer
{
    public const string Network = 'orbit-network';

    public const string GatewayService = 'orbit-gateway';

    public const string SchedulerService = 'orbit-scheduler';

    public const string RUNTIME_HIBERNATOR_SERVICE = 'orbit-runtime-hibernator';

    public const string OPERATIONS_REVERB_SERVICE = 'orbit-operations-reverb';

    public const string DEFAULT_OPERATIONS_REVERB_IMAGE = 'orbit-reverb:current';

    private const string OPERATIONS_WEB_SOCKET_CONFIG_PATH = '/etc/orbit/operations-websocket/apps.php';

    public function render(
        GatewayImageReference $image,
        GatewayExposureMode $exposureMode,
        string $configRoot = '/home/orbit/.config/orbit',
        string $installRoot = '/home/orbit/orbit',
        string $operationsReverbImage = self::DEFAULT_OPERATIONS_REVERB_IMAGE,
    ): string {
        $configRoot = $this->normalizeConfigRoot($configRoot);
        $installRoot = $this->normalizeInstallRoot($installRoot);
        $operationsReverbImage = $this->normalizeOperationsReverbImage($operationsReverbImage);
        $configRootExpression = '${ORBIT_CONFIG_ROOT:-'.$configRoot.'}';
        $installRootExpression = '${ORBIT_INSTALL_ROOT:-'.$installRoot.'}';

        return implode("\n", [
            'version: "3.8"',
            'services:',
            ...$this->gatewayService($image, $exposureMode, $configRoot, $configRootExpression, $installRootExpression),
            ...$this->schedulerService($image, $configRoot, $configRootExpression, $installRootExpression),
            ...$this->runtimeHibernatorService($image, $configRoot, $configRootExpression, $installRootExpression),
            ...$this->operationsReverbService($operationsReverbImage, $configRootExpression),
            'networks:',
            '  '.self::Network.':',
            '    external: true',
            '',
        ]);
    }

    /**
     * @return list<string>
     */
    private function gatewayService(
        GatewayImageReference $image,
        GatewayExposureMode $exposureMode,
        string $configRoot,
        string $configRootExpression,
        string $installRootExpression,
    ): array {
        $lines = [
            '  '.self::GatewayService.':',
            '    image: '.$this->quoted($image->canonical()),
            '    networks:',
            '      '.self::Network.':',
            '        aliases:',
            '          - '.self::GatewayService,
            '    environment:',
            '      APP_ENV: production',
            '      APP_DEBUG: "false"',
            '      DB_BUSY_TIMEOUT: "5000"',
            '      DB_JOURNAL_MODE: wal',
            '      DB_SYNCHRONOUS: NORMAL',
            '      ORBIT_CONFIG_ROOT: '.$configRoot,
            '      ORBIT_FORWARD_INSTALL_BINARY: /usr/local/bin/orbit-cli',
            '      ORBIT_GATEWAY_EXPOSURE_MODE: '.$exposureMode->value,
            '      ORBIT_GATEWAY_CONTAINER: "{{.Task.Name}}"',
            '      ORBIT_GATEWAY_HEALTH_PORT: "8080"',
            '      ORBIT_HOST_PATH_PREFIX: /mnt/orbit-host',
            '      ORBIT_LOCAL_EXECUTOR_BINARY: /usr/local/bin/orbit-cli',
        ];

        if ($exposureMode->isRouterColocated()) {
            $lines[] = '      ORBIT_TRUST_WIREGUARD_PROXY_HEADER: "1"';
        }

        if ($exposureMode->isGatewayDirect()) {
            array_push(
                $lines,
                '      ORBIT_GATEWAY_TLS_CERT: /etc/orbit/certs/gateway.crt',
                '      ORBIT_GATEWAY_TLS_KEY: /etc/orbit/certs/gateway.key',
                '    ports:',
                '      - target: 443',
                '        published: 443',
                '        protocol: tcp',
                '        mode: ingress',
            );
        }

        array_push(
            $lines,
            '    volumes:',
            '      - '.$configRootExpression.':'.$configRoot,
            '      - '.$installRootExpression.'/bin/orbit-binary:/usr/local/bin/orbit-cli:ro',
            '      - /etc/caddy:/mnt/orbit-host/etc/caddy',
            '      - /etc/orbit:/mnt/orbit-host/etc/orbit',
            '      - /home:/mnt/orbit-host/home:ro',
        );

        if ($exposureMode->isGatewayDirect()) {
            $lines[] = '      - '.$configRootExpression.'/certs:/etc/orbit/certs:ro';
        }

        array_push(
            $lines,
            '      - /var/run/docker.sock:/var/run/docker.sock',
            '      - /home/orbit/.ssh:/root/.ssh:ro',
            '    healthcheck:',
            '      test: ["CMD", "orbit-gateway-healthcheck"]',
            '      interval: 5s',
            '      timeout: 3s',
            '      retries: 12',
            '      start_period: 10s',
            '    deploy:',
            '      replicas: 1',
            '      labels:',
            '        orbit.managed: "true"',
            '        orbit.service: '.self::GatewayService,
            '      placement:',
            '        constraints:',
            '          - node.labels.orbit.role.gateway == true',
            '      update_config:',
            '        parallelism: 1',
            '        order: start-first',
            '        failure_action: rollback',
            '        monitor: 60s',
            '      rollback_config:',
            '        parallelism: 1',
            '        order: start-first',
            '        monitor: 60s',
        );

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function schedulerService(
        GatewayImageReference $image,
        string $configRoot,
        string $configRootExpression,
        string $installRootExpression,
    ): array {
        return $this->gatewayDaemonService(
            [
                'service' => self::SchedulerService,
                'command' => 'orbit-scheduler',
            ],
            $image,
            $configRoot,
            $configRootExpression,
            $installRootExpression,
        );
    }

    /**
     * @return list<string>
     */
    private function runtimeHibernatorService(
        GatewayImageReference $image,
        string $configRoot,
        string $configRootExpression,
        string $installRootExpression,
    ): array {
        return $this->gatewayDaemonService(
            [
                'service' => self::RUNTIME_HIBERNATOR_SERVICE,
                'command' => 'orbit-runtime-hibernator',
            ],
            $image,
            $configRoot,
            $configRootExpression,
            $installRootExpression,
        );
    }

    /**
     * @param  array{service: string, command: string}  $daemon
     * @return list<string>
     */
    private function gatewayDaemonService(
        array $daemon,
        GatewayImageReference $image,
        string $configRoot,
        string $configRootExpression,
        string $installRootExpression,
    ): array {
        return [
            '  '.$daemon['service'].':',
            '    image: '.$this->quoted($image->canonical()),
            '    command: ["php", "artisan", "'.$daemon['command'].'"]',
            '    networks: ['.self::Network.']',
            '    environment:',
            '      APP_ENV: production',
            '      APP_DEBUG: "false"',
            '      DB_BUSY_TIMEOUT: "5000"',
            '      DB_JOURNAL_MODE: wal',
            '      DB_SYNCHRONOUS: NORMAL',
            '      ORBIT_CONFIG_ROOT: '.$configRoot,
            '      ORBIT_FORWARD_INSTALL_BINARY: /usr/local/bin/orbit-cli',
            '      ORBIT_LOCAL_EXECUTOR_BINARY: /usr/local/bin/orbit-cli',
            '    volumes:',
            '      - '.$configRootExpression.':'.$configRoot,
            '      - '.$installRootExpression.'/bin/orbit-binary:/usr/local/bin/orbit-cli:ro',
            '      - /var/run/docker.sock:/var/run/docker.sock',
            '      - /home/orbit/.ssh:/root/.ssh:ro',
            '    healthcheck:',
            '      disable: true',
            '    deploy:',
            '      replicas: 1',
            '      labels:',
            '        orbit.managed: "true"',
            '        orbit.service: '.$daemon['service'],
            '      placement:',
            '        constraints:',
            '          - node.labels.orbit.role.gateway == true',
            '      update_config:',
            '        parallelism: 1',
            '        order: stop-first',
            '        failure_action: rollback',
        ];
    }

    /**
     * @return list<string>
     */
    private function operationsReverbService(string $image, string $configRootExpression): array
    {
        return [
            '  '.self::OPERATIONS_REVERB_SERVICE.':',
            '    image: '.$this->quoted($image),
            '    command: ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080", "--hostname='
                .self::OPERATIONS_REVERB_SERVICE
                .'"]',
            '    networks:',
            '      '.self::Network.':',
            '        aliases:',
            '          - '.self::OPERATIONS_REVERB_SERVICE,
            '    environment:',
            '      APP_ENV: production',
            '      APP_DEBUG: "false"',
            '      CACHE_STORE: array',
            '      ORBIT_WEBSOCKET_APPS_CONFIG: '.self::OPERATIONS_WEB_SOCKET_CONFIG_PATH,
            '      REVERB_HOST: '.self::OPERATIONS_REVERB_SERVICE,
            '      REVERB_PORT: "8080"',
            '      REVERB_SCALING_ENABLED: "false"',
            '      REVERB_SCHEME: http',
            '      REVERB_SERVER_HOST: 0.0.0.0',
            '      REVERB_SERVER_PORT: "8080"',
            '    volumes:',
            '      - '.$configRootExpression.'/operations-websocket:/etc/orbit/operations-websocket:ro',
            '    healthcheck:',
            '      test: ["CMD-SHELL", "php -r \'$$socket = @fsockopen(\"127.0.0.1\", 8080); exit(is_resource($$socket) ? 0 : 1);\'"]',
            '      interval: 5s',
            '      timeout: 3s',
            '      retries: 12',
            '      start_period: 10s',
            '    deploy:',
            '      replicas: 1',
            '      labels:',
            '        orbit.managed: "true"',
            '        orbit.service: '.self::OPERATIONS_REVERB_SERVICE,
            '      placement:',
            '        constraints:',
            '          - node.labels.orbit.role.gateway == true',
            '      update_config:',
            '        parallelism: 1',
            '        order: stop-first',
            '        failure_action: rollback',
        ];
    }

    private function normalizeConfigRoot(string $configRoot): string
    {
        $configRoot = trim($configRoot);

        if ($configRoot === '') {
            throw new InvalidArgumentException('Gateway Swarm stack config root cannot be empty.');
        }

        if ($configRoot === '/') {
            return $configRoot;
        }

        return rtrim($configRoot, '/');
    }

    private function normalizeInstallRoot(string $installRoot): string
    {
        $installRoot = trim($installRoot);

        if ($installRoot === '') {
            throw new InvalidArgumentException('Gateway Swarm stack install root cannot be empty.');
        }

        if ($installRoot === '/') {
            return $installRoot;
        }

        return rtrim($installRoot, '/');
    }

    private function normalizeOperationsReverbImage(string $image): string
    {
        $image = trim($image);

        if ($image === '') {
            throw new InvalidArgumentException('Operations Reverb image reference cannot be empty.');
        }

        if (preg_match('/\s/', $image) === 1) {
            throw new InvalidArgumentException('Operations Reverb image reference cannot contain whitespace.');
        }

        return $image;
    }

    private function quoted(string $value): string
    {
        return '"'.str_replace('"', '\"', $value).'"';
    }
}
