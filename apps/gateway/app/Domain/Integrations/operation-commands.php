<?php

declare(strict_types=1);
use App\Domain\Integrations\IntegrationCertificateCommand;
use App\Domain\Integrations\IntegrationReceiptCommand;
use App\Domain\Integrations\IntegrationRolePrepareCommand;
use App\Domain\WebSocket\WebSocketPublicationStateCommand;

return [
    'websocket.publication-state' => WebSocketPublicationStateCommand::class,
    'integration.prepare-role' => IntegrationRolePrepareCommand::class,
    'integration.certificate-record' => IntegrationCertificateCommand::class,
    'integration.receipt' => IntegrationReceiptCommand::class,
    ...require base_path('extensions/conn/src/operation-commands.php'),
    ...require base_path('extensions/ai-router/src/operation-commands.php'),
];
