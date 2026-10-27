<?php

namespace Encore\Admin\Tests\Actions;

use Encore\Admin\Actions\Action;
use PHPUnit\Framework\TestCase;

class ActionRenderTest extends TestCase
{
    public function test_attached_attributes_keep_the_action_wrapper(): void
    {
        $action = new class extends Action {
            public function expose(string $content): string
            {
                return $this->attachAttributes($content);
            }
        };

        $action->attribute('data-admin-action', 'ticket');

        $result = $action->expose(
            '<li class="nav-item"><a class="clear-cache" href="#"><i class="fa fa-trash"></i><span>清理缓存</span></a></li>'
        );

        $this->assertStringContainsString('<li class="nav-item">', $result);
        $this->assertStringContainsString('data-admin-action="ticket"', $result);
        $this->assertStringContainsString('清理缓存', $result);
    }

    public function test_attached_attributes_keep_anchor_only_fragments(): void
    {
        $action = new class extends Action {
            public function expose(string $content): string
            {
                return $this->attachAttributes($content);
            }
        };

        $action->attribute('data-admin-action', 'ticket');

        $result = trim($action->expose('<a class="grid-row-action" href="#">Run</a>'));

        $this->assertSame('<a class="grid-row-action" href="#" data-admin-action="ticket">Run</a>', $result);
    }
}
