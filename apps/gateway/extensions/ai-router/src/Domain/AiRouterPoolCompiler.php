<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use Illuminate\Support\Carbon;

final readonly class AiRouterPoolCompiler
{
    public function __construct(
        private AiRouterWindowOrder $order = new AiRouterWindowOrder,
    ) {}

    /**
     * @param  list<AiRouterAccount>  $accounts
     * @param  list<AiRouterModel>  $models
     */
    public function compile(array $accounts, ?string $collectedAt, array $models = []): AiRouterSnapshot
    {
        $grouped = [];

        foreach ($accounts as $account) {
            $grouped[$account->provider][] = $account;
        }

        ksort($grouped);
        $providers = [];

        foreach ($grouped as $provider => $members) {
            $providers[] = new AiRouterProviderPool(
                $provider,
                $this->poolWindows($members),
                $members,
            );
        }

        return new AiRouterSnapshot($accounts, $providers, $collectedAt, $models);
    }

    /**
     * Toggle only changes the disabled flag on the cached account. It never invents windows.
     *
     * @param  list<AiRouterAccount>  $accounts
     * @return list<AiRouterAccount>
     */
    public function withDisabled(array $accounts, string $accountId, bool $disabled): array
    {
        return array_map(
            static function (AiRouterAccount $account) use ($accountId, $disabled): AiRouterAccount {
                if ($account->id !== $accountId) {
                    return $account;
                }

                return new AiRouterAccount(
                    $account->id,
                    $account->provider,
                    $account->label,
                    $disabled,
                    $disabled ? 'disabled' : 'enabled',
                    $account->windows,
                    $account->error,
                );
            },
            $accounts,
        );
    }

    /**
     * @param  list<AiRouterAccount>  $accounts
     * @return list<AiRouterWindow>
     */
    private function poolWindows(array $accounts): array
    {
        $used = [];
        $count = [];
        $resets = [];

        foreach ($accounts as $account) {
            if ($account->disabled) {
                continue;
            }

            foreach ($account->windows as $window) {
                $used[$window->label] = ($used[$window->label] ?? 0.0) + $window->usedPercent;
                $count[$window->label] = ($count[$window->label] ?? 0) + 1;
                $resets[$window->label] = $this->earliest($resets[$window->label] ?? null, $window->resetsAt);
            }
        }

        $windows = [];

        foreach ($used as $label => $total) {
            $windows[] = new AiRouterWindow(
                $label,
                $total / $count[$label],
                $resets[$label],
            );
        }

        return $this->order->sort($windows);
    }

    private function earliest(?string $left, ?string $right): ?string
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return Carbon::parse($left)->lessThanOrEqualTo(Carbon::parse($right)) ? $left : $right;
    }
}
