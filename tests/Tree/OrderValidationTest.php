<?php

namespace Encore\Admin\Tests\Tree;

use Encore\Admin\Traits\ModelTree;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

class OrderValidationTest extends TestCase
{
    protected function setUp(): void
    {
        OrderNode::$writes = [];
        OrderNode::$nodes = [];
        foreach ([1, 2, 3, 'node-a', 'node-b'] as $id) {
            $node = (new OrderNode())->forceFill(['id' => $id, 'parent_id' => 0]);
            if (is_string($id)) $node->setKeyType('string');
            OrderNode::$nodes[$id] = $node;
        }
    }

    public function test_duplicate_or_malformed_nodes_are_rejected_before_any_write(): void
    {
        foreach ([
            [['id' => 1, 'children' => [['id' => 2, 'children' => [['id' => 1]]]]]],
            [['id' => 1], ['id' => '1']],
            [['id' => 1], ['id' => 2, 'children' => [['id' => 1]]]],
            [['id' => 1], ['id' => 2, 'children' => 'invalid']],
            [['id' => 1], ['id' => ['invalid']]],
            [['id' => 1], ['children' => []]],
            [['id' => 0]],
        ] as $tree) {
            try {
                OrderNode::saveOrder($tree);
                $this->fail('Invalid tree was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame([], OrderNode::$writes);
                $this->assertSame(0, OrderNode::$nodes[1]->parent_id);
            }
        }
    }

    public function test_valid_orders_save_each_node_once_in_depth_first_order(): void
    {
        OrderNode::saveOrder([['id' => 2, 'children' => [['id' => 3]]], ['id' => 1]]);
        $this->assertSame([[2, 0, 1], [3, 2, 2], [1, 0, 3]], OrderNode::$writes);
    }

    public function test_string_identifiers_and_partial_subtrees_are_supported(): void
    {
        OrderNode::saveOrder([['id' => 'node-a', 'children' => [['id' => 'node-b']]]], 3);
        $this->assertSame([['node-a', 3, 1], ['node-b', 'node-a', 2]], OrderNode::$writes);
        OrderNode::$writes = [];
        try {
            OrderNode::saveOrder([['id' => 'node-a', 'children' => [['id' => 3]]]], 3);
            $this->fail('A parent was added as its own descendant.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame([], OrderNode::$writes);
        }
    }
}

class OrderNode extends Model
{
    use ModelTree;

    public static array $nodes = [];
    public static array $writes = [];

    protected static function boot() { parent::boot(); }
    public static function findOrFail($id, $columns = ['*']) { return static::$nodes[$id]; }
    public function save(array $options = [])
    {
        static::$writes[] = [$this->getKey(), $this->parent_id, $this->order];
        return true;
    }
}
