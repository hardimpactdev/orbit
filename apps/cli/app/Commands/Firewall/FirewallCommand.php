<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

abstract class FirewallCommand extends GatewayCommand
{
    use FollowsOperations;

    protected function nodeId(): ?int
    {
        $node = filter_var(
            $this->option('node'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if (is_int($node)) {
            return $node;
        }

        $this->renderGatewayFailure(
            'firewall.node_id_invalid',
            'Node ID must be a positive integer.',
        );

        return null;
    }

    protected function protocol(): string|false|null
    {
        $protocol = $this->stringOption('protocol');

        if ($protocol === null) {
            return null;
        }

        if (in_array($protocol, ['tcp', 'udp'], strict: true)) {
            return $protocol;
        }

        $this->renderGatewayFailure(
            'firewall.protocol_invalid',
            'Firewall protocol must be tcp or udp.',
        );

        return false;
    }

    protected function source(): string|false|null
    {
        $source = $this->stringOption('from');

        if ($source === null) {
            return null;
        }

        if ($source === 'any') {
            return $source;
        }

        $parts = explode('/', $source, limit: 2);
        $address = $parts[0];
        $prefix = $parts[1] ?? null;
        $packedAddress = inet_pton($address);

        if ($packedAddress === false) {
            $this->renderInvalidSource();

            return false;
        }

        if ($prefix === null) {
            return $source;
        }

        if (
            preg_match('/\A(?:0|[1-9]\d{0,2})\z/D', $prefix) === 1
            && (int) $prefix <= (strlen($packedAddress) * 8)
        ) {
            return $source;
        }

        $this->renderInvalidSource();

        return false;
    }

    /**
     * Validates the Node, port, and protocol in that order and renders the first failure.
     *
     * @return array{int, string, ?string}|null
     */
    protected function target(): ?array
    {
        $nodeId = $this->nodeId();
        $port = $nodeId === null ? null : $this->port();
        $protocol = $port === null ? false : $this->protocol();

        return $nodeId === null || $port === null || $protocol === false ? null : [$nodeId, $port, $protocol];
    }

    protected function describe(string $port, ?string $protocol, ?string $source): string
    {
        return $port.($protocol === null ? '' : "/{$protocol}").($source === null ? '' : " from {$source}");
    }

    /** Sends one firewall admission and follows its Operation to the end. */
    protected function admit(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
        GatewayRequest $request,
        string $title,
        string $verb,
    ): int {
        $connector = $this->gatewayConnector($repository, $connectors);
        $profile = $connector === null ? null : $this->activeGatewayProfile($repository);

        if ($connector === null || $profile === null) {
            return self::FAILURE;
        }

        try {
            $operation = $this->sendOrThrow($connector, $request, OperationResponse::class);
        } catch (GatewayApiException $exception) {
            return $exception->errorCode() === 'firewall.rule_ambiguous'
                ? $this->renderAmbiguousRule($exception)
                : $this->renderApiFailure($exception);
        }

        return $this->followOperation($connector, $profile, $operation, $title, $verb);
    }

    protected function port(): ?string
    {
        $port = $this->arguments()['port'] ?? null;
        $matches = [];

        if (! is_string($port) || preg_match('/\A(\d{1,5})(?::(\d{1,5}))?\z/D', $port, $matches) !== 1) {
            $this->renderInvalidPort();

            return null;
        }

        $start = (int) $matches[1];
        $end = isset($matches[2]) ? (int) $matches[2] : $start;

        if ($start >= 1 && $start <= 65_535 && $end >= $start && $end <= 65_535) {
            return $port;
        }

        $this->renderInvalidPort();

        return null;
    }

    /** Renders a refused removal with one line per rule that matched the filter. */
    private function renderAmbiguousRule(GatewayApiException $exception): int
    {
        $matches = [];

        foreach (is_array($exception->details()['matches'] ?? null) ? $exception->details()['matches'] : [] as $match) {
            $field = static fn (string $key): ?string => is_array($match) && is_string($match[$key] ?? null) ? $match[$key] : null;
            $port = $field('port');

            if ($port !== null) {
                $label = $field('label');
                $matches[] = $this->describe($port, $field('protocol'), $field('source')).': '.$field('action').($label === null ? '' : " ({$label})");
            }
        }

        return $this->renderGatewayFailure(
            'firewall.rule_ambiguous',
            $exception->getMessage(),
            $exception->requestId(),
            details: $matches === [] ? [] : ['matches' => $matches],
        );
    }

    private function renderInvalidSource(): void
    {
        $this->renderGatewayFailure(
            'firewall.source_invalid',
            'Firewall source must be any or a valid IPv4 or IPv6 address or CIDR.',
        );
    }

    private function renderInvalidPort(): void
    {
        $this->renderGatewayFailure(
            'firewall.port_invalid',
            'Firewall port must be from 1 to 65535 or an ordered range.',
        );
    }
}
