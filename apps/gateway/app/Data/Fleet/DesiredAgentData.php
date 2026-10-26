<?php

declare(strict_types=1);

namespace App\Data\Fleet;

use App\Infrastructure\AgentCommands\AgentDelivery;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The `orbit-agent` build the Gateway delivers: its Cargo version, source commit, and the SHA-256 of each binary. */
#[MapOutputName(SnakeCaseMapper::class)]
final class DesiredAgentData extends Data
{
    /**
     * @param  list<FleetReleaseAssetData>  $assets
     */
    public function __construct(
        /** The Cargo version of the delivered build, such as `0.5.0`. */
        public string $version,
        /** The last commit that changed the agent's sources; the binaries are assets of the Gateway commit's release. */
        public string $commit,
        /** One binary per platform that has a release URL. */
        public array $assets,
    ) {}

    /** The agent build that this Gateway release delivers; only artifacts with a release URL can be pulled. */
    public static function fromDelivery(AgentDelivery $delivery): self
    {
        $assets = [];

        foreach ($delivery->artifacts() as $platform => $artifact) {
            if (isset($artifact['url'])) {
                $assets[] = new FleetReleaseAssetData(
                    platform: $platform,
                    name: basename($artifact['url']),
                    url: $artifact['url'],
                    sha256: $artifact['sha256'],
                );
            }
        }

        return new self($delivery->version(), $delivery->commit(), $assets);
    }
}
