<?php

declare(strict_types=1);

namespace App\Services\E2E;

use App\E2E\Support\DockerTopologyProvider;
use App\E2E\Support\IncusHost;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Vpn\WgEasyServiceInstaller;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Build the reusable Incus base image (`orbit-base-ubuntu-26.04-runtime`) used by the
 * E2E topology lane. The base image holds OS deps, the bootstrap user, the
 * `orbit` user, and the runtime directory tree — but no Orbit source. Source
 * is pushed per topology preparation; see bin/e2e-provision-node.
 *
 * Creates the `orbit` user and pre-installs the apt package list defined by
 * bin/_e2e-deps.sh, so the per-run `bin/install-orbit` invocation inside a
 * base-cloned VM is fast.
 */
class IncusBaseImagePreparer
{
    public function __construct(private readonly IncusHost $host) {}

    /**
     * @return array{role: string, alias: string, action: string}
     */
    public function build(IncusBaseImagePreparationOptions $options): array
    {
        if (! $options->force) {
            return [
                'role' => 'base',
                'alias' => $options->baseImageAlias,
                'action' => 'planned',
            ];
        }

        $runId = $this->newRunId();
        $instanceName = "orbit-e2e-{$runId}-prepare-base";
        $remoteWorkDir = $this->createRemoteWorkDir($instanceName);
        $tempInstance = null;

        try {
            $this->ensureSourceImageExists($options->sourceImage);

            $remotePrivateKey = "{$remoteWorkDir}/id_ed25519";
            $publicKey = $this->createRemoteSshKey($remotePrivateKey, $runId);

            $packages = $this->readPackageList($options->depsScriptPath, '--all');
            $frankenPhpImageArchive = "{$remoteWorkDir}/frankenphp-1-php8.5-bookworm.tar";

            $this->stageRemoteDockerImageArchive(
                (new PhpRuntimeCatalog)->imageFor(PhpRuntimeCatalog::DEFAULT),
                $frankenPhpImageArchive,
            );

            $this->launchBaseInstance($instanceName, $options);
            $tempInstance = $instanceName;

            $this->waitForAgent($instanceName, $options->timeoutSeconds);
            $this->pushRemoteFileToInstance($frankenPhpImageArchive, $instanceName, '/var/tmp/frankenphp-1-php8.5-bookworm.tar');
            $this->bootstrapBaseInstance($instanceName, $options, $publicKey, $packages);
            $this->waitForAgent($instanceName, $options->timeoutSeconds);
            $ipv4 = $this->waitForIpv4($instanceName, $options->timeoutSeconds);
            $this->waitForSsh($ipv4, $remotePrivateKey, $options->bootstrapUser, $options->timeoutSeconds);

            $this->cleanBaseImageState($instanceName, $options->bootstrapUser);
            $this->stopInstance($instanceName);
            $this->publishImage($instanceName, $options->baseImageAlias);

            return [
                'role' => 'base',
                'alias' => $options->baseImageAlias,
                'action' => 'built',
            ];
        } finally {
            $this->cleanupRemote($tempInstance, $remoteWorkDir);
        }
    }

    private function newRunId(): string
    {
        return date('YmdHis').'-'.getmypid().'-'.bin2hex(random_bytes(3));
    }

    private function createRemoteWorkDir(string $instanceName): string
    {
        $template = '/tmp/'.$instanceName.'-XXXXXX';

        $result = $this->host->run('mktemp -d '.escapeshellarg($template), timeoutSeconds: 30);

        if (! $result->successful()) {
            throw new RuntimeException('Could not create remote work directory.');
        }

        $path = trim($result->output());

        if ($path === '') {
            throw new RuntimeException('mktemp returned an empty path.');
        }

        return $path;
    }

    private function ensureSourceImageExists(string $sourceImage): void
    {
        $result = $this->host->run(
            'incus image info '.escapeshellarg($sourceImage).' >/dev/null 2>&1',
            timeoutSeconds: 60,
        );

        if (! $result->successful()) {
            throw new RuntimeException("Source image [{$sourceImage}] is not available and could not be fetched.");
        }
    }

    private function createRemoteSshKey(string $privateKeyPath, string $runId): string
    {
        $generate = $this->host->run(sprintf(
            'ssh-keygen -t ed25519 -N %s -f %s -C %s >/dev/null',
            escapeshellarg(''),
            escapeshellarg($privateKeyPath),
            escapeshellarg("orbit-e2e-base-{$runId}"),
        ), timeoutSeconds: 60);

        if (! $generate->successful()) {
            throw new RuntimeException('Failed to generate temporary SSH key on Incus host.');
        }

        $read = $this->host->run('cat '.escapeshellarg($privateKeyPath.'.pub'), timeoutSeconds: 10);

        if (! $read->successful()) {
            throw new RuntimeException('Failed to read generated public key on Incus host.');
        }

        $publicKey = trim($read->output());

        if ($publicKey === '') {
            throw new RuntimeException('Generated public key is empty.');
        }

        return $publicKey;
    }

    private function stageRemoteDockerImageArchive(string $image, string $archive): void
    {
        $quotedImage = escapeshellarg($image);
        $quotedArchive = escapeshellarg($archive);

        $result = $this->host->run(sprintf(
            <<<'BASH'
if ! docker image inspect %1$s >/dev/null 2>&1; then
    docker pull %1$s
fi
docker image inspect %1$s >/dev/null
docker save %1$s -o %2$s
chmod 0644 %2$s
BASH,
            $quotedImage,
            $quotedArchive,
        ), timeoutSeconds: 900);

        if (! $result->successful()) {
            throw new RuntimeException("Could not stage {$image} archive on Incus host: {$result->errorOutput()}");
        }
    }

    private function pushRemoteFileToInstance(string $remotePath, string $instanceName, string $guestPath): void
    {
        $result = $this->host->run(sprintf(
            'incus file push %s %s',
            escapeshellarg($remotePath),
            escapeshellarg("{$instanceName}{$guestPath}"),
        ), timeoutSeconds: 300);

        if (! $result->successful()) {
            throw new RuntimeException("Could not push {$remotePath} into [{$instanceName}]: {$result->errorOutput()}");
        }
    }

    /**
     * @return list<string>
     */
    private function readPackageList(string $depsScriptPath, string $selector): array
    {
        if (! is_file($depsScriptPath) || ! is_executable($depsScriptPath)) {
            throw new RuntimeException("Deps helper not executable: {$depsScriptPath}");
        }

        $result = Process::timeout(30)->run([$depsScriptPath, $selector]);

        if (! $result->successful()) {
            throw new RuntimeException("Failed to read package list from {$depsScriptPath} {$selector}: {$result->errorOutput()}");
        }

        $packages = array_values(array_filter(
            array_map(trim(...), explode("\n", $result->output())),
            fn (string $package): bool => $package !== '',
        ));

        if ($packages === []) {
            throw new RuntimeException("Deps helper {$depsScriptPath} {$selector} returned an empty package list.");
        }

        return $packages;
    }

    private function launchBaseInstance(
        string $name,
        IncusBaseImagePreparationOptions $options,
    ): void {
        $launch = $this->host->run(sprintf(
            'incus launch %s %s --vm --config=limits.cpu=%s --config=limits.memory=%s >/dev/null',
            escapeshellarg($options->sourceImage),
            escapeshellarg($name),
            escapeshellarg((string) $options->cpus),
            escapeshellarg($options->memory),
        ), timeoutSeconds: $options->timeoutSeconds);

        if (! $launch->successful()) {
            throw new RuntimeException("Failed to launch base instance [{$name}]: {$launch->errorOutput()}");
        }
    }

    /**
     * @param  list<string>  $packages
     */
    private function bootstrapBaseInstance(
        string $instanceName,
        IncusBaseImagePreparationOptions $options,
        string $publicKey,
        array $packages,
    ): void {
        $packageArguments = implode(' ', array_map(escapeshellarg(...), $packages));
        $bootstrapUser = escapeshellarg($options->bootstrapUser);
        $publicKeyValue = escapeshellarg($publicKey);
        $caddyImage = escapeshellarg(OrbitCaddyContainer::Image);
        $frankenPhpDockerfileImage = (new PhpRuntimeCatalog)->imageFor(PhpRuntimeCatalog::DEFAULT);
        $frankenPhpImage = escapeshellarg($frankenPhpDockerfileImage);
        $sourceGatewayArtisanImage = escapeshellarg(DockerTopologyProvider::sourceGatewayArtisanImage());
        $webSocketRuntimeImage = escapeshellarg(DockerTopologyProvider::webSocketRuntimeImage());
        $wgEasyImage = escapeshellarg(WgEasyServiceInstaller::Image);

        $script = <<<BASH
set -euo pipefail

bootstrap_user={$bootstrapUser}
public_key={$publicKeyValue}
export DEBIAN_FRONTEND=noninteractive

rm -f /etc/resolv.conf
printf 'nameserver 1.1.1.1\nnameserver 8.8.8.8\n' > /etc/resolv.conf
printf '%s\n' 'Acquire::ForceIPv4 "true";' 'Acquire::http::Timeout "10";' 'Acquire::https::Timeout "10";' 'Acquire::Retries "3";' > /etc/apt/apt.conf.d/99orbit-e2e-network

for _ in \$(seq 1 60); do
    getent hosts archive.ubuntu.com >/dev/null 2>&1 && break
    sleep 2
done

getent hosts archive.ubuntu.com >/dev/null
apt-get update -qq
apt-get install -y -qq {$packageArguments} docker.io
systemctl enable --now docker

id -u "\$bootstrap_user" >/dev/null 2>&1 || useradd -m -s /bin/bash "\$bootstrap_user"
id -u orbit >/dev/null 2>&1 || useradd -m -s /bin/bash orbit
usermod -aG sudo "\$bootstrap_user"
usermod -aG sudo orbit
usermod -aG docker "\$bootstrap_user"
usermod -aG docker orbit
usermod -p '*' "\$bootstrap_user"
usermod -p '*' orbit

printf '%s ALL=(ALL) NOPASSWD:ALL\n' "\$bootstrap_user" > /etc/sudoers.d/orbit-e2e-bootstrap
printf 'orbit ALL=(ALL) NOPASSWD:ALL\n' > /etc/sudoers.d/orbit-e2e-orbit
chmod 0440 /etc/sudoers.d/orbit-e2e-bootstrap /etc/sudoers.d/orbit-e2e-orbit

install -d -m 700 -o "\$bootstrap_user" -g "\$bootstrap_user" "/home/\$bootstrap_user/.ssh"
printf '%s\n' "\$public_key" > "/home/\$bootstrap_user/.ssh/authorized_keys"
chown "\$bootstrap_user:\$bootstrap_user" "/home/\$bootstrap_user/.ssh/authorized_keys"
chmod 600 "/home/\$bootstrap_user/.ssh/authorized_keys"

install -d -m 700 -o orbit -g orbit /home/orbit/.ssh
install -d -m 755 -o orbit -g orbit /home/orbit/.config /home/orbit/.config/composer /home/orbit/.config/orbit
update-alternatives --set php /usr/bin/php8.5 || true
systemctl enable --now ssh || systemctl enable --now sshd || true

case "\$(uname -m)" in
    x86_64|amd64) static_php_arch=x86_64 ;;
    aarch64|arm64) static_php_arch=aarch64 ;;
    *) echo "unsupported static PHP architecture: \$(uname -m)" >&2; exit 1 ;;
esac

for php_version in 8.5:8.5.6 8.4:8.4.21 8.3:8.3.31; do
    php_minor="\${php_version%%:*}"
    php_patch="\${php_version#*:}"
    install -d -m 0755 "/opt/orbit/php/\$php_minor/bin"
    curl -fsSL "https://dl.static-php.dev/static-php-cli/bulk/php-\$php_patch-cli-linux-\$static_php_arch.tar.gz" -o "/tmp/orbit-php-\$php_minor.tar.gz"
    tar -xzf "/tmp/orbit-php-\$php_minor.tar.gz" -C "/opt/orbit/php/\$php_minor/bin"
    chmod +x "/opt/orbit/php/\$php_minor/bin/php"
    ln -sf "/opt/orbit/php/\$php_minor/bin/php" "/usr/local/bin/php\$php_minor"
    rm -f "/tmp/orbit-php-\$php_minor.tar.gz"
done
ln -sf /opt/orbit/php/8.5/bin/php /usr/local/bin/php

expected_composer_signature="\$(curl -fsSL https://composer.github.io/installer.sig)"
curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
actual_composer_signature="\$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
if [ "\$expected_composer_signature" != "\$actual_composer_signature" ]; then
    echo "Composer installer signature verification failed." >&2
    exit 1
fi
php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm -f /tmp/composer-setup.php

install -d -m 0755 /etc/apt/keyrings
curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg -o /etc/apt/keyrings/githubcli-archive-keyring.gpg
chmod go+r /etc/apt/keyrings/githubcli-archive-keyring.gpg
printf 'deb [arch=%s signed-by=/etc/apt/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main\n' "\$(dpkg --print-architecture)" > /etc/apt/sources.list.d/github-cli.list
apt-get update -qq
apt-get install -y -qq gh

runuser -u orbit -- env COMPOSER_HOME=/home/orbit/.config/composer composer global require laravel/installer --no-interaction --no-progress
ln -sf /home/orbit/.config/composer/vendor/bin/laravel /usr/local/bin/laravel

cat > /etc/systemd/system/orbit-e2e-docker-swarm-init.service <<'UNIT'
[Unit]
Description=Initialize Docker Swarm for Orbit E2E
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
ExecStart=/bin/sh -lc 'docker info --format "{{.Swarm.LocalNodeState}}" | grep -qx active || docker swarm init --advertise-addr 127.0.0.1 >/dev/null 2>&1 || true'

[Install]
WantedBy=multi-user.target
UNIT
systemctl enable orbit-e2e-docker-swarm-init.service
docker info --format "{{.Swarm.LocalNodeState}}" | grep -qx active || docker swarm init --advertise-addr 127.0.0.1 >/dev/null 2>&1 || true

docker --version >/dev/null
for image in {$caddyImage} {$wgEasyImage}; do
    docker pull "\$image"
    docker image inspect "\$image" >/dev/null
done
docker load -i /var/tmp/frankenphp-1-php8.5-bookworm.tar
docker image inspect {$frankenPhpImage} >/dev/null
rm -f /var/tmp/frankenphp-1-php8.5-bookworm.tar

cat > /tmp/orbit-e2e-source-gateway-artisan.Dockerfile <<'DOCKERFILE'
FROM {$frankenPhpDockerfileImage}
RUN apt-get update \
    && apt-get install -y --no-install-recommends openssh-client \
    && rm -rf /var/lib/apt/lists/*
DOCKERFILE
docker build --pull=false -t {$sourceGatewayArtisanImage} -f /tmp/orbit-e2e-source-gateway-artisan.Dockerfile /tmp
docker image inspect {$sourceGatewayArtisanImage} >/dev/null

cat > /tmp/orbit-e2e-websocket-runtime.Dockerfile <<'DOCKERFILE'
FROM ubuntu:26.04
ENV DEBIAN_FRONTEND=noninteractive
RUN printf '%s\\n' 'Acquire::ForceIPv4 "true";' 'Acquire::http::Timeout "10";' 'Acquire::https::Timeout "10";' 'Acquire::Retries "3";' > /etc/apt/apt.conf.d/99orbit-e2e-network \
    && apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends \
        ca-certificates \
        php8.5-bcmath \
        php8.5-cli \
        php8.5-common \
        php8.5-curl \
        php8.5-intl \
        php8.5-mbstring \
        php8.5-redis \
        php8.5-sqlite3 \
        php8.5-xml \
        php8.5-zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
CMD ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]
DOCKERFILE
docker build --pull=false -t {$webSocketRuntimeImage} -f /tmp/orbit-e2e-websocket-runtime.Dockerfile /tmp
docker image inspect {$webSocketRuntimeImage} >/dev/null

/opt/orbit/php/8.5/bin/php -r "echo PHP_VERSION;" >/dev/null
/usr/local/bin/composer --version >/dev/null
gh --version >/dev/null
cd /home/orbit && /usr/local/bin/laravel --version >/dev/null
apt-get clean
rm -rf /var/lib/apt/lists/*
BASH;

        $result = $this->host->run(sprintf(
            'incus exec %s -- bash -lc %s',
            escapeshellarg($instanceName),
            escapeshellarg($script),
        ), timeoutSeconds: $options->timeoutSeconds);

        if (! $result->successful()) {
            throw new RuntimeException("Failed to bootstrap base instance [{$instanceName}]: {$result->errorOutput()}");
        }
    }

    private function waitForAgent(string $instanceName, int $timeoutSeconds): void
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $result = $this->host->run(
                sprintf('incus exec %s -- true', escapeshellarg($instanceName)),
                timeoutSeconds: 10,
            );

            if ($result->successful()) {
                return;
            }

            sleep(2);
        }

        throw new RuntimeException("Incus agent never became ready on [{$instanceName}].");
    }

    private function waitForIpv4(string $instanceName, int $timeoutSeconds): string
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $result = $this->host->run(
                sprintf(
                    'incus exec %s -- sh -lc %s',
                    escapeshellarg($instanceName),
                    escapeshellarg('ip -o -4 addr show scope global | awk \'$2 !~ /^(lo|docker0|docker_gwbridge|wg-orbit|wg0|br-|veth)/ && found != 1 { split($4, parts, "/"); print parts[1]; found = 1 }\''),
                ),
                timeoutSeconds: 10,
            );

            $ip = trim($result->output());

            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $ip;
            }

            sleep(2);
        }

        throw new RuntimeException("Instance [{$instanceName}] did not receive an IPv4 address within {$timeoutSeconds}s.");
    }

    private function waitForSsh(string $ip, string $remotePrivateKey, string $bootstrapUser, int $timeoutSeconds): void
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $result = $this->host->run(sprintf(
                'ssh -i %s -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s %s',
                escapeshellarg($remotePrivateKey),
                escapeshellarg("{$bootstrapUser}@{$ip}"),
                escapeshellarg('test "$(uname -s)" = Linux && test -r /etc/os-release'),
            ), timeoutSeconds: 10);

            if ($result->successful()) {
                return;
            }

            sleep(3);
        }

        throw new RuntimeException("SSH never became ready on {$ip} as {$bootstrapUser}.");
    }

    private function cleanBaseImageState(string $instanceName, string $bootstrapUser): void
    {
        $script = sprintf(
            'rm -f /home/%1$s/.ssh/authorized_keys && '
            .'touch /home/%1$s/.ssh/authorized_keys && '
            .'chown %1$s:%1$s /home/%1$s/.ssh/authorized_keys && '
            .'chmod 600 /home/%1$s/.ssh/authorized_keys && '
            .'install -d -m 700 -o orbit -g orbit /home/orbit/.ssh && '
            .'rm -f /home/orbit/.ssh/authorized_keys && '
            .'touch /home/orbit/.ssh/authorized_keys && '
            .'chown orbit:orbit /home/orbit/.ssh/authorized_keys && '
            .'chmod 600 /home/orbit/.ssh/authorized_keys && '
            .'grep -q "^Subsystem sftp" /etc/ssh/sshd_config || echo "Subsystem sftp /usr/lib/openssh/sftp-server" >> /etc/ssh/sshd_config && '
            .'systemctl restart sshd || systemctl restart ssh || true && '
            .'rm -f /etc/machine-id && '
            .'touch /etc/machine-id',
            $bootstrapUser,
        );

        $result = $this->host->run(sprintf(
            'incus exec %s -- sh -lc %s',
            escapeshellarg($instanceName),
            escapeshellarg($script),
        ), timeoutSeconds: 60);

        if (! $result->successful()) {
            throw new RuntimeException("Failed to clean state on [{$instanceName}]: {$result->errorOutput()}");
        }
    }

    private function stopInstance(string $instanceName): void
    {
        $result = $this->host->run(
            sprintf('incus stop %s --timeout 120', escapeshellarg($instanceName)),
            timeoutSeconds: 180,
        );

        if (! $result->successful()) {
            throw new RuntimeException("Failed to stop instance [{$instanceName}].");
        }
    }

    private function publishImage(string $instanceName, string $alias): void
    {
        $result = $this->host->run(sprintf(
            'incus publish %s --force --reuse --alias %s >/dev/null',
            escapeshellarg($instanceName),
            escapeshellarg($alias),
        ), timeoutSeconds: 600);

        if (! $result->successful()) {
            throw new RuntimeException("Failed to publish image [{$alias}] from [{$instanceName}].");
        }
    }

    private function cleanupRemote(?string $tempInstance, string $remoteWorkDir): void
    {
        if ($tempInstance !== null) {
            $this->host->run(
                'incus delete --force '.escapeshellarg($tempInstance).' >/dev/null 2>&1 || true',
                timeoutSeconds: 120,
            );
        }

        $this->host->run(
            'rm -rf '.escapeshellarg($remoteWorkDir).' || true',
            timeoutSeconds: 30,
        );
    }
}
