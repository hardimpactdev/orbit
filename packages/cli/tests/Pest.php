<?php

use App\Services\DatabaseService;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Create a temporary test project directory with a basic Laravel structure.
 */
function createTestProject(string $slug): string
{
    $basePath = sys_get_temp_dir().'/orbit-tests/'.$slug;

    // Clean up if exists
    if (is_dir($basePath)) {
        deleteDirectory($basePath);
    }

    // Create directory structure
    mkdir($basePath, 0755, true);
    mkdir("{$basePath}/database", 0755, true);

    // Create basic .env.example
    file_put_contents("{$basePath}/.env.example", <<<'ENV'
APP_NAME=Laravel
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=sqlite

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
ENV
    );

    // Create artisan file (empty, just for detection)
    file_put_contents("{$basePath}/artisan", "<?php\n// Laravel artisan stub\n");
    chmod("{$basePath}/artisan", 0755);

    // Create composer.json
    file_put_contents("{$basePath}/composer.json", json_encode([
        'name' => "test/{$slug}",
        'require' => [
            'php' => '^8.3',
        ],
        'scripts' => [
            'post-autoload-dump' => [],
        ],
    ], JSON_PRETTY_PRINT));

    // Create bootstrap/app.php for trusted proxies test
    mkdir("{$basePath}/bootstrap", 0755, true);
    file_put_contents("{$basePath}/bootstrap/app.php", <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure()
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->create();
PHP
    );

    return $basePath;
}

/**
 * Delete a directory recursively.
 */
function deleteDirectory(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = "{$dir}/{$file}";
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    rmdir($dir);
}

/**
 * Get a fresh test DatabaseService instance.
 */
function testDatabase(): DatabaseService
{
    $dbPath = __DIR__.'/database/test.sqlite';

    // Ensure clean database for each test
    if (file_exists($dbPath)) {
        unlink($dbPath);
    }

    return new DatabaseService($dbPath);
}

/**
 * Clean up test projects after tests.
 */
function cleanupTestProjects(): void
{
    $testDir = sys_get_temp_dir().'/orbit-tests';
    if (is_dir($testDir)) {
        deleteDirectory($testDir);
    }
}
