<?php

namespace Encore\Admin\Tests\Actions;

use Encore\Admin\Actions\Response;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    public function test_success_clears_a_previous_error_status(): void
    {
        $response = (new Response())->toastr()->error('Failed');
        $this->assertFalse($response->status);

        $response->success('Saved')->refresh();

        $this->assertTrue($response->status);
        $this->assertSame('success', $response->getPlugin()->getOptions()['toastr']['type']);
    }

    public function test_error_after_success_still_marks_the_response_as_failed(): void
    {
        $response = (new Response())->toastr()->success('Saved')->error('Failed');

        $this->assertFalse($response->status);
        $this->assertSame('error', $response->getPlugin()->getOptions()['toastr']['type']);
    }

    public function test_a_swal_without_content_does_not_leak_an_empty_dialog(): void
    {
        $response = (new Response())->swal();

        $this->assertSame([], $response->getPlugin()->getOptions());
    }

    public function test_a_swal_message_is_kept(): void
    {
        $response = (new Response())->swal()->error('报告尚未完成，无法生成PDF报告');

        $this->assertSame(
            ['swal' => ['type' => 'error', 'title' => '报告尚未完成，无法生成PDF报告']],
            $response->getPlugin()->getOptions()
        );
    }

    public function test_a_dialog_response_can_be_built_without_a_notification(): void
    {
        $response = (new Response())->refresh();

        $this->assertNull($response->getPlugin());
        $this->assertArrayNotHasKey('swal', $response->getPlugin()?->getOptions() ?? []);
    }

    public function test_a_bare_response_can_still_report_a_message(): void
    {
        $response = (new Response())->success('Saved');

        $this->assertTrue($response->status);
        $this->assertSame('success', $response->getPlugin()->getOptions()['toastr']['type']);
    }

    public function test_positioning_a_bare_response_does_not_fail(): void
    {
        $response = (new Response())->topCenter()->warning('Careful');

        $this->assertSame('warning', $response->getPlugin()->getOptions()['toastr']['type']);
        $this->assertSame('toast-top-center', $response->getPlugin()->getOptions()['toastr']['options']['positionClass']);
    }
}
