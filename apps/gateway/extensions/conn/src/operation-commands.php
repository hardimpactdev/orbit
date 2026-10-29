<?php

declare(strict_types=1);
use Orbit\Extensions\Conn\Domain\ConnGatewaySessionsCommand;
use Orbit\Extensions\Conn\Domain\ConnPairingIssueCommand;
use Orbit\Extensions\Conn\Domain\ConnPairingRevokeCommand;

return [
    'conn.gateway-sessions-revoke' => ConnGatewaySessionsCommand::class,
    'conn.pairing-issue' => ConnPairingIssueCommand::class,
    'conn.pairing-revoke' => ConnPairingRevokeCommand::class,
];
