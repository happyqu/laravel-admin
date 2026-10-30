<?php

namespace Encore\Admin\Tests\Cascader;

use Encore\Admin\Cascader\ModelSource;
use Encore\Admin\Cascader\Source;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use PHPUnit\Framework\TestCase;

class ModelSourceTest extends TestCase
{
    private $resolver;
    private $container;
    private array $queries = [];

    protected function setUp(): void
    {
        $this->resolver = Model::getConnectionResolver();
        $this->container = Container::getInstance();
        Container::setInstance(new Container());
        // Use the real query compiler and Eloquent hydration, with an in-memory result set.
        $connection = new class($this) extends MySqlConnection {
            public function __construct(private ModelSourceTest $test) { parent::__construct(static fn () => null, 'test'); }
            public function select($query, $bindings = [], $useReadPdo = true) { return $this->test->selectRows($query, $bindings); }
        };
        $resolver = new ConnectionResolver(['test' => $connection]);
        $resolver->setDefaultConnection('test');
        Model::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        $this->resolver ? Model::setConnectionResolver($this->resolver) : Model::unsetConnectionResolver();
        Container::setInstance($this->container);
    }

    public function selectRows(string $sql, array $bindings): array
    {
        $this->queries[] = [$sql, $bindings];
        $rows = str_contains($sql, '`provinces`')
            ? [['id' => 0, 'name' => 'Province', 'tenant_id' => 1], ['id' => 3, 'name' => 'Other', 'tenant_id' => 2]]
            : [['id' => 10, 'code' => 'city-a', 'name' => 'City A', 'province_id' => 0, 'tenant_id' => 1],
                ['id' => 11, 'code' => 'city-b', 'name' => 'City B', 'province_id' => 3, 'tenant_id' => 2]];
        preg_match_all('/`(\w+)` (=|like) \?/', $sql, $conditions, PREG_SET_ORDER);
        foreach ($conditions as $index => $condition) {
            $rows = array_values(array_filter($rows, static fn ($row) => $condition[2] === '='
                ? (string) $row[$condition[1]] === (string) $bindings[$index]
                : str_contains($row[$condition[1]], trim($bindings[$index], '%'))));
        }
        if (preg_match('/limit (\d+)/', $sql, $limit)) {
            preg_match('/offset (\d+)/', $sql, $offset);
            $rows = array_slice($rows, (int) ($offset[1] ?? 0), (int) $limit[1]);
        }

        return array_map(static fn ($row) => (object) $row, $rows);
    }

    public function test_cross_model_children_custom_business_key_and_single_id_resolution_use_scopes(): void
    {
        $source = Source::make(RegionModels::class, ['tenant_id' => 1]);
        $result = $source->children(['0'], 'City A');
        $this->assertSame([['value' => 'city-a', 'label' => 'City A', 'leaf' => true, 'disabled' => false]], $result['items']);
        $this->assertSame(['0', 'city-a'], array_column($source->validate('city-a'), 'value'));
        $this->assertSame(['0', 'city-a'], array_column($source->validate(['0', 'city-a']), 'value'));
        $this->assertStringContainsString('`province_id` = ?', $this->queries[1][0]);
        $this->assertStringContainsString('`tenant_id` = ?', $this->queries[1][0]);
        $this->expectException(\InvalidArgumentException::class);
        $source->validate('city-b');
    }

    public function test_wrong_parent_and_revoked_tenant_are_rejected(): void
    {
        $source = Source::make(RegionModels::class, ['tenant_id' => 1]);
        foreach ([['0', 'city-b'], ['3', 'city-a']] as $path) {
            try {
                $source->validate($path);
                $this->fail('Wrong parent was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        $source = Source::make(RegionModels::class, ['tenant_id' => 2]);
        $this->expectException(\InvalidArgumentException::class);
        $source->validate(['0', 'city-a']);
    }
}

class ProvinceModel extends Model { protected $table = 'provinces'; }
class CityModel extends Model { protected $table = 'cities'; }
class RegionModels extends ModelSource
{
    protected function levels(): array
    {
        return [['model' => ProvinceModel::class], ['model' => CityModel::class, 'value' => 'code', 'parent' => 'province_id']];
    }
    protected function query(int $level, array $path): Builder
    {
        return parent::query($level, $path)->where('tenant_id', $this->context['tenant_id']);
    }
}
