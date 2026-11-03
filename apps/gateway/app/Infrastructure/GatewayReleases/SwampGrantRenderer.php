<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Support\ValidatedData;
use RuntimeException;

/** Pure release material: JSON is YAML, and serve owns validation of CEL and grant patterns. */
final readonly class SwampGrantRenderer
{
    /** @return array<string, string> grant path relative to the Swamp repository => file content */
    public function render(string $repository): array
    {
        $manifest = $this->read($repository.'/config/gateway-workflow-grants.json');
        $principal = ValidatedData::string($manifest['principal']);
        $grants = [];
        foreach ($this->rows($manifest['vaults']) as $entry) {
            $grants[] = ['subject' => $principal, 'effect' => 'allow', 'actions' => ValidatedData::stringList($entry['actions']),
                'resource' => 'vault:'.ValidatedData::string($entry['vault']), 'condition' => 'key.matches('.json_encode(ValidatedData::string($entry['key_pattern']), JSON_THROW_ON_ERROR).')'];
        }
        foreach ($this->rows($manifest['workflows']) as $entry) {
            $grants[] = ['subject' => $principal, 'effect' => 'allow', 'actions' => ValidatedData::stringList($entry['actions']), 'resource' => 'workflow:'.ValidatedData::string($entry['workflow'])];
        }
        $serve = $this->read($repository.'/config/serve-grants.json');

        return [
            'grants/orbit-gateway.yaml' => json_encode(['grants' => $grants], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            'grants/orbit-serve.yaml' => json_encode(['grants' => $this->rows($serve['grants'])], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException('Release grants must be a list.');
        }

        return array_map(ValidatedData::object(...), $value);
    }

    /** @return array<string, mixed> */
    private function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Missing release grant manifest: {$path}");
        }
        $value = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException("Release grant manifest must be an object: {$path}");
        }

        return ValidatedData::object($value);
    }
}
