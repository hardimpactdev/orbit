<?php

declare(strict_types=1);
use App\Domain\Integrations\IntegrationCertificateCommand;
use App\Domain\Integrations\IntegrationReceiptCommand;

return [
    'websocket.publication-state' => \App\Domain\WebSocket\WebSocketPublicationStateCommand::class,
    'integration.prepare-role' => \App\Domain\Integrations\IntegrationRolePrepareCommand::class,
    'integration.certificate-record' => IntegrationCertificateCommand::class,
    'integration.receipt' => IntegrationReceiptCommand::class,
    ...require base_path('extensions/conn/src/operation-commands.php'),
    ...require base_path('extensions/ai-router/src/operation-commands.php'),
];
