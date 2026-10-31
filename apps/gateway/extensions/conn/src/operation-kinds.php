<?php

declare(strict_types=1);

$kinds = [];
$kinds['conn.pairing_create'] = [
    'executor' => 'swamp',
    'result_fields' => ['backend', 'pairing_id', 'pairing_link_id', 'expires_at', 'error_code',
        ['name' => 'credential', 'secret' => true, 'store' => 'conn.pairing.credential']],
];
$kinds['conn.pairing_revoke'] = ['executor' => 'swamp', 'result_fields' => ['backend', 'revoked_sessions', 'error_code']];
$kinds['conn.gateway_sessions_revoke'] = ['executor' => 'swamp', 'result_fields' => ['backend', 'revoked_sessions', 'error_code']];

return $kinds;
