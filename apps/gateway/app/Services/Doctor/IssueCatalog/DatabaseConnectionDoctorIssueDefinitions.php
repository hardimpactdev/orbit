<?php

declare(strict_types=1);

namespace App\Services\Doctor\IssueCatalog;

use App\Data\Doctor\DoctorIssueDefinition;

/** Explicit database_connection family Doctor issue classifications. */
final class DatabaseConnectionDoctorIssueDefinitions implements DoctorIssueDefinitionProvider
{
    use DefinesDoctorIssues;

    /**
     * @return list<DoctorIssueDefinition>
     */
    public function definitions(): array
    {
        return [
            self::invalid('database_connection.env_extra', 'database_connection', adoptable: true),
            self::genuine(
                'database_connection.env_mismatch',
                'database_connection',
                'restore_database_connection_env_mismatch',
                adoptable: true,
            ),
            self::genuine(
                'database_connection.env_missing',
                'database_connection',
                'restore_database_connection_env_missing',
            ),
            self::blocked('database_connection.remote_shell_probe_failed', 'database_connection'),
            self::invalid('database_connection.target_extra', 'database_connection', adoptable: true),
            self::genuine(
                'database_connection.target_missing',
                'database_connection',
                'restore_database_connection_target_missing',
            ),
            self::blocked('database_connection.unverifiable', 'database_connection'),
            self::incident('database_connection.wireguard_self_route_unavailable', 'database_connection'),
        ];
    }
}
