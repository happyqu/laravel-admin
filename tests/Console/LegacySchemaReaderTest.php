<?php

namespace Encore\Admin\Tests\Console;

use Encore\Admin\Console\SchemaReader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use PHPUnit\Framework\TestCase;

class LegacySchemaReaderTest extends TestCase
{
    public function test_mysql_metadata_query_adds_table_prefix_once(): void
    {
        $connection = $this->getMockBuilder(MySqlConnection::class)
            ->setConstructorArgs([null, 'admin', 'app_', ['driver' => 'mysql']])
            ->onlyMethods(['select'])
            ->getMock();

        $connection->expects($this->once())->method('select')
            ->with('SHOW FULL COLUMNS FROM `app_users`')
            ->willReturn([(object) [
                'Field' => 'quota', 'Type' => 'int', 'Default' => 10,
                'Null' => 'NO', 'Extra' => '',
            ]]);

        $columns = $this->legacyColumns($connection);

        $this->assertSame('integer', $columns[0]['type']);
        $this->assertSame(10, $columns[0]['default']);
    }

    public function test_sqlite_metadata_query_adds_table_prefix_once(): void
    {
        $connection = $this->getMockBuilder(SQLiteConnection::class)
            ->setConstructorArgs([null, ':memory:', 'app_', ['driver' => 'sqlite']])
            ->onlyMethods(['select'])
            ->getMock();

        $connection->expects($this->once())->method('select')
            ->with('PRAGMA table_info("app_users")')
            ->willReturn([(object) [
                'name' => 'quota', 'type' => 'integer', 'dflt_value' => '10',
                'notnull' => 1, 'pk' => 0,
            ]]);

        $columns = $this->legacyColumns($connection);

        $this->assertSame('integer', $columns[0]['type']);
        $this->assertSame('10', $columns[0]['default']);
    }

    private function legacyColumns($connection): array
    {
        $model = new class extends Model {
            protected $table = 'users';
        };

        // Exercise the fallback even when the installed Laravel has getColumns().
        $method = new \ReflectionMethod(SchemaReader::class, 'readLegacyColumns');

        return $method->invoke(new SchemaReader($model), $connection);
    }
}
