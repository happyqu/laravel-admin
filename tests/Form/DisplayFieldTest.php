<?php

namespace Encore\Admin\Tests;

use Encore\Admin\Form\Field\Display;
use PHPUnit\Framework\TestCase;

class DisplayFieldTest extends TestCase
{
    public function test_value_is_escaped_by_default(): void
    {
        $this->assertTrue((new Display('name'))->isEscaped());
    }

    public function test_unescape_opts_out(): void
    {
        $field = new Display('name');

        $this->assertFalse($field->unescape()->isEscaped());
    }
}
