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
}
