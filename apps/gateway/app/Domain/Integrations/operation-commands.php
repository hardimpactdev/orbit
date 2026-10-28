<?php

declare(strict_types=1);
use App\Domain\Integrations\IntegrationCertificateCommand;
use App\Domain\Integrations\IntegrationReceiptCommand;

return [
    'integration.certificate-record' => IntegrationCertificateCommand::class,
    'integration.receipt' => IntegrationReceiptCommand::class,
    ...require base_path('extensions/conn/src/operation-commands.php'),
    ...require base_path('extensions/ai-router/src/operation-commands.php'),
];
