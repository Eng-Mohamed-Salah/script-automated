<?php
/**
 * Author: Eng-Mohamed Salah  👨‍💻
 * Date: 2025-04-11
 * Updated: 2025-06-27
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
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Illuminate\Support\Str;

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

    public function handle()
    {
        $this->addCustomStyles();
        $this->info('🚀 <key>Starting database schema check...</key>');

        $this->registerEnumType();

        $report = [];
        $table = $this->option('table');
        $skipMigrations = $this->option('skip-migrations');

        if ($table) {
            $this->info("🎯 <key>Checking specific table:</key> <value>$table</value>");
            if (!Schema::hasTable($table)) {
                $this->error("<missing>❌ Table '$table' does not exist in the database.</missing>");
                return;
            }
            $report[$table] = $this->checkTableSchema($table);
        } elseif ($skipMigrations) {
            $this->info("⏭️ <key>Skipping migration check</key> and focusing on database schema.");
            $report = $this->checkDatabaseSchema();
        } else {
            $this->info("📦 <key>Checking migrations and database schema...</key>");
            $this->checkMigrations($report);
            $report = $this->checkDatabaseSchema();
        }

        $this->generateReport($report);
    }

    private function addCustomStyles()
    {
        $this->output->getFormatter()->setStyle('key', new OutputFormatterStyle('blue', null, ['bold']));
        $this->output->getFormatter()->setStyle('value', new OutputFormatterStyle('green'));
        $this->output->getFormatter()->setStyle('missing', new OutputFormatterStyle('red', null, ['bold']));
        $this->output->getFormatter()->setStyle('type', new OutputFormatterStyle('cyan'));
        $this->output->getFormatter()->setStyle('index', new OutputFormatterStyle('magenta'));
    }

    private function registerEnumType()
    {
        $platform = Schema::getConnection()->getDoctrineSchemaManager()->getDatabasePlatform();
        $platform->markDoctrineTypeCommented(Type::STRING);
        $platform->registerDoctrineTypeMapping('enum', 'string');
    }

    private function checkMigrations(&$report)
    {
        $migrations = DB::table('migrations')->pluck('migration')->toArray();
        $migrationFiles = $this->getMigrationFiles();

        $missingMigrations = array_diff($migrations, $migrationFiles);

        if (!empty($missingMigrations)) {
            $this->error("<missing>❌ Missing migrations:</missing>");
            foreach ($missingMigrations as $missing) {
                $this->line("<missing>- $missing</missing>");
                $report['migrations']['missing'][] = $missing;
            }
        } else {
            $this->line("<value>✅ All migrations are present in the database.</value>");
            $report['migrations']['status'] = 'All migrations are present';
        }
    }

    private function getMigrationFiles()
    {
        $files = glob(base_path('database/migrations/*.php'));
        return array_map(fn($file) => pathinfo($file, PATHINFO_FILENAME), $files);
    }

    private function checkDatabaseSchema()
    {
        $report = [];
        $tables = Schema::getConnection()->getDoctrineSchemaManager()->listTableNames();

        foreach ($tables as $table) {
            $this->line("\n🧾 <key>Table:</key> <value>$table</value>");
            $report[$table] = $this->checkTableSchema($table);
        }

        return $report;
    }

    private function checkTableSchema($table)
    {
        $report = [
            'missing_columns' => [],
            'missing_indexes' => [],
            'status' => 'valid',
            'columns_table' => [],
            'indexes_table' => [],
            'relationships' => [],
            'external_relationships' => []
        ];

        $migrationTable = $this->getMigrationTable($table);

        if (empty($migrationTable)) {
            $this->line("<missing>⚠️ No migration file found for table:</missing> <key>$table</key>");
            $report['status'] = 'No migration found';
            return $report;
        }

        $manager = Schema::getConnection()->getDoctrineSchemaManager();
        $doctrineTable = $manager->listTableDetails($table);
        $columnsDetails = $doctrineTable->getColumns();
        $columnsTable = [];

        foreach ($columnsDetails as $columnName => $columnData) {
            $type = $columnData->getType()->getName();

            // Use enhanced column detection method
            $inMigration = $this->isColumnInMigration($columnName, $migrationTable);

            $statusText = $inMigration ? '✅ Exists' : '❌ Missing in Migration';

            if (!$inMigration) {
                $report['missing_columns'][] = $columnName;
                $report['status'] = 'issues found';
            }

            $columnsTable[] = [
                'Column' => $columnName,
                'Type' => $type,
                'Status' => $statusText,
            ];
        }

        $this->line("<key>📋 Columns for table:</key> <value>$table</value>");
        $this->table(['Column', 'Type', 'Status'], $columnsTable);
        $report['columns_table'] = $columnsTable;

        // Indexes
        $indexesTable = [];
        foreach ($doctrineTable->getIndexes() as $indexObj) {
            $indexName = $indexObj->getName();
            $indexColumns = $indexObj->getColumns();

            $inMigration = false;
            foreach ($indexColumns as $col) {
                if (preg_match("/->\w+\('$col'\)->(index|unique|primary)\(/", $migrationTable)) {
                    $inMigration = true;
                    break;
                }
            }

            if (strpos($migrationTable, $indexName) !== false) {
                $inMigration = true;
            }

            $statusText = $inMigration ? '✅ Exists' : '❌ Missing in Migration';

            if (!$inMigration) {
                $report['missing_indexes'][] = $indexName;
                $report['status'] = 'issues found';
            }

            $indexesTable[] = [
                'Index' => $indexName,
                'Status' => $statusText
            ];
        }

        if (!empty($indexesTable)) {
            $this->line("<index>📌 Indexes:</index>");
            $this->table(['Index', 'Status'], $indexesTable);
            $report['indexes_table'] = $indexesTable;
        }

        // Internal relationships
        $relationships = [];

        if (preg_match_all("/->foreignId\('(\w+)'\)->constrained\(['\"]?(\w+)?['\"]?\)/", $migrationTable, $matches1, PREG_SET_ORDER)) {
            foreach ($matches1 as $match) {
                $relationships[] = [
                    'Column' => $match[1],
                    'Related Table' => $match[2] ?? $this->guessTableFromColumn($match[1]),
                    'Type' => 'foreignId → constrained',
                ];
            }
        }

        if (preg_match_all("/->foreign\('(\w+)'\)->references\('(\w+)'\)->on\('(\w+)'\)/", $migrationTable, $matches2, PREG_SET_ORDER)) {
            foreach ($matches2 as $match) {
                $relationships[] = [
                    'Column' => $match[1],
                    'Related Table' => $match[3],
                    'Type' => "foreign('{$match[1]}')->references('{$match[2]}')->on('{$match[3]}')",
                ];
            }
        }

        if (!empty($relationships)) {
            $this->line("<key>🔁 Relationships (used by this table):</key>");
            $this->table(['Column', 'Related Table', 'Type'], $relationships);
            $report['relationships'] = $relationships;
        }

        // External relationships - Enhanced analysis
        $externalRelations = $this->checkAndDisplayExternalRelationships($table);
        if (!empty($externalRelations)) {
            $this->line("<key>📎 Final External Relationships Summary:</key>");
            $this->table(['From Table', 'Column', 'References', 'Constraint Name'], $externalRelations);
            $report['external_relationships'] = $externalRelations;
        } else {
            $this->line("<type>📎 No external relationships found for this table.</type>");
            $report['external_relationships'] = [];
        }

        return $report;
    }

    private function getExternalRelationships(string $targetTable): array
    {
        $manager = Schema::getConnection()->getDoctrineSchemaManager();
        $allTables = $manager->listTableNames();
        $externalRelations = [];

        $this->line("<type>🔍 Scanning for external relationships to table:</type> <value>$targetTable</value>");

        foreach ($allTables as $table) {
            if ($table === $targetTable) continue;

            try {
                $details = $manager->listTableDetails($table);

                // Check if table has foreign keys
                $foreignKeys = $details->getForeignKeys();

                if (!empty($foreignKeys)) {
                    $this->line("<type>  • Checking table:</type> <value>$table</value> <type>(found " . count($foreignKeys) . " foreign keys)</type>");
                }

                foreach ($foreignKeys as $foreign) {
                    $foreignTableName = $foreign->getForeignTableName();

                    if ($foreignTableName === $targetTable) {
                        $localColumns = $foreign->getLocalColumns();
                        $foreignColumns = $foreign->getForeignColumns();

                        $externalRelations[] = [
                            'From Table' => $table,
                            'Column' => implode(', ', $localColumns),
                            'References' => implode(', ', $foreignColumns),
                            'Constraint Name' => $foreign->getName()
                        ];

                        $this->line("<value>    ✅ Found relationship:</value> <key>$table</key>(" . implode(', ', $localColumns) . ") → <key>$targetTable</key>(" . implode(', ', $foreignColumns) . ")");
                    }
                }
            } catch (\Throwable $e) {
                $this->warn("⚠️ <missing>Error scanning table '$table':</missing> " . $e->getMessage());

                // Try alternative method using raw SQL for MySQL
                try {
                    $this->line("<type>  • Trying alternative method for table:</type> <value>$table</value>");
                    $alternativeRelations = $this->getExternalRelationshipsAlternative($table, $targetTable);
                    $externalRelations = array_merge($externalRelations, $alternativeRelations);
                } catch (\Throwable $alternativeE) {
                    $this->warn("⚠️ <missing>Alternative method also failed for table '$table':</missing> " . $alternativeE->getMessage());
                }
                continue;
            }
        }

        if (empty($externalRelations)) {
            $this->line("<type>  • No external relationships found for table:</type> <value>$targetTable</value>");

            // Try to find relationships using raw SQL as backup
            $this->line("<type>🔄 Trying backup method using raw SQL...</type>");
            $externalRelations = $this->getExternalRelationshipsRawSQL($targetTable);
        }

        return $externalRelations;
    }

    /**
     * Alternative method to get external relationships using raw SQL
     */
    private function getExternalRelationshipsAlternative(string $fromTable, string $targetTable): array
    {
        $externalRelations = [];

        try {
            // Get foreign key constraints using raw SQL for MySQL
            $constraints = DB::select("
                SELECT
                    CONSTRAINT_NAME,
                    COLUMN_NAME,
                    REFERENCED_TABLE_NAME,
                    REFERENCED_COLUMN_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND REFERENCED_TABLE_NAME = ?
            ", [$fromTable, $targetTable]);

            foreach ($constraints as $constraint) {
                $externalRelations[] = [
                    'From Table' => $fromTable,
                    'Column' => $constraint->COLUMN_NAME,
                    'References' => $constraint->REFERENCED_COLUMN_NAME,
                    'Constraint Name' => $constraint->CONSTRAINT_NAME
                ];
            }
        } catch (\Throwable $e) {
            $this->warn("⚠️ <missing>Raw SQL alternative method failed:</missing> " . $e->getMessage());
        }

        return $externalRelations;
    }

    /**
     * Backup method to get all external relationships using raw SQL
     */
    private function getExternalRelationshipsRawSQL(string $targetTable): array
    {
        $externalRelations = [];

        try {
            $this->line("<type>  • Using raw SQL to find relationships...</type>");

            // Get all foreign key constraints that reference the target table
            $constraints = DB::select("
                SELECT
                    TABLE_NAME as from_table,
                    COLUMN_NAME as column_name,
                    REFERENCED_TABLE_NAME as referenced_table,
                    REFERENCED_COLUMN_NAME as referenced_column,
                    CONSTRAINT_NAME as constraint_name
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                AND REFERENCED_TABLE_NAME = ?
                AND CONSTRAINT_NAME != 'PRIMARY'
            ", [$targetTable]);

            foreach ($constraints as $constraint) {
                $externalRelations[] = [
                    'From Table' => $constraint->from_table,
                    'Column' => $constraint->column_name,
                    'References' => $constraint->referenced_column,
                    'Constraint Name' => $constraint->constraint_name
                ];

                $this->line("<value>    ✅ Found via SQL:</value> <key>{$constraint->from_table}</key>({$constraint->column_name}) → <key>$targetTable</key>({$constraint->referenced_column})");
            }

            if (empty($constraints)) {
                $this->line("<type>  • No foreign key constraints found in INFORMATION_SCHEMA</type>");
            }
        } catch (\Throwable $e) {
            $this->error("❌ <missing>Raw SQL backup method failed:</missing> " . $e->getMessage());
        }

        return $externalRelations;
    }

    /**
     * Get database type for tailored SQL queries
     */
    private function getDatabaseType(): string
    {
        $driver = config('database.default');
        $connection = config("database.connections.$driver.driver");
        return $connection ?? 'mysql';
    }

    /**
     * Get external relationships using database-specific SQL
     */
    private function getExternalRelationshipsAdvanced(string $targetTable): array
    {
        $dbType = $this->getDatabaseType();
        $externalRelations = [];

        try {
            switch ($dbType) {
                case 'mysql':
                    $query = "
                        SELECT
                            kcu.TABLE_NAME as from_table,
                            kcu.COLUMN_NAME as column_name,
                            kcu.REFERENCED_TABLE_NAME as referenced_table,
                            kcu.REFERENCED_COLUMN_NAME as referenced_column,
                            kcu.CONSTRAINT_NAME as constraint_name,
                            rc.UPDATE_RULE,
                            rc.DELETE_RULE
                        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                        LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                            ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                            AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
                        WHERE kcu.TABLE_SCHEMA = DATABASE()
                        AND kcu.REFERENCED_TABLE_NAME = ?
                        AND kcu.CONSTRAINT_NAME != 'PRIMARY'
                    ";
                    break;

                case 'pgsql':
                    $query = "
                        SELECT
                            tc.table_name as from_table,
                            kcu.column_name as column_name,
                            ccu.table_name as referenced_table,
                            ccu.column_name as referenced_column,
                            tc.constraint_name as constraint_name
                        FROM information_schema.table_constraints tc
                        JOIN information_schema.key_column_usage kcu
                            ON tc.constraint_name = kcu.constraint_name
                        JOIN information_schema.constraint_column_usage ccu
                            ON ccu.constraint_name = tc.constraint_name
                        WHERE tc.constraint_type = 'FOREIGN KEY'
                        AND ccu.table_name = ?
                    ";
                    break;

                default:
                    return $this->getExternalRelationshipsRawSQL($targetTable);
            }

            $constraints = DB::select($query, [$targetTable]);

            foreach ($constraints as $constraint) {
                $relationInfo = [
                    'From Table' => $constraint->from_table,
                    'Column' => $constraint->column_name,
                    'References' => $constraint->referenced_column,
                    'Constraint Name' => $constraint->constraint_name
                ];

                // Add additional info for MySQL
                if ($dbType === 'mysql' && isset($constraint->UPDATE_RULE)) {
                    $relationInfo['Update Rule'] = $constraint->UPDATE_RULE;
                    $relationInfo['Delete Rule'] = $constraint->DELETE_RULE;
                }

                $externalRelations[] = $relationInfo;
            }

        } catch (\Throwable $e) {
            $this->warn("⚠️ <missing>Advanced SQL method failed:</missing> " . $e->getMessage());
            return [];
        }

        return $externalRelations;
    }

    private function getMigrationTable($table)
    {
        $files = glob(base_path('database/migrations/*.php'));
        $tableContent = '';

        foreach ($files as $file) {
            $content = file_get_contents($file);

            if (
                strpos($content, "Schema::create('$table'") !== false ||
                strpos($content, "Schema::table('$table'") !== false
            ) {
                $tableContent .= "\n" . $content;
            }
        }

        return $tableContent ?: null;
    }

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

    private function guessTableFromColumn(string $column): string
    {
        return Str::plural(str_replace('_id', '', $column));
    }

    private function generateReport($report)
    {
        $filename = storage_path('logs/migration_report_' . date('Y_m_d_H_i_s') . '.log');
        File::put($filename, json_encode($report, JSON_PRETTY_PRINT));

        $this->info("\n📁 <key>Report generated at:</key> <value>$filename</value>");

        $issues = array_filter($report, fn($table) => isset($table['status']) && $table['status'] !== 'valid');

        if (!empty($issues)) {
            $this->error("\n<missing>❗ Some issues were found. Check the report for more details.</missing>");
        } else {
            $this->line("\n<value>✅ No issues found. Everything is valid!</value>");
        }

        $this->line("\n<key>📊 Summary:</key>");
        foreach ($report as $table => $details) {
            $status = $details['status'] ?? 'N/A';
            $statusColor = $status === 'valid' ? 'value' : 'missing';
            $this->line("- <key>$table</key>: <$statusColor>$status</$statusColor>");
        }
    }

    /**
     * Enhanced method to check and display detailed external relationships
     */
    private function checkAndDisplayExternalRelationships(string $targetTable): array
    {
        $this->line("\n<key>🔍 Comprehensive External Relationships Analysis for table:</key> <value>$targetTable</value>");

        $externalRelations = [];

        // Method 1: Try Doctrine DBAL first
        $doctrineResults = $this->getExternalRelationships($targetTable);
        if (!empty($doctrineResults)) {
            $this->line("<value>✅ Found " . count($doctrineResults) . " relationships using Doctrine DBAL</value>");
            $externalRelations = array_merge($externalRelations, $doctrineResults);
        }

        // Method 2: Try raw SQL for additional verification
        $sqlResults = $this->getExternalRelationshipsRawSQL($targetTable);
        if (!empty($sqlResults)) {
            $this->line("<value>✅ Found " . count($sqlResults) . " relationships using Raw SQL</value>");

            // Merge and deduplicate
            foreach ($sqlResults as $sqlResult) {
                $isDuplicate = false;
                foreach ($externalRelations as $existing) {
                    if ($existing['From Table'] === $sqlResult['From Table'] &&
                        $existing['Column'] === $sqlResult['Column']) {
                        $isDuplicate = true;
                        break;
                    }
                }
                if (!$isDuplicate) {
                    $externalRelations[] = $sqlResult;
                }
            }
        }

        // Method 3: Check Laravel naming conventions
        $conventionResults = $this->getRelationshipsByConvention($targetTable);
        if (!empty($conventionResults)) {
            $this->line("<value>✅ Found " . count($conventionResults) . " potential relationships using Laravel conventions</value>");
            $externalRelations = array_merge($externalRelations, $conventionResults);
        }

        if (empty($externalRelations)) {
            $this->warn("⚠️ <missing>No external relationships found for table '$targetTable'</missing>");
            $this->line("<type>This could mean:</type>");
            $this->line("<type>• No other tables reference this table</type>");
            $this->line("<type>• Foreign key constraints might not be properly defined</type>");
            $this->line("<type>• The database might be using soft relationships without constraints</type>");
        }

        return $externalRelations;
    }

    /**
     * Find relationships based on Laravel naming conventions
     */
    private function getRelationshipsByConvention(string $targetTable): array
    {
        $relations = [];
        $manager = Schema::getConnection()->getDoctrineSchemaManager();
        $allTables = $manager->listTableNames();

        // Common naming patterns for foreign keys
        $possibleColumnNames = [
            $targetTable . '_id',
            Str::singular($targetTable) . '_id',
            str_replace('s', '', $targetTable) . '_id', // Remove trailing 's'
        ];

        foreach ($allTables as $table) {
            if ($table === $targetTable) continue;

            try {
                $details = $manager->listTableDetails($table);
                $columns = $details->getColumns();

                foreach ($columns as $columnName => $column) {
                    if (in_array($columnName, $possibleColumnNames)) {
                        $relations[] = [
                            'From Table' => $table,
                            'Column' => $columnName,
                            'References' => 'id (assumed)',
                            'Constraint Name' => 'Convention-based (no formal constraint)'
                        ];

                        $this->line("<type>    📋 Convention-based relationship:</type> <key>$table</key>($columnName) → <key>$targetTable</key>(id)");
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $relations;
    }

    /**
     * Enhanced column detection with Laravel Schema Builder methods mapping
     */
    private function isColumnInMigration(string $columnName, string $migrationContent): bool
    {
        // Direct column name check (explicit definition)
        if (strpos($migrationContent, "'$columnName'") !== false) {
            return true;
        }

        // Laravel Schema Builder methods mapping
        $laravelMethods = [
            // Timestamps
            'created_at' => ['timestamps()', 'timestampsTz()'],
            'updated_at' => ['timestamps()', 'timestampsTz()'],

            // Soft deletes
            'deleted_at' => ['softDeletes()', 'softDeletesTz()'],

            // Remember token
            'remember_token' => ['rememberToken()'],

            // ID columns
            'id' => ['id()', 'bigIncrements()', 'increments()'],

            // UUID columns
            'uuid' => ['uuid()'],

            // Email verification
            'email_verified_at' => ['timestamp(\'email_verified_at\')->nullable()'],
        ];

        // Check if column has corresponding Laravel method
        if (isset($laravelMethods[$columnName])) {
            foreach ($laravelMethods[$columnName] as $method) {
                if (strpos($migrationContent, $method) !== false) {
                    return true;
                }
            }
        }

        // Advanced pattern matching for common Laravel patterns
        $patterns = [
            // Foreign keys
            '/\-\>foreign(Id)?\(\s*[\'"]' . preg_quote($columnName, '/') . '[\'"]/',
            '/\-\>unsignedBigInteger\(\s*[\'"]' . preg_quote($columnName, '/') . '[\'"]/',
            '/\-\>unsignedInteger\(\s*[\'"]' . preg_quote($columnName, '/') . '[\'"]/',

            // String/Text columns with specific names
            '/\-\>(string|text|longText|mediumText)\(\s*[\'"]' . preg_quote($columnName, '/') . '[\'"]/',

            // Date/Time columns
            '/\-\>(date|dateTime|time|timestamp)\(\s*[\'"]' . preg_quote($columnName, '/') . '[\'"]/',

            // Numeric columns
            '/\-\>(integer|bigInteger|smallInteger|tinyInteger|decimal|float|double)\(\s*[\'"]' . preg_quote($columnName, '/') . '[\'"]/',

            // Boolean columns
            '/\-\>boolean\(\s*[\'"]' . preg_quote($columnName, '/') . '[\'"]/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $migrationContent)) {
                return true;
            }
        }

        return false;
    }
}
