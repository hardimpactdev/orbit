<?php

declare(strict_types=1);

use App\Http\Mcp\ToolManifest;
use Orbit\Extensions\Conn\Domain\ConnProfileSections;
use Orbit\Extensions\Conn\Http\Requests\ConnProfileSettingsRules;
use Orbit\Extensions\Conn\Http\Requests\ReplaceConnProfileSettingsRequest;

/**
 * `bin/docs-openapi` writes the settings patch body by hand, because the rules depend on the sections a request holds.
 * This keeps the published body, and the MCP tool built from it, on the request rules. It reads the generated
 * `docs/openapi.json` and MCP manifest, so run `bin/build-api` first, as CI does.
 */
function contract_pattern(array $rules): string
{
    $regex = array_values(array_filter($rules, static fn (mixed $rule): bool => is_string($rule) && str_starts_with($rule, 'regex:')));
    expect($regex)->toHaveCount(1)->and($regex[0])->toStartWith('regex:/\A')->toEndWith('\z/');

    return '^'.substr($regex[0], strlen('regex:/\A'), -strlen('\z/')).'$(?![\s\S])';
}

/** @return array<string, array<string, mixed>> */
function contract_sections(): array
{
    $spec = json_decode((string) file_get_contents(base_path('../../docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    $tool = collect(ToolManifest::default()->definitions())->firstWhere('name', 'conn-profile-settings-patch');
    expect($tool)->not->toBeNull();

    return [
        'OpenAPI' => $spec['paths']['/api/v1/conn/profiles/{profile}/settings']['patch']['requestBody']['content']['application/json']['schema']['properties']['sections'],
        'MCP tool' => json_decode((string) json_encode($tool->inputSchema['properties']['sections']), true),
    ];
}

it('publishes every patch section with the attached-set rules of the request', function (): void {
    $rules = ConnProfileSettingsRules::attachedThreads('v');

    foreach (contract_sections() as $source => $sections) {
        expect(array_keys($sections['properties']))->toEqualCanonicalizing(ConnProfileSections::Named, $source)
            ->and(array_keys($sections['patternProperties']))->toBe(['^devices\.[a-z][a-z0-9-]{1,23}$'], $source);

        [$value, $null] = $sections['properties']['attachedThreads']['properties']['value']['oneOf'];
        $threads = $value['properties']['threads'];
        $entry = $threads['items'];

        expect($null)->toBe(['type' => 'null'], $source)
            ->and($value['additionalProperties'])->toBeFalse()
            ->and($value['required'])->toBe(['schema', 'threads'])
            ->and($value['properties']['schema'])->toBe(['type' => 'integer', 'minimum' => 1, 'maximum' => 1000])
            ->and($value['description'])->toContain('at most '.(ConnProfileSections::MaxAttachedThreadsBytes / 1024).' KB')
            ->and($threads['maxItems'])->toBe(ConnProfileSections::MaxAttachedThreads)
            ->and($entry['additionalProperties'])->toBeFalse()
            ->and($entry['required'])->toBe(['environmentId', 'threadId'])
            ->and($entry['properties']['environmentId']['pattern'])->toBe(contract_pattern($rules['v.threads.*.environmentId']), $source)
            ->and($entry['properties']['threadId']['pattern'])->toBe(contract_pattern($rules['v.threads.*.threadId']), $source);
    }
});

it('names the attached set in the settings show and patch descriptions', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../../docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    $path = $spec['paths']['/api/v1/conn/profiles/{profile}/settings'];

    expect($path['get']['description'])->toContain('`attachedThreads`')
        ->and($path['patch']['description'])->toContain('A section is `workspaces`, `appearance`, `attachedThreads`, or `devices.<type>`')
        ->toContain('A null value removes any section but `workspaces`');
});

it('publishes the replace body with exactly the sections a replace accepts', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../../docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    $put = $spec['paths']['/api/v1/conn/profiles/{profile}/settings']['put'];
    $settings = $put['requestBody']['content']['application/json']['schema']['properties']['settings'];
    $rule = array_values(array_filter((new ReplaceConnProfileSettingsRequest)->rules()['settings'], static fn (mixed $rule): bool => is_string($rule) && str_starts_with($rule, 'array:')));

    expect($rule)->toHaveCount(1)
        ->and(array_keys($settings['properties']))->toBe(explode(',', substr($rule[0], strlen('array:'))))
        ->and($settings['additionalProperties'])->toBeFalse()
        ->and($put['description'])->toContain('Any other section, such as `attachedThreads`, returns `validation.failed` (422)');
});
