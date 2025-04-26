<?php
/**
 * Author: Eng-Mohamed Salah  👨‍💻
 * Date: 2025-04-11
 * LinkedIn: https://www.linkedin.com/in/mohamed-m-salah/
 * Position: Sr.Backend Developer
 * Job Script: Check if database schema matches migrations, with optional reporting and alerts.
 * Feature: Check if database schema matches migrations
 * Description: This command checks if the database schema matches the migrations.
 * It can check a specific table or all tables in the database.
 * It also generates a report of the schema check and saves it to a log file.
 * Usage: db:check-migrations {--table=} {--skip-migrations}
 * Note: This command uses Doctrine DBAL to check the database schema.
 * It requires the doctrine/dbal package to be installed.
*/

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;
use Doctrine\DBAL\Types\Type;

class CheckMigrations extends Command
{
     /**
     *  Command To Check Database Schema Matches Migrations
     *  Command ⚠️👨‍💻: db:check-migrations {--table=} Select Table1,Table2
     *  Command ⚠️👨‍💻: db:check-migrations {--skip-migrations} Skip Migrations Check
     *  Command ⚠️👨‍💻: db:check-migrations Default
     */
    protected $signature = 'db:check-migrations {--table=} {--skip-migrations}';
    protected $description = 'Check if database schema matches migrations, with optional reporting and alerts.';

    /**
     * Execute the console command.
     * Handle the command logic.
     */

    public function handle()
    {
        $this->info('Starting database schema check...');

        // Register custom type mapping for enum
        $this->registerEnumType();

        $report = []; // لحفظ نتائج التقرير
        $table = $this->option('table');
        $skipMigrations = $this->option('skip-migrations');

        if ($table) {
            $this->info("Checking specific table: $table");
            if (!Schema::hasTable($table)) {
                $this->error("Table '$table' does not exist in the database.");
                return;
            }
            $report[$table] = $this->checkTableSchema($table);
        } elseif ($skipMigrations) {
            $this->info("Skipping migration check and focusing on database schema.");
            $report = $this->checkDatabaseSchema();
        } else {
            $this->info("Checking migrations and database schema...");
            $this->checkMigrations($report);
            $report = $this->checkDatabaseSchema();
        }

        // كتابة التقرير إلى ملف
        $this->generateReport($report);
    }

    /**
     * Register custom type mapping for enum to avoid Doctrine DBAL exceptions.
     */
    private function registerEnumType()
    {
        $platform = Schema::getConnection()->getDoctrineSchemaManager()->getDatabasePlatform();
        $platform->markDoctrineTypeCommented(Type::STRING); // Mark string as commented to avoid issues
        $platform->registerDoctrineTypeMapping('enum', 'string'); // Map enum to string
    }

    /**
     * Check if the migrations are present in the database.
     *
     * @param array $report
     */
    private function checkMigrations(&$report)
    {
        $migrations = DB::table('migrations')->pluck('migration')->toArray();
        $migrationFiles = $this->getMigrationFiles();

        $missingMigrations = array_diff($migrations, $migrationFiles);

        if (!empty($missingMigrations)) {
            $this->error('Missing migrations:');
            foreach ($missingMigrations as $missing) {
                $this->error("- $missing");
                $report['migrations']['missing'][] = $missing;
            }
        } else {
            $this->info('All migrations are present in the database.');
            $report['migrations']['status'] = 'All migrations are present';
        }
    }

    /**
     * Get the list of migration files in the migrations directory.
     *
     * @return array
     */
    private function getMigrationFiles()
    {
        $files = glob(base_path('database/migrations/*.php'));
        return array_map(function ($file) {
            return pathinfo($file, PATHINFO_FILENAME);
        }, $files);
    }
    /**
     * Check the database schema against the migrations.
     *
     * @return array
     */
    private function checkDatabaseSchema()
    {
        $report = [];
        $tables = Schema::getConnection()->getDoctrineSchemaManager()->listTableNames();

        foreach ($tables as $table) {
            $this->info("Checking table: $table");
            $report[$table] = $this->checkTableSchema($table);
        }

        return $report;
    }

    /**
     * Check the schema of a specific table.
     *
     * @param string $table
     * @return array
     */
    private function checkTableSchema($table)
    {
        $this->info("Checking schema for table: $table");

        $report = [
            'missing_columns' => [],
            'missing_indexes' => [],
            'status' => 'valid'
        ];

        $columns = Schema::getColumnListing($table);
        $indexes = $this->getIndexes($table);
        $migrationTable = $this->getMigrationTable($table);

        if (empty($migrationTable)) {
            $this->warn("No migration found for table: $table");
            $report['status'] = 'No migration found';
            return $report;
        }

        foreach ($columns as $column) {
            if (strpos($migrationTable, "'$column'") === false) {
                $this->warn("Column $column is missing from migration!");
                $report['missing_columns'][] = $column;
                $report['status'] = 'issues found';
            }
        }

        foreach ($indexes as $index) {
            if (strpos($migrationTable, "$index") === false) {
                $this->warn("Index $index is missing from migration!");
                $report['missing_indexes'][] = $index;
                $report['status'] = 'issues found';
            }
        }

        $this->info("Schema check for table '$table' is complete.");
        return $report;
    }

    /**
     * Get the migration file content for a specific table.
     *
     * @param string $table
     * @return string|null
     */
    private function getMigrationTable($table)
    {
        $files = glob(base_path('database/migrations/*.php'));

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (strpos($content, "Schema::create('$table'") !== false) {
                return $content;
            }
        }
        return null;
    }

    /**
     * Get the indexes for a specific table.
     *
     * @param string $table
     * @return array
     */
    private function getIndexes($table)
    {
        $indexes = [];
        $manager = Schema::getConnection()->getDoctrineSchemaManager();
        $doctrineTable = $manager->listTableDetails($table);

        foreach ($doctrineTable->getIndexes() as $index) {
            $indexes[] = $index->getName();
        }

        return $indexes;
    }

    /**
     * Generate a report of the schema check and save it to a log file.
     *
     * @param array $report
     */
    private function generateReport($report)
    {
        $filename = storage_path('logs/migration_report_' . date('Y_m_d_H_i_s') . '.log');
        $logContent = json_encode($report, JSON_PRETTY_PRINT);

        File::put($filename, $logContent);
        $this->info("Report generated at: $filename");

        // Check for issues in the report
        $issues = array_filter($report, function ($table) {
            return isset($table['status']) && $table['status'] !== 'valid';
        });

        if (!empty($issues)) {
            $this->error("Some issues were found. Check the report for more details.");
        } else {
            $this->info("No issues found. Everything is valid!");
        }
    }
}
