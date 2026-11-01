<?php

declare(strict_types=1);

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Illuminate\Contracts\Console\Kernel;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/** Send one fixture API request with the operator CLI's existing Gateway profile. */
function swampProofRequest(GatewayConnector $connector, string $method, string $path, stdClass $body): Response
{
    if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
        || ! str_starts_with($path, '/api/') || preg_match('/[\x00-\x20\x7f?#]/', $path) === 1) {
        throw new InvalidArgumentException('proof.api_request_invalid');
    }

    $request = new class($method, $path, $body) extends GatewayRequest implements HasBody
    {
        use HasJsonBody;

        public function __construct(string $method, private readonly string $path, #[SensitiveParameter] private readonly stdClass $input)
        {
            $this->method = Method::from($method);
        }

        public function resolveEndpoint(): string
        {
            return $this->path;
        }

        /** @return array<string, mixed> */
        protected function defaultBody(): array
        {
            return (array) $this->input;
        }
    };

    return $connector->send($request);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $root = dirname(__DIR__).'/apps/cli';
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $profile = $app->make(GatewayConfigRepository::class)->active();
        $body = json_decode(stream_get_contents(STDIN), false, 512, JSON_THROW_ON_ERROR);
        if ($profile === null || ! $body instanceof stdClass || count($argv) !== 3) {
            throw new InvalidArgumentException('proof.api_request_invalid');
        }
        $connector = $app->make(GatewayConnectorFactory::class)->make($profile, timeout: 120);
        $response = swampProofRequest($connector, $argv[1], $argv[2], $body);
        echo $response->body();
    } catch (Throwable) {
        // Exceptions may carry a private request body; print no transport dump or stack trace.
        fwrite(STDERR, "proof.api_request_failed\n");
        exit(1);
    }
}
