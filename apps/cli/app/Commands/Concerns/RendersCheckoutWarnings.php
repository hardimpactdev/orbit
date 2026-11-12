<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Support\GatewayFailureRenderer;
use Orbit\Sdk\Responses\Operations\OperationResponse;

trait RendersCheckoutWarnings
{
    protected function writeCheckoutWarnings(OperationResponse $operation): void
    {
        $warnings = $operation->result['warnings'] ?? [];
        foreach (array_slice(is_array($warnings) ? $warnings : [], 0, 20) as $warning) {
            if (is_array($warning) && ($warning['code'] ?? null) === 'instance.checkout_unknown') {
                $details = GatewayFailureRenderer::checkoutDetails($warning);
                if (isset($details['path'], $details['cause'])) {
                    $this->writeHumanMessage('Warning: instance.checkout_unknown. '.$details['path']
                        .(($details['instance_ids'] ?? []) === [] ? '' : ' (Instance #'.implode(', #', $details['instance_ids']).')').': '.$details['cause'].'.');
                }
            }
        }
        $remaining = $operation->result['other_warnings'] ?? null;
        if (is_int($remaining) && $remaining > 0) {
            $this->writeHumanMessage('Additional unknown checkouts: '.$remaining);
        }
    }
}
