<?php

namespace Encore\Admin\Tests\Middleware;

use Encore\Admin\Middleware\LogOperation;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class LogOperationTest extends TestCase
{
    private $previousContainer;
    private $previousFacade;

    protected function setUp(): void
    {
        $this->previousContainer = \Illuminate\Container\Container::getInstance();
        $this->previousFacade = \Illuminate\Support\Facades\Facade::getFacadeApplication();
        $app = new \Illuminate\Foundation\Application(dirname(__DIR__, 2));
        $app->instance('config', new \Illuminate\Config\Repository([
            'admin' => ['operation_log' => ['enable' => true, 'except' => [], 'allowed_methods' => []]],
        ]));
        $app->instance('request', Request::create('/admin/orders'));
        $app->register(\Illuminate\Routing\RoutingServiceProvider::class);
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        $admin = $this->createMock(\Encore\Admin\Admin::class);
        $admin->method('user')->willReturn(new class {
            public function getAuthIdentifier() { return 7; }
        });
        \Encore\Admin\Facades\Admin::swap($admin);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($this->previousFacade);
        \Illuminate\Container\Container::setInstance($this->previousContainer);
    }

    private function recorder(): LogOperation
    {
        return new class extends LogOperation {
            public array $logs = [];
            public bool $broken = false;
            protected function persist(array $log): void {
                if ($this->broken) throw new \RuntimeException('Logging unavailable.');
                $this->logs[] = $log;
            }
        };
    }

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

    public function test_audit_captures_original_redacted_input_and_the_business_result_after_execution(): void
    {
        $middleware = $this->recorder();
        $request = Request::create('/admin/orders/8', 'PUT', [
            'title' => 'original', 'password' => 'do-not-store-this',
            '_admin_audit' => ['outcome' => 'forged'],
        ]);
        $route = app('router')->put('admin/orders/{id}', fn () => null)->name('orders.update');
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $response = new \Illuminate\Http\JsonResponse(['status' => false, 'password' => 'response-must-not-be-logged']);
        $returned = $middleware->handle($request, function ($request) use ($middleware, $response) {
            $this->assertSame([], $middleware->logs);
            $request->replace(['title' => 'changed']);
            $request->attributes->set('_admin_audit_action', ['class' => 'TrustedAction', 'records' => ['8']]);
            return $response;
        });
        $this->assertSame($response, $returned);
        $this->assertSame(7, $middleware->logs[0]['user_id']);
        $input = json_decode($middleware->logs[0]['input'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('original', $input['title']);
        $this->assertSame('[REDACTED]', $input['password']);
        $this->assertStringNotContainsString('response-must-not-be-logged', $middleware->logs[0]['input']);
        $this->assertSame(200, $input['_admin_audit']['http_status']);
        $this->assertSame('failed', $input['_admin_audit']['outcome']);
        $this->assertFalse($input['_admin_audit']['business_status']);
        $this->assertGreaterThanOrEqual(0, $input['_admin_audit']['duration_ms']);
        $this->assertSame('orders.update', $input['_admin_audit']['route']);
        $this->assertSame(['id' => '8'], $input['_admin_audit']['parameters']);
        $this->assertSame(['8'], $input['_admin_audit']['action']['records']);
    }

    public function test_exceptions_are_logged_and_rethrown_without_recording_their_messages(): void
    {
        foreach ([new \RuntimeException('sensitive SQL contents'), new \Symfony\Component\HttpKernel\Exception\HttpException(403, 'private details')] as $failure) {
            $middleware = $this->recorder();
            try {
                $middleware->handle(Request::create('/admin/orders/8', 'DELETE'), static function () use ($failure) { throw $failure; });
                $this->fail('The original exception must propagate.');
            } catch (\Throwable $exception) {
                $this->assertSame($failure, $exception);
            }
            $input = json_decode($middleware->logs[0]['input'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($failure instanceof \RuntimeException && !$failure instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? 500 : 403,
                $input['_admin_audit']['http_status']);
            $this->assertSame('failed', $input['_admin_audit']['outcome']);
            $this->assertSame(get_class($failure), $input['_admin_audit']['exception']);
            $this->assertStringNotContainsString($failure->getMessage(), $middleware->logs[0]['input']);
        }
    }

    public function test_logging_failure_does_not_change_a_response_or_mask_a_business_exception(): void
    {
        $middleware = $this->recorder();
        $middleware->broken = true;
        $response = new \Illuminate\Http\JsonResponse(['status' => true]);
        $request = Request::create('/admin/orders', 'POST');
        $this->assertSame($response, $middleware->handle($request, fn () => $response));
        $failure = new \RuntimeException('Business failed.');
        try {
            $middleware->handle($request, static function () use ($failure) { throw $failure; });
            $this->fail('Business exception was swallowed.');
        } catch (\Throwable $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function test_large_inputs_keep_valid_bounded_json_and_audit_metadata(): void
    {
        $middleware = $this->recorder();
        $input = array_fill(0, 100, str_repeat('中文', 400));
        $request = Request::create('/admin/orders', 'POST', ['rows' => $input]);
        $middleware->handle($request, fn () => new \Illuminate\Http\JsonResponse(['status' => true]));
        $json = $middleware->logs[0]['input'];
        $this->assertLessThanOrEqual(65535, strlen($json));
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($data['_admin_audit']['input_truncated']);
        $this->assertSame('succeeded', $data['_admin_audit']['outcome']);
    }

    public function test_response_short_circuit_and_redirects_are_not_mislabeled_as_exceptions(): void
    {
        $middleware = $this->recorder();
        $failure = new \Illuminate\Http\Exceptions\HttpResponseException(new \Symfony\Component\HttpFoundation\Response('', 302));
        try {
            $middleware->handle(Request::create('/admin/orders', 'POST'), static function () use ($failure) { throw $failure; });
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            $this->assertSame($failure, $exception);
        }
        $data = json_decode($middleware->logs[0]['input'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(302, $data['_admin_audit']['http_status']);
        $this->assertSame('redirected', $data['_admin_audit']['outcome']);
    }
}
