<?php

declare(strict_types=1);

namespace App\Data\Processes;

/** @mago-expect lint:excessive-parameter-list */
final readonly class ProcessRuntimeUnitSpec
{
    /**
     * @param  list<string>  $environmentLines
     */
    public function __construct(
        public string $name,
        public string $configPath,
        public string $configHash,
        public string $configHashLabel,
        public string $restartPolicy,
        public array $environmentLines,
        public ?string $label = null,
    ) {}

    /**
     * @return array{name: string, config_path: string, config_hash: string, config_hash_label: string, restart_policy: string, environment_lines: list<string>, label?: string}
     */
    public function toArray(): array
    {
        $specification = [
            'name' => $this->name,
            'config_path' => $this->configPath,
            'config_hash' => $this->configHash,
            'config_hash_label' => $this->configHashLabel,
            'restart_policy' => $this->restartPolicy,
            'environment_lines' => $this->environmentLines,
        ];

        if ($this->label !== null) {
            $specification['label'] = $this->label;
        }

        return $specification;
    }
}
