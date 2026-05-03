<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final class E2ERun
{
    /** @var list<E2EInstance> */
    private array $instances = [];

    private ?SshKeyPair $sshKeyPair = null;

    private bool $cleaned = false;

    /** @var array<string, string> */
    private array $metadata = [];

    public function __construct(
        private readonly E2EProvider $provider,
        public readonly string $id,
        public readonly string $label,
        public readonly string $workDirectory,
    ) {}

    public static function start(E2EProvider $provider, string $label): self
    {
        return $provider->startRun($label);
    }

    public function createSshKeyPair(): SshKeyPair
    {
        if ($this->sshKeyPair !== null) {
            return $this->sshKeyPair;
        }

        $this->sshKeyPair = $this->provider->createSshKeyPair($this);

        return $this->sshKeyPair;
    }

    public function sshKeyPair(): ?SshKeyPair
    {
        return $this->sshKeyPair;
    }

    public function launchBlank(string $suffix): E2EInstance
    {
        return $this->launch(E2EImage::Blank, $suffix);
    }

    public function launchControl(string $suffix): E2EInstance
    {
        return $this->launch(E2EImage::Control, $suffix);
    }

    public function launchGateway(string $suffix): E2EInstance
    {
        return $this->launch(E2EImage::Gateway, $suffix);
    }

    public function launch(E2EImage $image, string $suffix): E2EInstance
    {
        $instance = $this->provider->launch($this, $image, $suffix);

        $this->instances[] = $instance;

        return $instance;
    }

    public function cleanup(): void
    {
        if ($this->cleaned) {
            return;
        }

        $this->cleaned = true;

        $this->provider->cleanup($this, array_reverse($this->instances));
    }

    public function setMetadata(string $key, string $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function metadata(string $key): ?string
    {
        return $this->metadata[$key] ?? null;
    }

    public static function id(): string
    {
        return date('YmdHis').'-'.getmypid().'-'.bin2hex(random_bytes(3));
    }

    public static function safeLabel(string $label): string
    {
        $safe = strtolower((string) preg_replace('/[^a-zA-Z0-9-]+/', '-', $label));
        $safe = trim($safe, '-');

        return $safe !== '' ? $safe : 'run';
    }
}
