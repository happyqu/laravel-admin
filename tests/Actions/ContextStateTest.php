<?php

namespace Encore\Admin\Tests\Actions;

use Encore\Admin\Actions\Action;
use PHPUnit\Framework\TestCase;

class ContextStateTest extends TestCase
{
    public function test_setters_survive_rebuilding_without_a_property_list_or_custom_constructor(): void
    {
        $original = new RuntimeContextChild();
        $original->setSn('SN-001');
        $original->setCallback('App\\Admin\\OrdersController', 'addCost');
        $original->name = '添加金额';
        $rebuilt = new RuntimeContextChild();
        $rebuilt->restoreContext($original->contextState());
        $this->assertSame('SN-001', $rebuilt->sn());
        $this->assertSame(['App\\Admin\\OrdersController', 'addCost'], $rebuilt->callback());
        $this->assertSame('添加金额', $rebuilt->name);
    }

    public function test_parent_and_child_private_properties_with_the_same_name_are_restored_independently(): void
    {
        $original = new RuntimeContextChild();
        $original->setParentValue('parent');
        $original->setChildValue('child');
        $rebuilt = new RuntimeContextChild();
        $rebuilt->restoreContext($original->contextState());
        $this->assertSame('parent', $rebuilt->parentValue());
        $this->assertSame('child', $rebuilt->childValue());
    }

    public function test_zero_false_null_arrays_and_uninitialized_typed_properties_are_handled(): void
    {
        $original = new RuntimeContextChild();
        $original->setValues(['zero' => 0, 'false' => false, 'null' => null, 'nested' => ['0', '']]);
        $rebuilt = new RuntimeContextChild();
        $rebuilt->restoreContext($original->contextState());
        $this->assertSame($original->values(), $rebuilt->values());
    }

    public function test_objects_resources_and_framework_state_are_not_collected(): void
    {
        $original = new RuntimeContextChild();
        $original->service = new \stdClass();
        $original->setValues(['model' => new \stdClass()]);
        $original->selectorPrefix = '.should-not-be-restored';
        $original->response();
        $state = $original->contextState();
        $this->assertSame([], $state);
    }

    public function test_existing_explicit_parent_private_configuration_remains_supported(): void
    {
        $original = new ExplicitRuntimeContextChild();
        $original->setCallback('App\\Admin\\OrdersController', 'addCost');
        $original->setSn('SN-002');
        $state = $original->contextState();
        $this->assertSame(['App\\Admin\\OrdersController', 'addCost'], $state['callback']);
        $rebuilt = new ExplicitRuntimeContextChild();
        $rebuilt->restoreContext($state);
        $this->assertSame($original->callback(), $rebuilt->callback());
        $this->assertSame('SN-002', $rebuilt->sn());
    }

    public function test_state_remains_bounded(): void
    {
        $original = new RuntimeContextChild();
        $original->setSn(str_repeat('x', 4097));
        $this->expectException(\LogicException::class);
        $original->contextState();
    }

    public function test_constructor_values_are_rebuilt_instead_of_being_saved(): void
    {
        $this->assertSame([], (new RuntimeConstructedContextAction())->contextState());
    }

    public function test_setters_can_reset_constructor_values_to_null_false_and_zero(): void
    {
        $original = new RuntimeConstructedContextAction();
        $original->setSn(null);
        $original->setEnabled(false);
        $original->setCount(0);
        $state = $original->contextState();
        $this->assertCount(3, $state);
        $rebuilt = new RuntimeConstructedContextAction();
        $rebuilt->restoreContext($state);
        $this->assertSame([null, false, 0], $rebuilt->values());
    }

    public function test_constructor_baseline_uses_the_same_container_binding_as_resolution(): void
    {
        $container = app();
        $container->bind(RuntimeContainerContextAction::class, fn () => new RuntimeContainerContextAction('factory-sn'));
        try {
            $original = $container->make(RuntimeContainerContextAction::class);
            $original->setSn(null);
            $state = $original->contextState();
            $rebuilt = $container->make(RuntimeContainerContextAction::class);
            $rebuilt->restoreContext($state);
            $this->assertSame([null], $rebuilt->values());
        } finally {
            $container->offsetUnset(RuntimeContainerContextAction::class);
        }
    }
}

class RuntimeContextParent extends Action
{
    protected $configuration = 'parent default';
    private $callback;
    private $value;
    public function setCallback($class, $method) { $this->callback = [$class, $method]; }
    public function callback() { return $this->callback; }
    public function setParentValue($value) { $this->value = $value; }
    public function parentValue() { return $this->value; }
}

class RuntimeContextChild extends RuntimeContextParent
{
    protected $configuration = 'child default';
    public $name = 'Run';
    private $sn;
    private $value;
    protected $values;
    public $service;
    public string $uninitialized;
    public function setSn($sn) { $this->sn = $sn; }
    public function sn() { return $this->sn; }
    public function setChildValue($value) { $this->value = $value; }
    public function childValue() { return $this->value; }
    public function setValues($values) { $this->values = $values; }
    public function values() { return $this->values; }
}

class ExplicitRuntimeContextChild extends RuntimeContextChild
{
    protected $contextProperties = ['callback'];
}

class RuntimeConstructedContextAction extends Action
{
    private $sn = null;
    private $enabled = false;
    private $count = 0;
    public function __construct()
    {
        parent::__construct();
        $this->sn = 'constructor-sn';
        $this->enabled = true;
        $this->count = 42;
    }
    public function setSn($sn) { $this->sn = $sn; }
    public function setEnabled($enabled) { $this->enabled = $enabled; }
    public function setCount($count) { $this->count = $count; }
    public function values() { return [$this->sn, $this->enabled, $this->count]; }
}

class RuntimeContainerContextAction extends Action
{
    private $sn = null;
    public function __construct(string $sn)
    {
        parent::__construct();
        $this->sn = $sn;
    }
    public function setSn($sn) { $this->sn = $sn; }
    public function values() { return [$this->sn]; }
}
