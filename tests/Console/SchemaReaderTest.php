<?php

namespace Encore\Admin\Tests\Console;

use Encore\Admin\Console\SchemaReader;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

class SchemaReaderTest extends TestCase
{
    private static bool $booted = false;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The pdo_sqlite extension is not available.');
        }

        if (!self::$booted) {
            $capsule = new Capsule();
            $capsule->addConnection([
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
            $capsule->setAsGlobal();
            $capsule->bootEloquent();

            $capsule->schema()->create('schema_reader_samples', function ($table) {
                $table->increments('id');
                $table->string('name');
                $table->boolean('active')->default(false);
                $table->text('notes')->nullable();
            });

            self::$booted = true;
        }
    }

    public function test_reads_native_sqlite_columns(): void
    {
        $model = new class extends Model {
            protected $table = 'schema_reader_samples';
            protected $guarded = [];
        };

        $columns = (new SchemaReader($model))->columns();
        $byName = array_column($columns, null, 'name');

        $this->assertArrayHasKey('id', $byName);
        $this->assertArrayHasKey('name', $byName);
        $this->assertSame('integer', $byName['id']['type']);
        $this->assertTrue($byName['id']['auto_increment']);
        $this->assertTrue($byName['notes']['nullable']);
        $this->assertSame('text', $byName['notes']['type']);
    }
}
