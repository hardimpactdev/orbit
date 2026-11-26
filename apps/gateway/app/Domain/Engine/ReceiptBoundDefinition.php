<?php

declare(strict_types=1);

namespace App\Domain\Engine;

/**
 * A kind whose Node steps settle only on a terminal receipt (ADR 0271): a lost receipt keeps the step, and the Operation's
 * target, fenced until the Node's journal answers the stored result. The kind names, per action, the receipt statuses that
 * end a step; any other status, a mismatched action or target, or an order that expired before the Node took it never does.
 */
interface ReceiptBoundDefinition
{
    /** Each executor action's terminal receipt beside `failed`: a desired-state action ends `applied`, a request `output`. */
    public const array Terminal = ['route.withdraw_site' => ['applied', 'failed'], 'route.publish_site' => ['applied', 'failed'],
        'dns.publish_records' => ['applied', 'failed'], 'tls.ensure_csr' => ['output', 'failed'], 'instance.destroy' => ['done', 'failed']];

    /**
     * The receipt statuses that end a step of `$action`: its success status and `failed`.
     *
     * @return list<string>
     */
    public function terminalStatuses(string $action): array;
}
