<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * The Gateway submits an Operation's execution secrets as one flat map of vault references and refuses any other shape
 * (operation.execution_secrets_invalid). So the top-level secret schema of every operation workflow names references only.
 */
it('declares the top-level execution secrets of every operation workflow as flat vault references', function (): void {
    $nested = [];
    foreach (glob(dirname(base_path(), 2).'/swamp/workflows/workflow-operation-*.yaml') ?: [] as $file) {
        // Only the header up to the jobs: the inputs are all this reads.
        $text = (string) file_get_contents($file);
        $workflow = Yaml::parse(substr($text, 0, strpos($text, "\njobs:") ?: strlen($text)));
        $secrets = $workflow['inputs']['properties']['execution_secrets']['properties'] ?? [];
        foreach ($secrets as $name => $schema) {
            $fields = array_keys($schema['properties'] ?? []);
            sort($fields);
            if (($schema['properties'] ?? null) !== null && $fields !== ['key', 'vault']) {
                $nested[] = basename($file).': '.$name;
            }
        }
    }

    expect($nested)->toBe([]);
});
