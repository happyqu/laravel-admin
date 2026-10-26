<?php

namespace Encore\Admin\Tests;

use Encore\Admin\Form;
use Encore\Admin\Form\Field;
use PHPUnit\Framework\TestCase;

class FieldRegistryTest extends TestCase
{
    public function test_all_builtin_field_classes_are_present(): void
    {
        $files = glob(__DIR__.'/../src/Form/Field/*.php') ?: [];

        $this->assertCount(65, $files, 'The built-in field class set changed unexpectedly.');
    }

    public function test_every_registered_alias_resolves_to_a_field_class(): void
    {
        foreach (Form::$availableFields as $alias => $class) {
            $this->assertTrue(class_exists($class), "Field alias [{$alias}] points to a missing class [{$class}].");
            $this->assertTrue(is_subclass_of($class, Field::class), "Field alias [{$alias}] is not a Field subclass.");
        }
    }
}
