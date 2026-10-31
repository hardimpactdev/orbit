<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

final readonly class AdmitRecipePrAction
{
    public function __construct(private StartOperationAction $start, private GatewayServingHost $gateway, private FleetServingRelease $serving) {}

    public function execute(string $action, string $variant, int $number, string $head, string $date): Operation
    {
        $nodeId = $this->gateway->nodeId();
        if ($nodeId === null || $this->serving->read()['state'] !== FleetServingRelease::Adopted || $this->serving->supersedes()
            || $date !== now('Europe/Amsterdam')->format('Ymd') || ! in_array($action, ['open', 'update', 'merge', 'retire'], true)
            || ! in_array($variant, ['snapshot', 'artifacts'], true) || ($action === 'open' ? ($number !== 0 || $head !== '') : ($number < 1 || preg_match('/\A[0-9a-f]{40}\z/D', $head) !== 1))) {
            throw new ResourceOperationException('fleet.recipe_pr_invalid', 'The recipe PR action does not match the serving Gateway.', 422);
        }

        return $this->start->execute('fleet.recipe_pr', $number === 0 ? 'recipe_'.$variant : 'recipe_pr', $number === 0 ? $nodeId : $number, $nodeId, [
            'action' => $action, 'variant' => $variant, 'pull_number' => $number, 'head_sha' => $head, 'date' => $date,
            'token' => ['$secret' => 'github.app', 'id' => $nodeId],
        ], 'swamp', null);
    }
}
