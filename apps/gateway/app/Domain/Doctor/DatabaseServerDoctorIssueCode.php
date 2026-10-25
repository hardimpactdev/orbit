<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum DatabaseServerDoctorIssueCode: string implements DoctorIssueCode
{
    case TestDatabaseMissing = 'database_server.test_database_missing';
    case ReplicationMismatch = 'database_server.replication_mismatch';
    case PublicationMismatch = 'database_server.publication_mismatch';
    case LoginFailed = 'database_server.login_failed';
    case InitializationSecretPresent = 'database_server.initialization_secret_present';
    case Open = 'database_server.open';
    case AclFileMissing = 'database_server.acl_file_missing';
    case AclMismatch = 'database_server.acl_mismatch';
    case InspectionFailed = 'database_server.inspection_failed';

    public function code(): string
    {
        return $this->value;
    }

    public function family(): DoctorFamily
    {
        return DoctorFamily::DatabaseServer;
    }
}
