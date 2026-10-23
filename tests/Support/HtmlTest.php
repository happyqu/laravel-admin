<?php

namespace Encore\Admin\Tests\Support;

use Encore\Admin\Support\Html;
use PHPUnit\Framework\TestCase;

class HtmlTest extends TestCase
{
    public function test_title(): void
    {
        $this->assertSame('Admin', Html::title('<html><head><title>Admin</title></head><body></body></html>'));
    }

    public function test_first_with_id_class_and_comma_selector(): void
    {
        $html = '<div id="box"><a class="grid-row-view" href="/users/1">View</a><button>Go</button></div>';

        $link = Html::first($html, 'a.grid-row-view');
        $this->assertNotNull($link);
        $this->assertSame('/users/1', $link->getAttribute('href'));

        $button = Html::first($html, 'a,button');
        $this->assertNotNull($button);
        $this->assertSame('a', $button->tagName);

        $box = Html::first($html, '#box');
        $this->assertNotNull($box);
        $this->assertStringContainsString('grid-row-view', Html::innerHtml($box));
    }

    public function test_outer_html_preserves_attributes(): void
    {
        $node = Html::first('<a href="#" class="x">Go</a>', '.x');
        $this->assertNotNull($node);
        $node->setAttribute('data-test', '1');

        $this->assertSame('<a href="#" class="x" data-test="1">Go</a>', trim(Html::outerHtml($node)));
    }

    public function test_unsupported_selector_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Html::first('<div></div>', 'div > span');
    }
}
