<?php

declare(strict_types=1);
use Orbit\Extensions\Conn\Domain\ConnPairingIssueCommand;
use Orbit\Extensions\Conn\Domain\ConnRevocationCommand;

return [
    'conn.pairing-issue' => ConnPairingIssueCommand::class,
    'conn.revocation' => ConnRevocationCommand::class,
];
