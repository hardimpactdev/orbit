<?php

declare(strict_types=1);

namespace App\Infrastructure\Certificates;

use CurlHandle;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\EasyHandle;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use SensitiveParameter;

/**
 * Guzzle's verify option sets CAINFO but leaves libcurl's system CAPATH in place. Guzzle 8 rejects
 * CAPATH in request options, so pin an empty private directory on the configured native handle.
 * cURL keeps the request's overall deadline, including a peer that slowly sends HTTP headers.
 */
final readonly class OrbitCaCurlFactory implements CurlFactoryInterface
{
    private CurlFactory $factory;

    private string $caDirectory;

    public function __construct()
    {
        $this->factory = new CurlFactory(0);
        $this->caDirectory = sys_get_temp_dir().'/orbit-ca-'.bin2hex(random_bytes(16));

        if (! mkdir($this->caDirectory, 0o700)) {
            throw new RuntimeException('Could not create an empty CA directory.');
        }
    }

    /** @param array<string, mixed> $options */
    public function create(#[SensitiveParameter] RequestInterface $request, #[SensitiveParameter] array $options): EasyHandle
    {
        if (! is_string($options['verify'] ?? null) || ! is_file($options['verify'])) {
            throw new InvalidArgumentException('Internal HTTPS requires an Orbit CA file.');
        }

        $easy = $this->factory->create($request, $options);

        if (! $easy->handle instanceof CurlHandle || ! curl_setopt($easy->handle, CURLOPT_CAPATH, $this->caDirectory)) {
            $this->factory->release($easy);

            throw new RuntimeException('Could not disable the system CA directory.');
        }

        return $easy;
    }

    public function release(#[SensitiveParameter] EasyHandle $easy): void
    {
        $this->factory->release($easy);
    }

    public function __destruct()
    {
        if (is_dir($this->caDirectory)) {
            @rmdir($this->caDirectory);
        }
    }
}
