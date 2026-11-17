<?php

declare(strict_types=1);

namespace App\Commands\Proof;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\GatedExtensionCommand;
use Orbit\Sdk\Requests\Proof\ProofPoolRequest;
use Orbit\Sdk\Responses\Proof\ProofPoolResponse;
use Throwable;

/** A record-only change of the warm proof copy pool: it prints the pool's state, its warm copies and the copies of running proofs. */
abstract class ProofPoolCommand extends GatewayCommand implements GatedExtensionCommand
{
    public function extensionSlug(): ?string
    {
        return 'tasks';
    }

    abstract protected function request(): ProofPoolRequest;

    abstract protected function failure(): string;

    public function handle(GatewayConfigRepository $profiles, GatewayConnectorFactory $connectors): int
    {
        try {
            $profile = $this->activeGatewayProfile($profiles);
            if ($profile === null) {
                return self::FAILURE;
            }
            $pool = $this->sendOrThrow($connectors->make($profile), $this->request(), ProofPoolResponse::class);
        } catch (Throwable $exception) {
            return $this->renderRequestFailure($exception, $this->failure());
        }
        if ($this->option('json') === true) {
            $this->writeJson($pool->toArray());

            return self::SUCCESS;
        }
        $ids = static fn (array $copies): string => $copies === [] ? 'none' : implode(', ', $copies);
        ConsoleWriter::write($this->output, ($pool->drained ? 'The warm proof copy pool is drained.' : 'The warm proof copy pool refills.')."\n"
            .'Warm copies: '.$ids($pool->warmCopies)."\n"
            .'Copies of running proofs: '.$ids($pool->proofCopies)."\n");

        return self::SUCCESS;
    }
}
