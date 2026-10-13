<?php

declare(strict_types=1);

/*
 * Inertia serves only the Horizon New Dawn dashboard (docs/reference/gateway-queue.md#dashboard). Its DevTools routes
 * stay off in every environment, so the Gateway exposes no `_inertia` path.
 */
return [
    'devtools' => [
        'enabled' => false,
    ],
];
