<?php

namespace Encore\Admin\Tests\Middleware;

use Encore\Admin\Middleware\LogOperation;
use PHPUnit\Framework\TestCase;

class LogOperationTest extends TestCase
{
    public function test_credentials_are_redacted_recursively(): void
    {
        $middleware = new class extends LogOperation {
            public function redactForTest(array $input): array
            {
                return $this->redact($input);
            }
        };

        $result = $middleware->redactForTest([
            'username' => 'admin',
            'password' => 'secret',
            'nested' => [
                'password_confirmation' => 'secret',
                'api_key' => 'key',
                'safe' => 'value',
            ],
        ]);

        $this->assertSame('admin', $result['username']);
        $this->assertSame('[REDACTED]', $result['password']);
        $this->assertSame('[REDACTED]', $result['nested']['password_confirmation']);
        $this->assertSame('[REDACTED]', $result['nested']['api_key']);
        $this->assertSame('value', $result['nested']['safe']);
    }
}
