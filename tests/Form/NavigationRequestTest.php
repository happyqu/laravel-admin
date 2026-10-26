<?php

namespace Encore\Admin\Tests\Form;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class NavigationRequestTest extends TestCase
{
    public function test_navigation_posts_keep_html_validation_and_redirect_responses(): void
    {
        $request = Request::create('/admin/users', 'POST', ['name' => 'Alice'], [], [], [
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_X_ADMIN_NAVIGATION' => 'true',
        ]);

        $this->assertTrue($request->ajax());
        // Form::ajaxResponse and ValidationResponse must retain their HTML paths.
        $this->assertFalse($request->expectsJson());
    }

    public function test_inline_ajax_requests_can_still_negotiate_json(): void
    {
        $request = Request::create('/admin/users/1', 'POST', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        $this->assertTrue($request->expectsJson());
    }
}
