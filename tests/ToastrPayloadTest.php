<?php

namespace Encore\Admin\Tests;

use Illuminate\Support\MessageBag;
use PHPUnit\Framework\TestCase;

class ToastrPayloadTest extends TestCase
{
    public function test_array_payload_is_passed_through(): void
    {
        $payload = admin_toastr_payload([
            'type' => 'warning',
            'message' => 'Saved',
            'options' => ['timeOut' => 2000],
        ]);

        $this->assertSame('warning', $payload['type']);
        $this->assertSame('Saved', $payload['message']);
        $this->assertSame(['timeOut' => 2000], $payload['options']);
    }

    public function test_message_bag_payload_is_normalized(): void
    {
        $payload = admin_toastr_payload(new MessageBag([
            'type' => 'success',
            'message' => 'Done',
        ]));

        $this->assertSame('success', $payload['type']);
        $this->assertSame('Done', $payload['message']);
        $this->assertSame([], $payload['options']);
    }

    public function test_scalar_message_bag_values_are_normalized(): void
    {
        $payload = admin_toastr_payload(new MessageBag([
            'type' => ['error'],
            'message' => ['Boom'],
        ]));

        $this->assertSame('error', $payload['type']);
        $this->assertSame('Boom', $payload['message']);
    }

    public function test_invalid_payload_falls_back_to_defaults(): void
    {
        $payload = admin_toastr_payload('not-an-array');

        $this->assertSame('success', $payload['type']);
        $this->assertSame('', $payload['message']);
        $this->assertSame([], $payload['options']);
    }
}
