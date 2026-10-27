<?php

namespace Encore\Admin\Tests\Middleware;

use Encore\Admin\Middleware\LogOperation;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class LogOperationTest extends TestCase
{
    public function test_read_only_component_endpoints_are_internal_but_action_execution_is_not(): void
    {
        $middleware = new class extends LogOperation {
            public function isInternalForTest(Request $request): bool
            {
                return $this->isInternalEndpoint($request);
            }
        };

        foreach ([
            'admin/_load_action_',
            'admin/_handle_selectable_',
            'admin/_handle_renderable_',
        ] as $path) {
            $this->assertTrue(
                $middleware->isInternalForTest(Request::create($path, 'POST')),
                "expected {$path} to be treated as an internal endpoint"
            );
        }

        $this->assertFalse($middleware->isInternalForTest(Request::create('admin/auth/users', 'POST')));
        $this->assertFalse($middleware->isInternalForTest(Request::create('admin/orders/1', 'PUT')));
        $this->assertFalse($middleware->isInternalForTest(Request::create('admin/_handle_action_', 'POST')));
    }

    public function test_default_log_policy_keeps_action_execution_and_skips_loaders(): void
    {
        $previous = \Illuminate\Container\Container::getInstance();
        $previousFacade = \Illuminate\Support\Facades\Facade::getFacadeApplication();
        try {
            $app = new \Illuminate\Foundation\Application(dirname(__DIR__, 2));
            \Illuminate\Container\Container::setInstance($app);
            $configuration = require dirname(__DIR__, 2).'/config/admin.php';
            $configuration['operation_log']['enable'] = true;
            $configuration['operation_log']['allowed_methods'] = [];
            $app->instance('config', new \Illuminate\Config\Repository(['admin' => $configuration]));
            \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
            $admin = $this->createMock(\Encore\Admin\Admin::class);
            $admin->method('user')->willReturn((object) ['id' => 7]);
            \Encore\Admin\Facades\Admin::swap($admin);
            $middleware = new class extends LogOperation {
                public function shouldLogForTest(Request $request): bool { return (bool) $this->shouldLogOperation($request); }
            };
            $prefix = trim($configuration['route']['prefix'], '/');
            $this->assertTrue($middleware->shouldLogForTest(Request::create('/'.$prefix.'/_handle_action_', 'POST')));
            $this->assertFalse($middleware->shouldLogForTest(Request::create('/'.$prefix.'/_load_action_', 'POST')));
            // Hosts with existing published configuration also retain the execution log.
            config(['admin.operation_log.except' => []]);
            $this->assertTrue($middleware->shouldLogForTest(Request::create('/'.$prefix.'/_handle_action_', 'POST')));
            $this->assertFalse($middleware->shouldLogForTest(Request::create('/'.$prefix.'/_load_action_', 'POST')));
        } finally {
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($previousFacade);
            \Illuminate\Container\Container::setInstance($previous);
        }
    }
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
