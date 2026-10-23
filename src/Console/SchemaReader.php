<?php

namespace Encore\Admin\Console;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads table columns without an external schema package.
 *
 * Laravel 10.50+, 11, 12 and 13 expose Schema::getColumns(). Older Laravel 10
 * releases do not, so the common drivers are inspected with their native
 * metadata queries. Unsupported drivers degrade to column names only.
 */
class SchemaReader
{
    public function __construct(private Model $model)
    {
    }

    /**
     * @return array<int, array{name:string,type:string,default:mixed,nullable:bool,auto_increment:bool}>
     */
    public function columns(): array
    {
        $connection = $this->model->getConnection();
        $builder = $connection->getSchemaBuilder();

        if (method_exists($builder, 'getColumns')) {
            return $this->normalizeNativeColumns($builder->getColumns($this->model->getTable()));
        }

        return $this->readLegacyColumns($connection);
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     */
    private function normalizeNativeColumns(array $columns): array
    {
        $result = [];

        foreach ($columns as $column) {
            $result[] = [
                'name' => (string) ($column['name'] ?? ''),
                'type' => $this->normalizeType((string) ($column['type_name'] ?? $column['type'] ?? 'string')),
                'default' => $column['default'] ?? null,
                'nullable' => (bool) ($column['nullable'] ?? false),
                'auto_increment' => (bool) ($column['auto_increment'] ?? false),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{name:string,type:string,default:mixed,nullable:bool,auto_increment:bool}>
     */
    private function readLegacyColumns(Connection $connection): array
    {
        $table = $connection->getTablePrefix().$this->model->getTable();
        $driver = $connection->getDriverName();

        try {
            return match ($driver) {
                'mysql', 'mariadb' => $this->readMySqlColumns($connection, $this->model->getTable()),
                'pgsql' => $this->readPostgresColumns($connection, $table),
                'sqlite' => $this->readSqliteColumns($connection, $this->model->getTable()),
                'sqlsrv' => $this->readSqlServerColumns($connection, $table),
                default => $this->readColumnNamesOnly($connection),
            };
        } catch (\Throwable $exception) {
            report($exception);

            return $this->readColumnNamesOnly($connection);
        }
    }

    private function readMySqlColumns(Connection $connection, string $table): array
    {
        $wrapped = $connection->getQueryGrammar()->wrapTable($table);
        $columns = [];

        foreach ($connection->select('SHOW FULL COLUMNS FROM '.$wrapped) as $column) {
            $extra = strtolower((string) ($column->Extra ?? ''));

            $columns[] = [
                'name' => (string) $column->Field,
                'type' => $this->normalizeType((string) $column->Type),
                'default' => $column->Default ?? null,
                'nullable' => strtoupper((string) ($column->Null ?? 'NO')) === 'YES',
                'auto_increment' => str_contains($extra, 'auto_increment'),
            ];
        }

        return $columns;
    }

    private function readPostgresColumns(Connection $connection, string $table): array
    {
        [$schema, $tableName] = $this->splitSchema($table);
        $schema = $schema ?: 'public';

        $rows = $connection->select(
            'select column_name as name, data_type as type, column_default as "default", is_nullable, is_identity
             from information_schema.columns
             where table_schema = ? and table_name = ?
             order by ordinal_position',
            [$schema, $tableName]
        );

        $columns = [];

        foreach ($rows as $row) {
            $default = $row->default ?? null;

            $columns[] = [
                'name' => (string) $row->name,
                'type' => $this->normalizeType((string) $row->type),
                'default' => $default,
                'nullable' => strtoupper((string) $row->is_nullable) === 'YES',
                'auto_increment' => strtoupper((string) ($row->is_identity ?? '')) === 'YES'
                    || ($default !== null && str_contains((string) $default, 'nextval(')),
            ];
        }

        return $columns;
    }

    private function readSqliteColumns(Connection $connection, string $table): array
    {
        $wrapped = $connection->getQueryGrammar()->wrapTable($table);
        $columns = [];

        foreach ($connection->select('PRAGMA table_info('.$wrapped.')') as $column) {
            $type = $this->normalizeType((string) $column->type);

            $columns[] = [
                'name' => (string) $column->name,
                'type' => $type,
                'default' => $column->dflt_value ?? null,
                'nullable' => !(bool) ($column->notnull ?? false),
                'auto_increment' => (bool) ($column->pk ?? false) && $type === 'integer',
            ];
        }

        return $columns;
    }

    private function readSqlServerColumns(Connection $connection, string $table): array
    {
        [$schema, $tableName] = $this->splitSchema($table);
        $schema = $schema ?: 'dbo';

        $rows = $connection->select(
            'select column_name as name, data_type as type, column_default as "default", is_nullable
             from information_schema.columns
             where table_schema = ? and table_name = ?
             order by ordinal_position',
            [$schema, $tableName]
        );

        $columns = [];

        foreach ($rows as $row) {
            $columns[] = [
                'name' => (string) $row->name,
                'type' => $this->normalizeType((string) $row->type),
                'default' => $row->default ?? null,
                'nullable' => strtoupper((string) $row->is_nullable) === 'YES',
                'auto_increment' => $row->default !== null && str_contains((string) $row->default, 'next value for'),
            ];
        }

        return $columns;
    }

    private function readColumnNamesOnly(Connection $connection): array
    {
        $names = $connection->getSchemaBuilder()->getColumnListing($this->model->getTable());
        $columns = [];

        foreach ($names as $name) {
            $columns[] = [
                'name' => (string) $name,
                'type' => 'text',
                'default' => null,
                'nullable' => true,
                'auto_increment' => false,
            ];
        }

        return $columns;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitSchema(string $table): array
    {
        if (!str_contains($table, '.')) {
            return ['', $table];
        }

        [$schema, $table] = explode('.', $table, 2);

        return [$schema, $table];
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        if (preg_match('/^(?:tinyint|bit)\s*\(\s*1\s*\)/', $type)) {
            return 'boolean';
        }

        $type = preg_replace('/\s*\(.*$/', '', $type) ?? $type;
        $type = preg_replace('/\s+(unsigned|zerofill).*$/', '', $type) ?? $type;

        return match ($type) {
            'int', 'integer', 'mediumint', 'serial' => 'integer',
            'bigint', 'bigserial' => 'bigint',
            'smallint', 'tinyint' => 'smallint',
            'varchar', 'nvarchar', 'char', 'nchar', 'character varying', 'character', 'uuid' => 'string',
            'text', 'longtext', 'mediumtext', 'tinytext', 'ntext' => 'text',
            'blob', 'longblob', 'mediumblob', 'tinyblob', 'bytea' => 'blob',
            'json', 'jsonb' => 'json',
            'decimal', 'numeric', 'money' => 'decimal',
            'float', 'double', 'double precision', 'real' => 'float',
            'bool', 'boolean' => 'boolean',
            'date' => 'date',
            'datetime', 'timestamp', 'timestamp without time zone', 'timestamp with time zone' => 'datetime',
            'time', 'time without time zone', 'time with time zone' => 'time',
            default => $type,
        };
    }
}
