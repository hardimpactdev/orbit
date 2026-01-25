<?php

use App\Actions\Provision\ConfigureTrustedProxies;
use App\Data\Provision\ProvisionContext;
use App\Services\ProvisionLogger;

beforeEach(function () {
    $this->projectPath = createTestProject('test-proxies');
    $this->context = new ProvisionContext(
        slug: 'test-proxies',
        projectPath: $this->projectPath,
    );
    $this->logger = new ProvisionLogger(slug: 'test-proxies');
});

afterEach(function () {
    deleteDirectory($this->projectPath);
});

it('skips when no bootstrap/app.php exists', function () {
    unlink("{$this->projectPath}/bootstrap/app.php");
    rmdir("{$this->projectPath}/bootstrap");

    $action = new ConfigureTrustedProxies;
    $result = $action->handle($this->context, $this->logger);

    expect($result->isSuccess())->toBeTrue();
});

it('skips when not Laravel 11+ (no Application::configure)', function () {
    file_put_contents("{$this->projectPath}/bootstrap/app.php", <<<'PHP'
<?php
// Old Laravel bootstrap file
$app = new Illuminate\Foundation\Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);
return $app;
PHP
    );

    $action = new ConfigureTrustedProxies;
    $result = $action->handle($this->context, $this->logger);

    expect($result->isSuccess())->toBeTrue();

    $content = file_get_contents("{$this->projectPath}/bootstrap/app.php");
    expect($content)->not->toContain('trustProxies');
});

it('skips when trusted proxies already configured', function () {
    file_put_contents("{$this->projectPath}/bootstrap/app.php", <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: "*");
    })
    ->create();
PHP
    );

    $action = new ConfigureTrustedProxies;
    $result = $action->handle($this->context, $this->logger);

    expect($result->isSuccess())->toBeTrue();
});

it('adds trusted proxies to empty middleware callback', function () {
    file_put_contents("{$this->projectPath}/bootstrap/app.php", <<<'PHP'
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

    $action = new ConfigureTrustedProxies;
    $result = $action->handle($this->context, $this->logger);

    expect($result->isSuccess())->toBeTrue();

    $content = file_get_contents("{$this->projectPath}/bootstrap/app.php");
    expect($content)->toContain('trustProxies');
    expect($content)->toContain('use Illuminate\Http\Request');
    expect($content)->toContain('Request::HEADER_X_FORWARDED_FOR');
});

it('adds Request import when not present', function () {
    $content = file_get_contents("{$this->projectPath}/bootstrap/app.php");
    expect($content)->not->toContain('use Illuminate\Http\Request');

    $action = new ConfigureTrustedProxies;
    $result = $action->handle($this->context, $this->logger);

    $content = file_get_contents("{$this->projectPath}/bootstrap/app.php");
    expect($content)->toContain('use Illuminate\Http\Request');
});
