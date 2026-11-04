<?php

namespace Encore\Admin\Tests\Integration;

use Encore\Admin\Traits\ModelTree;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class TreeIntegrityTest extends TestCase
{
    private $previous;
    private $resolver;
    private $dispatcher;
    private Application $app;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for tree integration tests.');
        }
        $this->previous = Container::getInstance();
        $this->resolver = Model::getConnectionResolver();
        $this->dispatcher = Model::getEventDispatcher();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Repository([
            'app' => ['locale' => 'en', 'fallback_locale' => 'en'],
            'database' => ['default' => 'sqlite', 'connections' => [
                'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            ]],
        ]));
        $this->app->instance('request', Request::create('/menus', 'PUT', ['parent_id' => '0']));
        $this->app->instance('files', new \Illuminate\Filesystem\Filesystem());
        foreach ([\Illuminate\Events\EventServiceProvider::class, \Illuminate\Database\DatabaseServiceProvider::class,
            \Illuminate\Translation\TranslationServiceProvider::class, \Illuminate\Validation\ValidationServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        (new \Illuminate\Database\DatabaseServiceProvider($this->app))->boot();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        IntegrityTreeNode::clearBootedModels();
        $this->app['db']->connection()->getSchemaBuilder()->create('tree_nodes', function ($table) {
            $table->string('id')->primary();
            $table->string('parent_id')->default('0');
            $table->string('title');
            $table->integer('order')->default(0);
        });
        foreach (['parent' => '0', 'child' => 'parent', 'grandchild' => 'child', 'other' => '0'] as $id => $parent) {
            IntegrityTreeNode::create(['id' => $id, 'parent_id' => $parent, 'title' => $id]);
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->app)) return;
        IntegrityTreeNode::flushEventListeners();
        NumericIntegrityTreeNode::flushEventListeners();
        IntegrityTreeNode::clearBootedModels();
        if ($this->resolver) Model::setConnectionResolver($this->resolver);
        else Model::unsetConnectionResolver();
        if ($this->dispatcher) Model::setEventDispatcher($this->dispatcher);
        else Model::unsetEventDispatcher();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previous);
        Container::setInstance($this->previous);
    }

    public function test_reparenting_to_self_or_a_descendant_is_rejected_before_writing(): void
    {
        foreach (['parent', 'child', 'grandchild'] as $parent) {
            try {
                IntegrityTreeNode::findOrFail('parent')->update(['parent_id' => $parent]);
                $this->fail('A cyclic parent was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('parent_id', $exception->errors());
                $this->assertSame('0', IntegrityTreeNode::findOrFail('parent')->parent_id);
            }
        }
    }

    public function test_an_existing_cycle_in_the_proposed_ancestor_chain_is_rejected(): void
    {
        IntegrityTreeNode::query()->whereKey('parent')->update(['parent_id' => 'grandchild']);
        $this->expectException(ValidationException::class);
        IntegrityTreeNode::findOrFail('other')->update(['parent_id' => 'parent']);
    }

    public function test_valid_reparenting_and_returning_to_root_are_allowed(): void
    {
        $node = IntegrityTreeNode::findOrFail('child');
        $this->assertTrue($node->update(['parent_id' => 'other']));
        $this->assertSame('other', $node->fresh()->parent_id);
        $this->assertTrue($node->update(['parent_id' => '0']));
        $this->assertSame('0', $node->fresh()->parent_id);
    }

    private function numericNodes(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->create('numeric_tree_nodes', function ($table) {
            $table->increments('id');
            $table->integer('parent_id')->default(0);
        });
        NumericIntegrityTreeNode::insert([
            ['id' => 1, 'parent_id' => 0], ['id' => 2, 'parent_id' => 0], ['id' => 3, 'parent_id' => 1],
        ]);
    }

    public function test_integer_self_references_are_rejected_using_the_resolved_database_key(): void
    {
        $this->numericNodes();
        foreach (['01', '1.0', '+1', '1e0', '03'] as $parent) {
            try {
                NumericIntegrityTreeNode::findOrFail(1)->update(['parent_id' => $parent]);
                $this->fail('A numeric alias bypassed cycle validation: '.$parent);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('parent_id', $exception->errors());
                $this->assertSame(0, NumericIntegrityTreeNode::findOrFail(1)->parent_id);
            }
        }
    }

    public function test_numeric_aliases_of_valid_parents_remain_supported(): void
    {
        $this->numericNodes();
        $node = NumericIntegrityTreeNode::findOrFail(3);
        $this->assertTrue($node->update(['parent_id' => '02']));
        $this->assertSame(2, $node->fresh()->parent_id);
        $this->assertTrue($node->update(['parent_id' => 0]));
        $this->assertSame(0, $node->fresh()->parent_id);
    }

    public function test_new_nodes_with_explicit_keys_cannot_reference_themselves(): void
    {
        $this->numericNodes();
        foreach ([new IntegrityTreeNode(['id' => 'new', 'parent_id' => 'new', 'title' => 'new']),
            new NumericIntegrityTreeNode(['id' => 4, 'parent_id' => 4])] as $node) {
            try {
                $node->save();
                $this->fail('A new node referencing itself was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('parent_id', $exception->errors());
                $this->assertFalse($node->exists);
            }
        }
    }

    public function test_child_deletion_cancellation_rolls_back_previously_deleted_descendants(): void
    {
        IntegrityTreeNode::deleting(static fn ($node) => $node->getKey() !== 'child');
        $this->assertCancelledDeletionKeepsTree();
    }

    public function test_parent_deletion_cancellation_also_rolls_back_descendants(): void
    {
        IntegrityTreeNode::deleting(static fn ($node) => $node->getKey() !== 'parent');
        $this->assertCancelledDeletionKeepsTree();
    }

    private function assertCancelledDeletionKeepsTree(): void
    {
        try {
            IntegrityTreeNode::findOrFail('parent')->delete();
            $this->fail('A cancelled tree deletion succeeded.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Tree node deletion was cancelled.', $exception->getMessage());
            $this->assertSame(['child', 'grandchild', 'other', 'parent'], IntegrityTreeNode::orderBy('id')->pluck('id')->all());
            $this->assertSame('parent', IntegrityTreeNode::findOrFail('child')->parent_id);
        }
    }

    public function test_successful_deletion_removes_the_whole_subtree_only(): void
    {
        $this->assertTrue(IntegrityTreeNode::findOrFail('parent')->delete());
        $this->assertSame(['other'], IntegrityTreeNode::pluck('id')->all());
    }
}

class IntegrityTreeNode extends Model
{
    use ModelTree;

    protected $table = 'tree_nodes';
    protected $guarded = [];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
}

class NumericIntegrityTreeNode extends IntegrityTreeNode
{
    protected $table = 'numeric_tree_nodes';
    protected $keyType = 'int';
    public $incrementing = true;
}
