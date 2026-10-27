<?php

namespace Encore\Admin\Tests\Controllers;

use Encore\Admin\Controllers\AuthController;
use Encore\Admin\Controllers\HandleController;
use Encore\Admin\Controllers\RoleController;
use Encore\Admin\Controllers\UserController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Security\ComponentSubmission;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\TestCase;

class ControllerBehaviorTest extends TestCase
{
    private $previous;
    private Store $session;
    private object $user;
    private $imageField;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository([
            'app' => ['locale' => 'en', 'fallback_locale' => 'en', 'url' => 'http://localhost'],
            'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
            'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]],
            'admin' => ['route' => ['prefix' => 'admin'], 'database' => [
                'users_model' => ControllerUser::class, 'roles_model' => ControllerLookup::class,
                'permissions_model' => ControllerLookup::class, 'connection' => '',
            ]],
        ]));
        $app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $app->instance('encrypter', new \Illuminate\Encryption\Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
        $this->session = new Store('controllers', new ArraySessionHandler(120));
        $app->instance('session', $this->session);
        foreach ([\Illuminate\Translation\TranslationServiceProvider::class, \Illuminate\Validation\ValidationServiceProvider::class,
            \Illuminate\Routing\RoutingServiceProvider::class, \Illuminate\View\ViewServiceProvider::class,
            \Illuminate\Hashing\HashServiceProvider::class, \Illuminate\Cache\CacheServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $this->user = new class {
            public bool $allowed = true;
            public string $id = 'admin-uuid';
            public function getAuthIdentifier() { return $this->id; }
            public function isRole($role) { return false; }
            public function can($permission) { return $this->allowed; }
        };
        $admin = $this->createMock(\Encore\Admin\Admin::class);
        $admin->method('user')->willReturn($this->user);
        Admin::swap($admin);
        $this->request([]);
        $app['router']->get('admin/source', fn () => null)->middleware('admin.permission:check,view-components');
        $app['router']->get('admin/_handle_renderable_', fn () => null)->name('admin.handle-renderable');
        $app['router']->get('admin/_handle_selectable_', fn () => null)->name('admin.handle-selectable');
        $app['router']->getRoutes()->refreshNameLookups();
        $this->imageField = Form::$availableFields['image'];
        Form::$availableFields['image'] = ControllerImage::class;
        ControllerLookup::$protected = true;
        ControllerPreview::$constructions = 0;
    }

    protected function tearDown(): void
    {
        Form::$availableFields['image'] = $this->imageField;
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previous);
        Container::setInstance($this->previous);
    }

    private function request(array $input, string $method = 'GET', string $url = '/admin/source'): Request
    {
        $request = Request::create('http://localhost'.$url, $method, $input);
        $request->setLaravelSession($this->session);
        app()->instance('request', $request);
        Facade::clearResolvedInstance('request');
        return $request;
    }

    public function test_login_rejects_array_credentials_and_parses_remember_as_boolean(): void
    {
        $controller = new class extends AuthController {
            public object $testGuard;
            public function rules($input) { return $this->loginValidator($input); }
            protected function guard() { return $this->testGuard; }
            protected function sendLoginResponse(Request $request) { return 'logged-in'; }
        };
        $this->assertFalse($controller->rules(['username' => ['x'], 'password' => ['x']])->passes());
        $controller->testGuard = new class {
            public bool $remember = true;
            public function attempt($credentials, $remember) { $this->remember = $remember; return true; }
        };
        $request = $this->request(['username' => 'user', 'password' => 'secret', 'remember' => 'false'], 'POST');
        $this->assertSame('logged-in', $controller->postLogin($request));
        $this->assertFalse($controller->testGuard->remember);
    }

    public function test_logout_rotates_the_csrf_token_and_invalidates_session_data(): void
    {
        $controller = new class extends AuthController {
            protected function guard() { return new class { public function logout() {} }; }
        };
        $this->session->put('old', 'value');
        $this->session->regenerateToken();
        $token = $this->session->token();
        $controller->getLogout(request());
        $this->assertNotSame($token, $this->session->token());
        $this->assertNotEmpty($this->session->token());
        $this->assertFalse($this->session->has('old'));
    }

    private function forms(): array
    {
        $auth = new class extends AuthController { public function profile() { return $this->settingForm(); } };
        return [$auth->profile(), (new UserController())->form()];
    }

    public function test_password_forms_hide_hashes_preserve_blank_and_hash_zero(): void
    {
        foreach ($this->forms() as $form) {
            $hash = Hash::make('original');
            $form->model()->password = $hash;
            $field = $form->fields()->first(fn ($field) => $field->column() === 'password');
            $field->fill(['password' => $hash]);
            $this->assertSame('', $field->value());
            $confirmation = $form->fields()->first(fn ($field) => $field->column() === 'password_confirmation');
            $this->assertNotSame($hash, $confirmation->value());
            $prepare = new \ReflectionMethod(Form::class, 'prepare');
            $prepare->invoke($form, ['password' => '']);
            $this->assertSame($hash, $form->input('password'));
        }
        foreach ($this->forms() as $form) {
            (new \ReflectionMethod(Form::class, 'prepare'))->invoke($form, ['password' => '0']);
            $this->assertTrue(Hash::check('0', $form->input('password')));
        }
    }

    public function test_username_uniqueness_uses_model_table_and_custom_primary_key(): void
    {
        $form = (new UserController())->form();
        $form->model()->uuid = 'user-uuid';
        $field = $form->fields()->first(fn ($field) => $field->column() === 'username');
        $this->request([], 'PUT');
        $rules = (new \ReflectionMethod(Form\Field::class, 'getRules'))->invoke($field);
        $rule = (string) $rules[2];
        $this->assertStringContainsString('unique:custom_users,username', $rule);
        $this->assertStringContainsString('user-uuid', $rule);
        $this->assertStringContainsString('uuid', $rule);
        $this->assertStringNotContainsString('unique:.', $rule);
    }

    public function test_settings_use_the_auth_identifier(): void
    {
        $form = $this->getMockBuilder(Form::class)->disableOriginalConstructor()->onlyMethods(['update'])->getMock();
        $form->expects($this->once())->method('update')->with('admin-uuid')->willReturn('saved');
        $controller = new class($form) extends AuthController {
            public function __construct(private Form $testForm) {}
            protected function settingForm() { return $this->testForm; }
        };
        $this->assertSame('saved', $controller->putSetting());
    }

    public function test_protected_accounts_and_roles_are_rejected_before_deletion(): void
    {
        config(['admin.database.users_model' => ControllerLookup::class]);
        foreach ([new UserController(), new RoleController()] as $controller) {
            try {
                $controller->destroy('1,2');
                $this->fail('Protected record was deleted.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    private function component(array $payload = [7], string $kind = 'renderable'): array
    {
        $class = $kind === 'renderable' ? ControllerPreview::class : ControllerSelectable::class;
        $parameters = $kind === 'renderable' ? ['renderable' => str_replace('\\', '_', $class)]
            : ['selectable' => str_replace('\\', '_', $class), 'args' => $payload];
        $url = ComponentSubmission::url('admin.handle-'.$kind, $parameters, $kind, $class, $payload);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        return $query;
    }

    public function test_renderable_ticket_binds_the_class_key_and_current_permission(): void
    {
        $query = $this->component();
        $request = $this->request($query + ['key' => '7']);
        $this->assertSame('preview:7', (new HandleController())->handleRenderable($request));
        $this->assertSame(1, ControllerPreview::$constructions);
        $this->user->allowed = false;
        try {
            (new HandleController())->handleRenderable($request);
            $this->fail('Revoked source permission was ignored.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(1, ControllerPreview::$constructions);
    }

    public function test_component_arguments_cannot_be_changed_or_used_without_a_ticket(): void
    {
        $query = $this->component();
        foreach ([$query + ['key' => '8'], ['renderable' => str_replace('\\', '_', ControllerPreview::class), 'key' => '7']] as $input) {
            try {
                (new HandleController())->handleRenderable($this->request($input));
                $this->fail('Invalid component request was accepted.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertContains($exception->getStatusCode(), [403, 419]);
            }
        }
        $this->assertSame(0, ControllerPreview::$constructions);
    }

    public function test_selectable_ticket_restores_boolean_constructor_arguments(): void
    {
        $query = $this->component([false, 'id'], 'selectable');
        $query['args'] = ['0', 'id'];
        $this->assertSame('single:id', (new HandleController())->handleSelectable($this->request($query)));
    }

    public function test_array_component_identifiers_return_a_client_error(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new HandleController())->handleRenderable($this->request(['renderable' => ['bad']]));
    }

    public function test_component_class_names_with_underscores_are_resolved_from_the_ticket(): void
    {
        $class = Controller_UnderscorePreview::class;
        $url = ComponentSubmission::url('admin.handle-renderable', ['renderable' => str_replace('\\', '_', $class)],
            'renderable', $class, [7]);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('preview:7', (new HandleController())->handleRenderable($this->request($query + ['key' => '7'])));
    }

    public function test_component_tickets_reject_class_and_user_changes(): void
    {
        $query = $this->component();
        foreach (['class', 'user'] as $change) {
            $input = $query + ['key' => '7'];
            if ($change === 'class') {
                $input['renderable'] = str_replace('\\', '_', Controller_UnderscorePreview::class);
            } else {
                $this->user->id = 'different-user';
            }
            try {
                (new HandleController())->handleRenderable($this->request($input));
                $this->fail('A changed component identity was accepted.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertSame(0, ControllerPreview::$constructions);
    }

    public function test_forms_and_actions_inside_components_keep_the_original_source(): void
    {
        $query = $this->component();
        $request = $this->request($query + ['key' => '7'], 'GET', '/admin/_handle_renderable_');
        (new HandleController())->handleRenderable($request);
        $form = new class extends \Encore\Admin\Widgets\Form { public function __construct() {} };
        $token = \Encore\Admin\Security\FormSubmission::issue($form);
        $this->assertSame('http://localhost/admin/source', session('admin.form_submissions.'.$token.'.source'));
        $action = new class extends \Encore\Admin\Actions\Action {};
        $token = (new \Encore\Admin\Actions\Context())->register($action);
        $this->assertSame(['http://localhost/admin/source', 'GET'], \Encore\Admin\Actions\ContextToken::decode($token)['source']);
    }
}

class ControllerImage extends Form\Field\Image { protected function initStorage() {} }
class ControllerUser extends Model {
    protected $table = 'custom_users';
    protected $primaryKey = 'uuid';
    protected $keyType = 'string';
    public $incrementing = false;
}
class ControllerLookup extends Model
{
    public static bool $protected = true;
    public static function all($columns = ['*']) { return new \Illuminate\Database\Eloquent\Collection(); }
    public static function query() {
        return new class {
            public function whereKey($keys) { return $this; }
            public function where($column, $value) { return $this; }
            public function exists() { return ControllerLookup::$protected; }
        };
    }
}
class ControllerPreview implements \Illuminate\Contracts\Support\Renderable
{
    public static int $constructions = 0;
    public function __construct() { self::$constructions++; }
    public function render($key = null) { return 'preview:'.$key; }
}
class ControllerSelectable extends \Encore\Admin\Grid\Selectable
{
    public function __construct(private bool $testMultiple = false, private string $testKey = 'id') {}
    public function make() {}
    public function render() { return ($this->testMultiple ? 'multi' : 'single').':'.$this->testKey; }
}
class Controller_UnderscorePreview extends ControllerPreview {}
