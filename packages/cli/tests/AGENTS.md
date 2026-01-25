# Tests Directory

Pest PHP tests for the Orbit CLI.

## Structure

```
tests/
├── Feature/           # Integration/feature tests
│   └── Mcp/          # MCP protocol tests
├── Unit/             # Unit tests
├── Pest.php          # Pest configuration
└── TestCase.php      # Base test class
```

## Testing Framework

Uses **Pest PHP** with Laravel Zero integrations:

```php
<?php

use App\Services\SomeService;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    // Setup test fixtures
    $this->tempDir = sys_get_temp_dir().'/orbit-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    // Cleanup
    \Illuminate\Support\Facades\File::deleteDirectory($this->tempDir);
});

describe('feature group', function () {
    it('does something specific', function () {
        Process::fake(['*' => Process::result(output: 'Success')]);

        $this->artisan('my:command', ['--json' => true])
            ->assertExitCode(0);
    });
});
```

## Test Patterns

### Command Testing

```php
it('runs command with expected output', function () {
    $this->artisan('status')
        ->assertExitCode(0)
        ->expectsOutput('Services running');
});

it('handles errors gracefully', function () {
    $this->artisan('site:create', ['name' => 'orbit'])
        ->assertExitCode(1);  // Reserved name
});
```

### Process Faking

```php
Process::fake([
    'gh repo clone *' => Process::result(output: 'Cloned'),
    'composer install' => Process::result(exitCode: 0),
    '*' => Process::result(exitCode: 1),  // Default fallback
]);
```

### HTTP Faking

```php
Http::fake([
    'packagist.org/packages/*' => Http::response(['package' => [...]]),
    'localhost:8000/mcp' => Http::response([
        'jsonrpc' => '2.0',
        'result' => ['content' => [['text' => 'Success']]],
    ]),
]);
```

### Service Mocking

```php
$configManager = Mockery::mock(ConfigManager::class)->makePartial();
$configManager->shouldReceive('getTld')->andReturn('test');
$this->app->instance(ConfigManager::class, $configManager);
```

## Quality Gate

**Every fix must have a test.** Run before commit:

```bash
./vendor/bin/pest
```

## Test Coverage Focus

Prioritize tests for:

1. **Command option definitions** - Ensure all expected options exist
2. **Error handling paths** - Reserved names, missing files, etc.
3. **Platform adapters** - Both Linux and macOS paths
4. **JSON output** - Clean JSON without console pollution
5. **Provision actions** - Each action's success/failure paths
