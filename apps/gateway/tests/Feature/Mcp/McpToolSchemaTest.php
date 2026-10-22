<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Shared\LifecycleStatus;
use App\Http\Mcp\ApiDispatcher;
use App\Http\Mcp\ToolManifest;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\TaskModelRouter;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'mcp-schema-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.71',
        'wireguard_ip' => '10.44.0.71',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    TaskModelRouter::ready();
    $this->postJson('/api/v1/extensions/tasks/enable')->assertOk();
    $this->postJson('/api/v1/extensions/documents/enable')->assertOk();
    $this->postJson('/api/v1/extensions/vault/enable')->assertOk();
    $this->postJson('/api/v1/extensions/conn/enable')->assertOk();
});

it('reads each extension switch once per catalogue and observes changes on the next catalogue', function (): void {
    $manifest = ToolManifest::default();
    $definitions = $manifest->definitions();
    $extensions = array_unique(array_filter(array_column($definitions, 'extension')));
    DB::enableQueryLog();

    $catalogue = static function () use ($manifest, $extensions): array {
        DB::flushQueryLog();
        $tools = $manifest->tools(app(ApiDispatcher::class));
        $switchReads = array_filter(DB::getQueryLog(), static fn (array $query): bool => array_any(
            $query['bindings'],
            static fn (mixed $value): bool => is_string($value) && str_starts_with($value, 'extension.'),
        ));
        expect($switchReads)->toHaveCount(count($extensions));

        return $tools;
    };

    try {
        $tools = $catalogue();
        app(ExtensionStore::class)->set('documents', false);
        $withoutDocuments = $catalogue();
        $documentCount = count(array_filter($definitions, static fn ($tool): bool => $tool->extension === 'documents'));
        expect($documentCount)->toBeGreaterThan(1)
            ->and($withoutDocuments)->toHaveCount(count($tools) - $documentCount);
        app(ExtensionStore::class)->set('documents', true);
        expect($catalogue())->toHaveCount(count($tools));
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

describe('MCP tool schemas', function (): void {
    it('serves every generated input schema unchanged through tools/list', function (): void {
        $generated = json_decode((string) file_get_contents(resource_path('mcp/tools.json')), associative: false, flags: JSON_THROW_ON_ERROR);
        $tools = [];
        $cursor = null;

        do {
            $response = $this->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => $cursor === null ? (object) [] : ['cursor' => $cursor],
            ]);
            $response->assertOk();
            $document = mcp_schema_document($response);
            $tools = [...$tools, ...$document->result->tools];
            $cursor = $document->result->nextCursor ?? null;
        } while (is_string($cursor));

        $served = collect($tools)->keyBy('name');
        expect($served->keys()->all())->toEqualCanonicalizing(array_column($generated->tools, 'name'));

        foreach ($generated->tools as $tool) {
            expect(json_encode($served->get($tool->name)->inputSchema, JSON_THROW_ON_ERROR))
                ->toBe(json_encode($tool->input_schema, JSON_THROW_ON_ERROR), $tool->name);
        }
    });

    it('serves every generated input schema unchanged through search_tools', function (stdClass $tool): void {
        $response = $this->postJson('/mcp/search', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'search_tools',
                'arguments' => ['query' => $tool->name, 'limit' => 1],
            ],
        ]);
        $response->assertOk();
        $document = mcp_schema_document($response);
        expect($document->result->isError)->toBeFalse();
        $found = json_decode($document->result->content[0]->text, associative: false, flags: JSON_THROW_ON_ERROR);

        expect($found->tools)->toHaveCount(1);
        expect($found->tools[0]->name)->toBe($tool->name);
        expect(json_encode($found->tools[0]->inputSchema, JSON_THROW_ON_ERROR))
            ->toBe(json_encode($tool->input_schema, JSON_THROW_ON_ERROR), $tool->name);
    })->with(function (): iterable {
        $generated = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/resources/mcp/tools.json'), associative: false, flags: JSON_THROW_ON_ERROR);
        foreach ($generated->tools as $tool) {
            yield $tool->name => [$tool];
        }
    });
});

function mcp_schema_document(TestResponse $response): stdClass
{
    $content = $response->getContent();

    if ($response->baseResponse instanceof StreamedResponse) {
        preg_match_all('/^data: (.+)$/m', $response->streamedContent(), $matches);
        $content = end($matches[1]);
    }

    $document = json_decode($content, associative: false, flags: JSON_THROW_ON_ERROR);
    expect($document)->toBeInstanceOf(stdClass::class);

    return $document;
}
