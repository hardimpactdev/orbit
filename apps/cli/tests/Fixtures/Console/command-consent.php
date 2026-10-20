<?php

declare(strict_types=1);

use App\Commands\Firewall\RemoveFirewallRuleCommand;
use App\Commands\Instances\DestroyDeployStepCommand;
use App\Commands\Instances\DestroyInstanceCommand;
use App\Commands\Instances\RegisterInstanceCommand;
use App\Commands\Instances\TransferInstanceCommand;
use App\Commands\Nodes\RemoveNodeAccessCommand;
use App\Commands\Nodes\RemoveNodeCommand;
use App\Commands\Nodes\RemoveNodeRoleCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Git\GitRegistrationDiscovery;
use App\Services\Git\GitRegistrationFacts;
use Illuminate\Contracts\Console\Kernel;
use LaravelZero\Framework\Bootstrap\LoadConfiguration;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__.'/../../../vendor/autoload.php';
$configuration = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$app = require __DIR__.'/../../../bootstrap/app.php';
if ($configuration['isolate_command'] ?? false) {
    $command = match ($configuration['arguments'][0]) {
        'instance:destroy' => DestroyInstanceCommand::class,
        'instance:deploy-step:destroy' => DestroyDeployStepCommand::class,
        'instance:register' => RegisterInstanceCommand::class,
        'instance:transfer' => TransferInstanceCommand::class,
        'node:remove' => RemoveNodeCommand::class,
        'node:access:remove' => RemoveNodeAccessCommand::class,
        'node:role:remove' => RemoveNodeRoleCommand::class,
        'firewall:remove' => RemoveFirewallRuleCommand::class,
    };
    $app->afterBootstrapping(LoadConfiguration::class, static function ($app) use ($command): void {
        $app['config']->set('commands.paths', []);
        $app['config']->set('commands.add', [$command]);
    });
}
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
config()->set('orbit.home', $configuration['home']);
app(GatewayConfigRepository::class)->add(new GatewayProfile('fixture', 'https://fixture.invalid', '/fixture/ca.pem'));
if (isset($configuration['registration_facts'])) {
    $facts = new GitRegistrationFacts(...$configuration['registration_facts']);
    app()->instance(GitRegistrationDiscovery::class, new readonly class($facts) implements GitRegistrationDiscovery
    {
        public function __construct(private GitRegistrationFacts $facts) {}

        public function inspect(string $path): ?GitRegistrationFacts
        {
            return $this->facts;
        }
    });
}
$replies = $configuration['replies'];
MockClient::global(['*' => static function (PendingRequest $pending) use (&$replies, $configuration): MockResponse {
    $request = $pending->getRequest();
    file_put_contents($configuration['trace'], json_encode([
        'class' => $request::class,
        'method' => $pending->getMethod()->value,
        'url' => $pending->getUrl(),
        'body' => method_exists($request, 'body') ? $request->body()->all() : [],
    ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    $reply = array_shift($replies);

    if ($reply === null || $request::class !== $reply['class']) {
        throw new RuntimeException('Unexpected fixture request.');
    }

    usleep($configuration['delay_us'] ?? 0);

    return MockResponse::make($reply['body'], $reply['status'] ?? 200,
        ['X-Orbit-Request-Id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']);
}]);
$input = new ArgvInput(['orbit', ...$configuration['arguments']]);
$status = $kernel->handle($input, new ConsoleOutput);
$kernel->terminate($input, $status);
exit($status);
