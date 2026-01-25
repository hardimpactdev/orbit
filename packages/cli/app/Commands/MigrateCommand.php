<?php

namespace App\Commands;

use App\Services\DatabaseService;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command;
use PDO;

class MigrateCommand extends Command
{
    protected $signature = 'schema:migrate {--dry-run : Show what would be done without making changes}';

    protected $description = 'Migrate legacy CLI database schema (sites table to projects table)';

    public function handle(DatabaseService $databaseService): int
    {
        $dbPath = $databaseService->getDbPath();

        if (! File::exists($dbPath)) {
            $this->info('Database does not exist yet. It will be initialized on first use.');

            return self::SUCCESS;
        }

        $db = $databaseService->getPdo();
        if (! $db) {
            $this->error('Could not connect to database.');

            return self::FAILURE;
        }

        // Check if projects table (migration) exists
        $hasProjectsTable = count($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='projects'")->fetchAll()) > 0;

        // Check if sites table exists
        $hasSitesTable = count($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sites'")->fetchAll()) > 0;

        $sitesCount = 0;
        if ($hasSitesTable) {
            $sitesCount = (int) $db->query('SELECT COUNT(*) FROM sites')->fetchColumn();
        }

        if ($hasProjectsTable) {
            $this->info('Migrating projects table (migration) to sites table...');

            // Detect projects table structure
            $stmt = $db->query('PRAGMA table_info(projects)');
            $projectColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $hasSlugColumn = false;
            $hasNameColumn = false;
            $hasPathColumn = false;
            foreach ($projectColumns as $column) {
                if ($column['name'] === 'slug') {
                    $hasSlugColumn = true;
                }
                if ($column['name'] === 'name') {
                    $hasNameColumn = true;
                }
                if ($column['name'] === 'path') {
                    $hasPathColumn = true;
                }
            }

            // If projects table has no 'path' column, it's the orbit-core projects table
            // (for GitHub repos), not the legacy CLI sites table. Skip migration.
            if (! $hasPathColumn) {
                $this->info('Projects table is orbit-core table (not legacy CLI). No migration needed.');

                return self::SUCCESS;
            }

            // If projects table has neither slug nor name, we can't migrate
            if (! $hasSlugColumn && ! $hasNameColumn) {
                $this->warn('Projects table has no slug or name column. Skipping migration.');

                return self::SUCCESS;
            }

            if (! $this->option('dry-run')) {
                // Create backup
                $timestamp = date('YmdHis');
                $backupPath = "{$dbPath}.bak.{$timestamp}";
                File::copy($dbPath, $backupPath);
                $this->info("Backup created: {$backupPath}");

                // Build select query based on available columns
                $slugSource = $hasSlugColumn ? 'slug' : 'name';

                if ($hasSitesTable) {
                    $this->warn('Sites table already exists. Merging data from projects table (migration)...');
                    // Merge data from projects into sites, ignoring duplicates
                    $db->exec("INSERT OR IGNORE INTO sites (slug, path, php_version, created_at, updated_at) SELECT {$slugSource}, path, php_version, created_at, updated_at FROM projects");
                    $db->exec('DROP TABLE projects');
                    $this->info('Data merged and projects table (migration) dropped.');
                } else {
                    // Rename table and possibly rename column
                    $db->exec('ALTER TABLE projects RENAME TO sites');
                    $this->info('Table projects renamed to sites.');

                    // If old table used 'name', rename column to 'slug'
                    if (! $hasSlugColumn) {
                        $db->exec('ALTER TABLE sites RENAME COLUMN name TO slug');
                        $this->info('Renamed column name to slug.');
                    }
                }

                // Ensure project_id column exists
                $this->ensureProjectIdColumn($db);
            } else {
                $this->info('[Dry Run] Would create backup and migrate projects table (migration) to sites.');
            }
        } elseif ($hasSitesTable) {
            $this->info('Database has sites table.');

            // Still check for project_id column
            if (! $this->option('dry-run')) {
                $this->ensureProjectIdColumn($db);
            } else {
                $this->info('[Dry Run] Would ensure project_id column exists in sites table.');
            }
        } else {
            $this->info('No migration needed (no projects table (migration) found).');
        }

        return self::SUCCESS;
    }

    protected function ensureProjectIdColumn(PDO $db): void
    {
        $stmt = $db->query('PRAGMA table_info(sites)');
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasProjectId = false;
        foreach ($columns as $column) {
            if ($column['name'] === 'project_id') {
                $hasProjectId = true;
                break;
            }
        }

        if (! $hasProjectId) {
            $db->exec('ALTER TABLE sites ADD COLUMN project_id INTEGER NULL');
            $this->info('Added project_id column to sites table.');
        } else {
            $this->info('project_id column already exists.');
        }
    }
}
