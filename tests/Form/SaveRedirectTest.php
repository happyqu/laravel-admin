<?php

namespace Encore\Admin\Tests\Form;

use Encore\Admin\Form;
use Encore\Admin\Form\Builder;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class SaveRedirectTest extends TestCase
{
    private $previousContainer;
    private Application $app;
    private Store $session;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Repository([
            'app' => ['locale' => 'en', 'fallback_locale' => 'en'],
            'view' => ['paths' => []],
        ]));
        $this->session = new Store('save', new ArraySessionHandler(120));
        $this->app->instance('session', $this->session);
        $this->app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $this->app->register(\Illuminate\Events\EventServiceProvider::class);
        $this->app->register(\Illuminate\Routing\RoutingServiceProvider::class);
        $this->app->register(\Illuminate\Translation\TranslationServiceProvider::class);
        $this->app->register(\Illuminate\View\ViewServiceProvider::class);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousContainer);
        Container::setInstance($this->previousContainer);
    }

    private function save(bool $navigation, array $input = [])
    {
        $request = Request::create('http://localhost/admin/users/1', 'POST', $input, [], [], [
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_X_ADMIN_NAVIGATION' => $navigation ? 'true' : '',
        ]);
        $request->setLaravelSession($this->session);
        $this->app->instance('request', $request);
        $form = new class extends Form {
            public function __construct() {}
            public function finish() { return $this->redirectAfterSaving('/admin/users', 1); }
        };
        return $form->finish();
    }

    public function test_ajax_save_returns_destination_without_redirect_or_stale_iframe_state(): void
    {
        $this->session->put(['_iframe_id_' => 'iframe_list', 'after_save' => '1']);
        $response = $this->save(true, [Builder::PREVIOUS_URL_KEY => '/admin/users?page=2']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('/admin/users?page=2', $response->getData(true)['redirect']);
        $this->assertFalse($this->session->has('_iframe_id_'));
        $this->assertFalse($this->session->has('after_save'));
        $this->assertFalse($this->session->has('toastr'));
    }

    public function test_continue_editing_keeps_its_destination_in_the_json_response(): void
    {
        $payload = $this->save(true, ['after-save' => '1'])->getData(true);
        $this->assertSame('/admin/users/1/edit', $payload['redirect']);
        $this->assertSame('1', $payload['after_save']);
    }

    public function test_native_save_retains_the_302_and_flash_message(): void
    {
        $response = $this->save(false);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/admin/users', $response->getTargetUrl());
        $this->assertTrue($this->session->has('toastr'));
    }
}
