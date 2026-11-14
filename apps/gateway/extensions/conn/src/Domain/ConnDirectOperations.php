<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\DirectOperationContribution;

final readonly class ConnDirectOperations implements DirectOperationContribution
{
    public function definitions(): array
    {
        $kinds = array_fill_keys(['conn.gateway_sessions_revoke'], [
            'workflow' => 'operation-conn-direct', 'projection' => ConnCleanupProjection::class, 'result_hook' => ConnDirectResult::class,
            'workflow_inputs' => true,
            'terminal_result' => ['job' => 'conn', 'step' => 'Revoke old sessions', 'native' => true,
                'captures' => ['revocation_receipts' => ['job' => 'conn', 'step' => 'Revoke old sessions']]],
            'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^conn-pairing-revoke-[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$']],
        ]);
        $kinds['conn.pairing_create'] = ['workflow' => 'operation-conn-pairing-create',
            'projection' => ConnPairingProjection::class, 'workflow_inputs' => true,
            'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm pairing', 'native' => true,
                'captures' => ['issued_receipt' => ['job' => 'pairing', 'step' => 'Store the link in vault', 'field' => 'stdout']]],
            'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^conn-pairing-create-[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^nckrtl-conn-pairing-[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}-issue-issued-pairing-[1-9][0-9]*-credential$']]];

        $kinds['conn.pairing_revoke'] = ['workflow' => 'operation-conn-pairing-revoke',
            'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^conn-pairing-revoke-[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$']],
            'projection' => ConnRevocationProjection::class, 'result_hook' => ConnDirectResult::class,
            'workflow_inputs' => true, 'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm revocation', 'native' => true,
                'captures' => ['session_receipt' => ['job' => 'session-replies', 'step' => 'Read session revocations', 'field' => 'stdout'],
                    'link_receipt' => ['job' => 'link-replies', 'step' => 'Read link revocations', 'field' => 'stdout']]]];

        // The session the Gateway holds for a Node; only its native assert ends the run, and the receipt is the store step's stdout.
        $kinds[ConnHeldSessions::Kind] = ['workflow' => 'operation-conn-session-hold', 'projection' => ConnSessionHoldProjection::class, 'workflow_inputs' => true,
            'terminal_result' => ['job' => 'cleanup', 'step' => 'Confirm session', 'native' => true,
                'captures' => ['held_receipt' => ['job' => 'hold', 'step' => 'Store the session', 'field' => 'stdout'],
                    'link_key' => ['job' => 'hold', 'step' => 'Read the link', 'field' => 'credential_key']]],
            'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^conn-session-hold-[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$'],
                ['vault' => 'orbit-control-inputs', 'key_pattern' => '^nckrtl-conn-session-[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}-(?:link-link-pairing-[1-9][0-9]*-credential|hold-held-pairing-[1-9][0-9]*-access_token)$']]];

        // A renewal reports through its own last step, so a refusal keeps its `conn.*` code.
        $kinds['conn.environment_renew'] = ['workflow' => 'operation-conn-environment-renew', 'projection' => ConnRenewalProjection::class,
            'workflow_inputs' => true, 'terminal_result' => ['job' => 'cleanup', 'step' => 'Report the renewal'],
            'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => ConnRenewalProjection::SessionKey]]];

        return $kinds;
    }
}
