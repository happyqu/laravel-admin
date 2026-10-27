<?php

namespace Encore\Admin\Tests\Layout;

use Encore\Admin\Layout\Column;
use Encore\Admin\Layout\Content;
use Encore\Admin\Layout\Row;
use Illuminate\Contracts\Support\Renderable;
use PHPUnit\Framework\TestCase;

class LayoutTest extends TestCase
{
    public function test_zero_content_is_rendered(): void
    {
        $this->assertStringContainsString('>0</div>', (new Row(0))->render());
        $this->assertStringContainsString('>0</div>', (new Content())->body('0')->build());
    }

    public function test_row_class_cannot_inject_attributes(): void
    {
        $html = (new Row('content'))->class('custom" onclick="alert(1)')->render();
        $dom = new \DOMDocument();
        $dom->loadHTML($html);
        $this->assertFalse($dom->getElementsByTagName('div')->item(0)->hasAttribute('onclick'));
    }

    public function test_widths_support_null_fractions_and_responsive_maps(): void
    {
        $row = new Row();
        $row->column(null, 'full');
        $row->column(0.5, 'half');
        $row->column(['xs' => 12, 'md' => 0.5, 'md-offset' => 0], 'responsive');
        $html = $row->render();
        $this->assertStringContainsString('class="col-md-12"', $html);
        $this->assertStringContainsString('class="col-md-6"', $html);
        $this->assertStringContainsString('class="col-xs-12 col-md-6 col-md-offset-0"', $html);
    }

    public function test_invalid_widths_are_rejected_before_building_content(): void
    {
        foreach ([0, -1, 13, 1.5, 'invalid', ['md" onclick="x' => 6]] as $width) {
            try {
                new Column(function () { $this->fail('Invalid width executed content.'); }, $width);
                $this->fail('Invalid width accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_nested_rows_are_deferred_and_buildable_columns_can_be_embedded(): void
    {
        $child = new class implements Renderable {
            public string $text = 'before';
            public int $renders = 0;
            public function render() { $this->renders++; return $this->text; }
        };
        $column = new Column('');
        $column->row($child);
        $this->assertSame(0, $child->renders);
        $child->text = 'after';
        $html = (new Content())->body($column)->build();
        $this->assertStringContainsString('after', $html);
        $this->assertSame(1, $child->renders);
    }

    public function test_existing_rows_are_not_wrapped_in_another_row(): void
    {
        $html = (new Content())->row(new Row('content'))->build();
        $this->assertSame(1, substr_count($html, 'class="row"'));
    }

    public function test_render_failures_restore_all_output_buffers(): void
    {
        foreach ([new Row(), new Content()] as $layout) {
            $child = new class implements Renderable {
                public function render() { ob_start(); echo 'partial'; throw new \RuntimeException('failed'); }
            };
            if ($layout instanceof Row) {
                $layout->column(12, $child);
            } else {
                $layout->body($child);
            }
            $level = ob_get_level();
            try {
                $layout instanceof Row ? $layout->render() : $layout->build();
                $this->fail('Exception was swallowed.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('failed', $exception->getMessage());
                $this->assertSame($level, ob_get_level());
            }
        }
    }

    public function test_view_mode_does_not_render_unused_rows(): void
    {
        $previous = \Illuminate\Container\Container::getInstance();
        $container = new \Illuminate\Container\Container();
        $container->instance(\Illuminate\Contracts\View\Factory::class, new class {
            public function make($view, $items, $merge = []) {
                return new class($items) {
                    public function __construct(private array $items) {}
                    public function render() { return $this->items['_content_'] === '' ? 'view' : 'rows'; }
                };
            }
        });
        \Illuminate\Container\Container::setInstance($container);
        try {
            $content = new class extends Content {
                public function build() { throw new \RuntimeException('Unused rows rendered.'); }
                protected function getUserData() { return []; }
            };
            $this->assertSame('view', $content->view('custom')->render());
        } finally {
            \Illuminate\Container\Container::setInstance($previous);
        }
    }
}
