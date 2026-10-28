<?php

declare(strict_types=1);

return [
    'integration.certificate-record' => \App\Domain\Integrations\IntegrationCertificateCommand::class,
    'integration.receipt' => \App\Domain\Integrations\IntegrationReceiptCommand::class,
    ...require base_path('extensions/conn/src/operation-commands.php'),
    ...require base_path('extensions/ai-router/src/operation-commands.php'),
];
