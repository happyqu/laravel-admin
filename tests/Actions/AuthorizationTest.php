<?php

namespace Encore\Admin\Tests\Actions;

use Encore\Admin\Actions\Action;
use Encore\Admin\Actions\BatchAction;
use Encore\Admin\Actions\Context;
use Encore\Admin\Actions\ContextToken;
use Encore\Admin\Actions\RowAction;
use Encore\Admin\Facades\Admin;
use Illuminate\Auth\Access\Gate;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthorizationTest extends TestCase
{
    private $previous;
    private Application $app;
    private object $user;
    private Gate $gate;
    private Context $context;
    private Collection $rows;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Repository([
            'app' => ['locale' => 'en', 'fallback_locale' => 'en'],
            'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
            'admin' => ['route' => ['prefix' => 'admin']],
        ]));
        $this->app->instance('encrypter', new \Illuminate\Encryption\Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
        $this->app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $this->app->instance('session', new Store('actions', new ArraySessionHandler(120)));
        $this->app->instance('request', Request::create('http://localhost/admin/orders'));
        $this->app->register(\Illuminate\Events\EventServiceProvider::class);
        $this->app->register(\Illuminate\Routing\RoutingServiceProvider::class);
        $this->app->register(\Illuminate\Translation\TranslationServiceProvider::class);
        $this->app->register(\Illuminate\View\ViewServiceProvider::class);
        $this->app['router']->get('admin/orders', fn () => null)->middleware('admin.permission:free');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->user = new class {
            public array $roles = ['finance'];
            public array $permissions = ['orders.refund', 'orders.export'];
            public function getAuthIdentifier() { return 42; }
            public function isRole($role) { return in_array($role, $this->roles, true); }
            public function can($permission) { return $this->isRole('administrator') || in_array($permission, $this->permissions, true); }
        };
        $this->swapUser($this->user);
        $this->gate = new Gate($this->app, static fn () => null);
        $this->app->instance(\Illuminate\Contracts\Auth\Access\Gate::class, $this->gate);
        $this->gate->policy(AuthorizationOrder::class, AuthorizationOrderPolicy::class);
        $this->rows = new Collection([
            new AuthorizationOrder(['id' => 1, 'status' => 'paid']),
            new AuthorizationOrder(['id' => 2, 'status' => 'refunded']),
        ]);
        $this->context = new class($this->rows) extends Context {
            public function __construct(private Collection $rows) {}
            public function query(array $reference) {
                return new class($this->rows) {
                    private array $keys = [];
                    public function __construct(private Collection $rows) {}
                    public function whereKey($keys) { $this->keys = $keys; return $this; }
                    public function get() { return $this->rows->whereIn('id', $this->keys)->values(); }
                };
            }
        };
    }

    private function swapUser($user): void
    {
        $admin = $this->createMock(\Encore\Admin\Admin::class);
        $admin->method('user')->willReturn($user);
        Admin::swap($admin);
    }

    protected function tearDown(): void
    {
        \Encore\Admin\Grid\Column::resetState();
        \Encore\Admin\Admin::$js = [];
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previous);
        Container::setInstance($this->previous);
    }

    private function assertDenied(callable $callback): void
    {
        try {
            $callback();
            $this->fail('An unauthorized action was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function resolve(string $token, $keys = [], bool $loading = false): array
    {
        return $this->context->resolve(Request::create('/execute', 'POST', [
            '_action_token' => $token, '_key' => $keys,
            // Client input must never replace signed rules.
            'authorization' => [], 'authorization_version' => '999',
        ]), $loading);
    }

    public function test_runtime_setters_are_encrypted_and_restored_for_loading_and_submission(): void
    {
        $action = new AuthorizationRuntimeForm();
        $action->setSn('SN-private-001');
        $action->setCallback('App\\Admin\\OrdersController', 'addCost');
        $token = $this->context->register($action);
        $this->assertSame($token, $this->context->register($action));
        $ticket = ContextToken::decode($token);
        $this->assertIsString($ticket['options']);
        $this->assertStringNotContainsString('SN-private-001', json_encode($ticket));
        [$loaded] = $this->resolve($token, [], true);
        $this->assertSame('SN-private-001', $loaded->sn());
        $this->assertSame(['App\\Admin\\OrdersController', 'addCost'], $loaded->callback());
        $this->app['view']->addNamespace('admin', dirname(__DIR__, 2).'/resources/views');
        $this->assertStringContainsString('value="SN-private-001"', $loaded->loadInteraction()['html']);
        $execution = $this->context->executionTicket($token, [], null);
        [$submitted] = $this->resolve($execution);
        $this->assertSame($loaded->sn(), $submitted->sn());
        $this->assertSame($loaded->callback(), $submitted->callback());
    }

    public function test_existing_unencrypted_explicit_options_remain_usable(): void
    {
        $ticket = ContextToken::decode($this->context->register(new AuthorizationExplicitRuntimeForm()));
        $ticket['options'] = ['callback' => ['App\\Admin\\OrdersController', 'addCost']];
        [$loaded] = $this->resolve(ContextToken::encode($ticket), [], true);
        $this->assertSame(['App\\Admin\\OrdersController', 'addCost'], $loaded->callback());
    }

    public function test_constructor_permissions_are_rechecked_for_loading_and_submission(): void
    {
        $action = new AuthorizationConstructorPermissionForm();
        $action->setSn('SN-001');
        $token = $this->context->register($action);
        [$loaded] = $this->resolve($token, [], true);
        $this->assertSame('SN-001', $loaded->sn());
        $execution = $this->context->executionTicket($token, [], null);
        $this->user->permissions = ['orders.export'];
        $this->assertDenied(fn () => $this->resolve($token, [], true));
        $this->assertDenied(fn () => $this->resolve($execution));
    }

    public function test_old_automatic_snapshots_cannot_restore_cached_permissions(): void
    {
        $ticket = ContextToken::decode($this->context->register(new AuthorizationConstructorPermissionForm()));
        $ticket['options'] = \Illuminate\Support\Facades\Crypt::encryptString(json_encode([
            AuthorizationConstructorPermissionForm::class.'::allowed' => true,
        ], JSON_THROW_ON_ERROR));
        $this->user->permissions = ['orders.export'];
        $this->expectException(HttpResponseException::class);
        $this->resolve(ContextToken::encode($ticket), [], true);
    }

    public function test_invalid_encrypted_state_is_rejected(): void
    {
        $ticket = ContextToken::decode($this->context->register(new AuthorizationRuntimeForm()));
        $ticket['options'] = 'invalid ciphertext';
        $this->expectException(HttpResponseException::class);
        $this->resolve(ContextToken::encode($ticket), [], true);
    }

    public function test_runtime_options_cannot_restore_framework_internal_properties(): void
    {
        $action = new AuthorizationRuntimeForm();
        $this->expectException(HttpResponseException::class);
        $action->restoreContext([Action::class.'::resolvedModel' => null]);
    }

    public function test_role_and_permission_conditions_are_combined_and_explicit_denial_wins(): void
    {
        $action = (new AuthorizationToolbar())
            ->allowRoles(['administrator', 'finance'])
            ->requirePermissions(['orders.refund', 'orders.export'])
            ->requireAnyPermission(['orders.approve', 'orders.export']);
        $this->assertTrue($action->passesAuthorization());
        $this->user->permissions = ['orders.refund'];
        $this->assertFalse($action->passesAuthorization());
        $this->user->permissions = ['orders.refund', 'orders.export'];
        $this->user->roles = ['sales'];
        $this->assertFalse($action->passesAuthorization());
        $this->user->roles = ['administrator'];
        $this->assertTrue($action->passesAuthorization());
        $this->assertFalse($action->denyRoles(['administrator'])->passesAuthorization());
        $this->assertFalse((new AuthorizationToolbar())->allowRoles(['finance'])->passesAuthorization());
    }

    public function test_any_permission_accepts_one_match_and_rejects_no_matches(): void
    {
        $action = (new AuthorizationToolbar())->requireAnyPermission(['orders.approve', 'orders.export']);
        $this->assertTrue($action->passesAuthorization());
        $this->user->permissions = [];
        $this->assertFalse($action->passesAuthorization());
    }

    public function test_invalid_lists_and_ability_arguments_fail_at_configuration_time(): void
    {
        foreach (['allowRoles', 'denyRoles', 'requirePermissions', 'requireAnyPermission'] as $method) {
            foreach ([[], [''], [12], ['ok', '  ']] as $names) {
                try {
                    (new AuthorizationToolbar())->$method($names);
                    $this->fail('Invalid authorization configuration was accepted.');
                } catch (\InvalidArgumentException $exception) {
                    $this->assertNotEmpty($exception->getMessage());
                }
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        (new AuthorizationToolbar())->ability('create', \stdClass::class);
    }

    public function test_policy_uses_the_admin_user_and_current_record_even_for_administrators(): void
    {
        $action = (new AuthorizationRow())->ability('refund');
        $this->assertTrue($action->passesAuthorization($this->rows[0]));
        $this->assertFalse($action->passesAuthorization($this->rows[1]));
        $this->user->roles = ['administrator'];
        $this->assertFalse($action->passesAuthorization($this->rows[1]));
    }

    public function test_class_abilities_and_global_gate_abilities_work_without_a_record(): void
    {
        $this->assertTrue((new AuthorizationToolbar())->ability('create', AuthorizationOrder::class)->passesAuthorization());
        $this->gate->define('clear-cache', fn ($user) => $user === $this->user);
        $this->assertTrue((new AuthorizationToolbar())->ability('clear-cache')->passesAuthorization());
        $this->user->roles = ['sales'];
        $this->assertFalse((new AuthorizationToolbar())->ability('create', AuthorizationOrder::class)->passesAuthorization());
    }

    public function test_existing_action_and_model_hooks_cannot_be_bypassed_by_button_rules(): void
    {
        $action = (new AuthorizationRow())->requirePermissions(['orders.refund']);
        $this->assertTrue($action->passesAuthorization($this->rows[0]));
        $action->allowed = false;
        $this->assertFalse($action->passesAuthorization($this->rows[0]));
        $action->allowed = true;
        $this->rows[0]->actionAllowed = false;
        $this->assertFalse($action->passesAuthorization($this->rows[0]));
        $this->assertFalse((new AuthorizationBatch())->passesAuthorization($this->rows));
    }

    public function test_unauthenticated_actions_are_denied_even_without_configuration(): void
    {
        $this->swapUser(null);
        $this->assertFalse((new AuthorizationToolbar())->passesAuthorization());
        $this->assertSame('', (new AuthorizationToolbar())->render());
    }

    public function test_denied_buttons_are_hidden_or_disabled_without_generating_tickets(): void
    {
        $actions = [new AuthorizationToolbar(), (new AuthorizationRow())->setRow($this->rows[0]), new AuthorizationBatch()];
        foreach ($actions as $action) {
            $action->requirePermissions(['missing']);
            $this->assertSame('', $action->render());
            $html = $action->deniedAsDisabled('No "permission" <script>')->render();
            $this->assertStringContainsString('aria-disabled="true"', $html);
            $this->assertStringContainsString('&lt;script&gt;', $html);
            $this->assertStringNotContainsString('data-admin-action', $html);
            $this->assertStringNotContainsString('href=', $html);
            $this->assertStringNotContainsString('<script>', $html);
        }
        $this->assertSame([], \Encore\Admin\Admin::$js);
    }

    public function test_link_actions_also_check_authorization_before_returning_the_link(): void
    {
        $action = (new AuthorizationLink())->setRow($this->rows[0])->requirePermissions(['missing']);
        $this->assertSame('', $action->render());
        $action->requirePermissions(['orders.refund']);
        $this->assertStringContainsString('href=', $action->render());
    }

    public function test_batch_policy_is_deferred_until_selection_and_checks_every_selected_record(): void
    {
        $action = (new AuthorizationBatch())->ability('refund')->requirePermissions(['orders.refund']);
        $this->assertTrue($action->passesAuthorizationForRendering());
        $token = $this->context->register($action);
        [, $selected] = $this->resolve($token, [1]);
        $this->assertSame([1], $selected->modelKeys());
        $this->assertDenied(fn () => $this->resolve($token, [1, 2]));
        $this->assertDenied(fn () => $this->resolve($token, [2], true));
        $this->user->permissions = [];
        $this->assertFalse($action->passesAuthorizationForRendering());
    }

    public function test_toolbar_and_batch_renderers_issue_tickets_only_for_authorized_buttons(): void
    {
        $this->app->instance(Context::class, $this->context);
        $html = (new AuthorizationToolbar())->allowRoles(['finance'])->render();
        $this->assertStringContainsString('data-admin-action=', $html);
        $grid = $this->createMock(\Encore\Admin\Grid::class);
        $gridModel = $this->createMock(\Encore\Admin\Grid\Model::class);
        $gridModel->method('getOriginalModel')->willReturn($this->rows[0]);
        $grid->method('model')->willReturn($gridModel);
        $grid->method('getGridRowName')->willReturn('orders');
        $batch = (new AuthorizationBatch())->setGrid($grid)->ability('refund');
        $html = $batch->render();
        $this->assertStringContainsString('data-admin-action=', $html);
        $this->assertStringContainsString('data-admin-selection="orders-checkbox"', $html);
        $this->user->roles = ['sales'];
        // A model-level ability can be decided before there is a selection.
        $batch->ability('create', AuthorizationOrder::class);
        $this->assertSame('', $batch->render());
    }

    public function test_older_tickets_keep_class_defined_authorization_and_unconfigured_actions_work(): void
    {
        $ticket = ContextToken::decode($this->context->register(new AuthorizationRestrictedToolbar()));
        unset($ticket['authorization'], $ticket['authorization_version']);
        $token = ContextToken::encode($ticket);
        $this->assertInstanceOf(AuthorizationRestrictedToolbar::class, $this->resolve($token)[0]);
        $this->user->permissions = [];
        $this->assertDenied(fn () => $this->resolve($token));
        $unrestricted = $this->context->register(new AuthorizationToolbar());
        $this->assertInstanceOf(AuthorizationToolbar::class, $this->resolve($unrestricted)[0]);
    }

    public function test_button_rules_survive_reconstruction_and_distinguish_otherwise_identical_tickets(): void
    {
        $action = (new AuthorizationToolbar())->requirePermissions(['orders.refund']);
        $first = $this->context->register($action);
        $this->assertSame($first, $this->context->register($action));
        $this->assertSame(['permissions' => ['orders.refund']], ContextToken::decode($first)['authorization']);
        $action->requirePermissions(['orders.export']);
        $second = $this->context->register($action);
        $this->assertNotSame($first, $second);
        $this->user->permissions = ['orders.refund'];
        $this->assertInstanceOf(AuthorizationToolbar::class, $this->resolve($first)[0]);
        $this->assertDenied(fn () => $this->resolve($second));
        $this->user->roles = [];
        $roleToken = $this->context->register((new AuthorizationToolbar())->allowRoles(['finance']));
        $this->assertDenied(fn () => $this->resolve($roleToken));
    }

    public function test_form_loading_and_submission_recheck_permissions_and_current_record_policy(): void
    {
        $action = (new AuthorizationForm())->setRow($this->rows[0])->requirePermissions(['orders.refund'])->ability('refund');
        $token = $this->context->register($action);
        [, $model, , $keys] = $this->resolve($token, [1], true);
        $loaded = $this->context->executionTicket($token, $keys, $model);
        $this->user->permissions = [];
        $this->assertDenied(fn () => $this->resolve($token, [1], true));
        $this->assertDenied(fn () => $this->resolve($loaded));
        $this->user->permissions = ['orders.refund'];
        $this->rows[0]->status = 'refunded';
        $this->assertDenied(fn () => $this->resolve($loaded));
        $this->rows[0]->status = 'paid';
        $this->assertInstanceOf(AuthorizationForm::class, $this->resolve($loaded)[0]);
    }

    public function test_authorization_version_invalidates_old_tickets_on_load_and_execution(): void
    {
        $token = $this->context->register(new AuthorizationToolbar());
        $this->app['config']->set('admin.actions.authorization_version', '2');
        foreach ([true, false] as $loading) {
            try {
                $this->resolve($token, [], $loading);
                $this->fail('An obsolete authorization ticket was accepted.');
            } catch (HttpResponseException $exception) {
                $this->assertSame('action_context_invalid', $exception->getResponse()->getData(true)['code']);
            }
        }
        $new = $this->context->register(new AuthorizationToolbar());
        $this->assertNotSame($token, $new);
        $this->assertInstanceOf(AuthorizationToolbar::class, $this->resolve($new)[0]);
    }

    public function test_action_columns_clone_configured_instances_and_bind_dialogs_to_each_row(): void
    {
        $column = new \Encore\Admin\Grid\Column('status', 'Status');
        $grid = $this->createMock(\Encore\Admin\Grid::class);
        $gridModel = $this->createMock(\Encore\Admin\Grid\Model::class);
        $gridModel->method('eloquent')->willReturn($this->rows[0]);
        $grid->method('model')->willReturn($gridModel);
        $column->setGrid($grid);
        \Encore\Admin\Grid\Column::setOriginalGridModels($this->rows);
        $prototype = (new AuthorizationDialog())->requirePermissions(['orders.refund']);
        $column->action($prototype);
        $data = $column->fill($this->rows->toArray());
        $first = $data[0]['status'];
        $second = $data[1]['status'];
        $this->assertNotSame($first, $second);
        $this->assertSame($this->rows[0], $first->getRow());
        $this->assertSame($this->rows[1], $second->getRow());
        $this->assertSame(['permissions' => ['orders.refund']], $second->authorizationConfiguration());
        $this->assertSame('Order 1', $first->loadInteraction()['settings']['title']);
        $this->assertSame('Order 2', $second->loadInteraction()['settings']['title']);
        $this->assertNull($prototype->getRow());
        $first->ability('refund');
        $second->ability('refund');
        $this->assertTrue($first->passesAuthorizationForRendering());
        $this->assertFalse($second->passesAuthorizationForRendering());
        $this->app->instance(Context::class, $this->context);
        $html = $first->render();
        $this->assertStringContainsString('data-admin-action=', $html);
        $this->assertSame('', $second->render());
    }

    public function test_tools_share_role_permission_and_policy_configuration(): void
    {
        $tool = (new \Encore\Admin\Widgets\Tool('<a href="/refund">Refund</a>'))
            ->allowRoles(['finance'])->requirePermissions(['orders.refund'])
            ->requireAnyPermission(['orders.export', 'missing'])->ability('refund')->forModel($this->rows[0]);
        $this->assertTrue($tool->passesAuthorization());
        $this->assertStringContainsString('href=', (string) $tool);
        $tool->forModel($this->rows[1]);
        $this->assertFalse($tool->passesAuthorization());
        $this->assertSame('', (string) $tool);
        $tool->ability('create', AuthorizationOrder::class);
        $this->assertTrue($tool->passesAuthorization());
        $tool->denyRoles(['finance']);
        $this->assertFalse($tool->passesAuthorization());
        $this->swapUser(null);
        $this->assertFalse((new \Encore\Admin\Widgets\Tool())->allowRoles(['finance'])->passesAuthorization());
        $this->assertSame('Existing HTML', (string) new \Encore\Admin\Widgets\Tool('Existing HTML'));
    }

    public function test_tool_containers_authorize_before_rendering_custom_content(): void
    {
        $grid = $this->createMock(\Encore\Admin\Grid::class);
        $containers = [
            'grid' => function ($tool) use ($grid) {
                $tools = new class($grid) extends \Encore\Admin\Grid\Tools {
                    protected function appendDefaultTools() {}
                };
                return $tools->append($tool)->render();
            },
            'form' => function ($tool) {
                $tools = new \Encore\Admin\Form\Tools($this->createMock(\Encore\Admin\Form\Builder::class));
                $tools->disableList()->disableDelete()->disableView();
                return $tools->append($tool)->render();
            },
            'detail' => function ($tool) {
                $tools = new \Encore\Admin\Show\Tools($this->createMock(\Encore\Admin\Show\Panel::class));
                $tools->disableList()->disableDelete()->disableEdit();
                return $tools->prepend($tool)->render();
            },
            'tree' => function ($tool) {
                $tools = new \Encore\Admin\Tree\Tools($this->createMock(\Encore\Admin\Tree::class));
                return $tools->add($tool)->render();
            },
            'navbar' => fn ($tool) => (new \Encore\Admin\Widgets\Navbar())->left($tool)->render('left'),
        ];
        foreach ($containers as $name => $render) {
            $content = new class implements \Illuminate\Contracts\Support\Renderable {
                public int $renders = 0;
                public function render() { $this->renders++; return '<button>Export</button>'; }
            };
            $tool = (new \Encore\Admin\Widgets\Tool($content))->requirePermissions(['missing']);
            $this->assertSame('', trim($render($tool)), $name);
            $this->assertSame(0, $content->renders, $name);
            $tool->deniedAsDisabled('No <permission>');
            $html = $render($tool);
            $this->assertStringContainsString('aria-disabled="true"', $html, $name);
            $this->assertStringContainsString('No &lt;permission&gt;', $html, $name);
            $this->assertSame(0, $content->renders, $name);
            $tool->requirePermissions(['orders.export']);
            $this->assertStringContainsString('<button>Export</button>', $render($tool), $name);
            $this->assertSame(1, $content->renders, $name);
        }
    }

    public function test_nested_tool_authorization_preserves_inner_rules_and_checks_every_wrapper(): void
    {
        $inner = new class('Sensitive content') extends \Encore\Admin\Widgets\Tool {
            public int $renders = 0;
            public bool $hookAllowed = true;
            public function authorize($user, $model) { return $this->hookAllowed; }
            public function render() { $this->renders++; return parent::render(); }
        };
        $inner->requirePermissions(['missing']);
        $outer = (new \Encore\Admin\Widgets\Tool(new \Encore\Admin\Widgets\Tool($inner)))->allowRoles(['finance']);
        $this->assertFalse($outer->passesAuthorization());
        $this->assertSame('', $outer->renderAuthorized());
        $this->assertSame(0, $inner->renders);
        $inner->requirePermissions(['orders.refund'])->ability('refund')->forModel($this->rows[0]);
        $this->assertTrue($outer->passesAuthorization());
        $this->assertSame('Sensitive content', $outer->renderAuthorized());
        $inner->hookAllowed = false;
        $this->assertFalse($outer->passesAuthorization());
        $inner->hookAllowed = true;
        $this->rows[0]->status = 'refunded';
        $this->assertFalse($outer->passesAuthorization());
        $this->rows[0]->status = 'paid';
        $outer->allowRoles(['manager']);
        $this->assertFalse($outer->passesAuthorization());
        $this->assertSame(1, $inner->renders);
        $unconfiguredWrapper = new \Encore\Admin\Widgets\Tool($inner);
        $this->user->permissions = [];
        $this->assertFalse($unconfiguredWrapper->passesAuthorization());
    }

    public function test_nested_grid_tools_receive_context_before_authorization_and_honor_disable(): void
    {
        $grid = $this->createMock(\Encore\Admin\Grid::class);
        $tools = new class($grid) extends \Encore\Admin\Grid\Tools {
            protected function appendDefaultTools() {}
        };
        $inner = new class extends \Encore\Admin\Grid\Tools\AbstractTool {
            public int $renders = 0;
            public function authorize($user, $model) { return $this->getGrid() !== null; }
            public function render() { $this->renders++; return 'Grid tool'; }
        };
        $outer = (new \Encore\Admin\Widgets\Tool(new \Encore\Admin\Widgets\Tool($inner)))->requirePermissions(['orders.export']);
        $tools->prepend($outer);
        $this->assertSame('Grid tool', $tools->render());
        $this->assertSame($grid, $inner->getGrid());
        $this->assertSame(1, $inner->renders);
        $inner->disable();
        $this->assertSame('', $tools->render());
        $this->assertSame(1, $inner->renders);
        $inner->disable(false)->requirePermissions(['missing']);
        $this->assertFalse($outer->passesAuthorization());
        $this->assertSame('', $tools->render());
        $this->assertSame(1, $inner->renders);
    }

    public function test_wrapped_grid_actions_receive_the_container_grid(): void
    {
        $grid = $this->createMock(\Encore\Admin\Grid::class);
        $grid->method('resource')->willReturn('/admin/orders');
        $tools = new class($grid) extends \Encore\Admin\Grid\Tools {
            protected function appendDefaultTools() {}
        };
        $action = new class extends \Encore\Admin\Actions\GridAction {
            public function html() { return '<a href="'.$this->getResource().'/export">Export</a>'; }
        };
        $tools->append((new \Encore\Admin\Widgets\Tool($action))->requirePermissions(['orders.export']));
        $this->assertStringContainsString('href="/admin/orders/export"', $tools->render());
    }

    public function test_grid_abstract_tools_inherit_rules_and_receive_the_grid_before_authorization(): void
    {
        $grid = $this->createMock(\Encore\Admin\Grid::class);
        $tools = new class($grid) extends \Encore\Admin\Grid\Tools {
            protected function appendDefaultTools() {}
        };
        $tool = new class extends \Encore\Admin\Grid\Tools\AbstractTool {
            public int $renders = 0;
            public bool $allowedByHook = true;
            public function authorize($user, $model) { return $this->getGrid() !== null && $this->allowedByHook; }
            public function render() { $this->renders++; return '<button>Custom Grid Tool</button>'; }
        };
        $tools->prepend($tool->requirePermissions(['missing']));
        $this->assertSame('', $tools->render());
        $this->assertSame(0, $tool->renders);
        $tool->requirePermissions(['orders.export']);
        $this->assertStringContainsString('Custom Grid Tool', $tools->render());
        $this->assertSame($grid, $tool->getGrid());
        $tool->allowedByHook = false;
        $this->assertSame('', $tools->render());
        $tool->allowedByHook = true;
        $tool->disable();
        $this->assertSame('', $tools->render());
        $this->assertSame(1, $tool->renders);
    }

    public function test_grid_header_and_footer_authorize_returned_tools(): void
    {
        $grid = $this->createMock(\Encore\Admin\Grid::class);
        $inner = new class extends \Encore\Admin\Grid\Tools\AbstractTool {
            public function authorize($user, $model) { return $this->getGrid() !== null; }
            public function render() { return 'Sensitive content'; }
        };
        $tool = (new \Encore\Admin\Widgets\Tool($inner))->requirePermissions(['missing']);
        $grid->method('header')->willReturn(fn ($query) => $tool);
        $grid->method('footer')->willReturn(fn ($query) => $tool);
        foreach ([\Encore\Admin\Grid\Tools\Header::class, \Encore\Admin\Grid\Tools\Footer::class] as $class) {
            $region = $this->getMockBuilder($class)->setConstructorArgs([$grid])->onlyMethods(['queryBuilder'])->getMock();
            $region->method('queryBuilder')->willReturn(null);
            $this->assertStringNotContainsString('Sensitive content', $region->render());
            $this->assertSame($grid, $inner->getGrid());
            $tool->requirePermissions(['orders.export']);
            $this->assertStringContainsString('Sensitive content', $region->render());
            $tool->requirePermissions(['missing']);
        }
    }
}

class AuthorizationOrder extends Model
{
    protected $guarded = [];
    public $timestamps = false;
    public bool $actionAllowed = true;
    public function actionAuthorize($user, $action) { return $this->actionAllowed; }
}

class AuthorizationOrderPolicy
{
    public function refund($user, AuthorizationOrder $order): bool
    {
        return $user->isRole('finance') && $order->status === 'paid';
    }
    public function create($user): bool { return $user->isRole('finance'); }
}

class AuthorizationToolbar extends Action
{
    public $name = 'Run';
    public function html() { return '<button>Run</button>'; }
}

class AuthorizationRow extends RowAction
{
    public $name = 'Refund';
    public bool $allowed = true;
    public function parameters() { return []; }
    public function authorize($user, $model) { return $this->allowed; }
}

class AuthorizationLink extends AuthorizationRow
{
    public function href() { return '/orders/1/edit'; }
}

class AuthorizationBatch extends BatchAction
{
    public $name = 'Batch refund';
    public function parameters() { return []; }
    public function contextKeys(): array { return [1, 2]; }
    public function contextModel() { return null; }
    protected function getModelClass() { return AuthorizationOrder::class; }
}

class AuthorizationForm extends AuthorizationRow
{
    public function form() { $this->text('reason'); }
}

class AuthorizationDialog extends AuthorizationRow
{
    public function dialog() { $this->confirm('Order '.$this->getRow()->getKey()); }
}

class AuthorizationRestrictedToolbar extends AuthorizationToolbar
{
    public function __construct()
    {
        parent::__construct();
        $this->requirePermissions(['orders.refund']);
    }
}

class AuthorizationRuntimeForm extends Action
{
    private $sn;
    protected $callback;
    public function setSn($sn) { $this->sn = $sn; }
    public function sn() { return $this->sn; }
    public function setCallback($class, $method) { $this->callback = [$class, $method]; }
    public function callback() { return $this->callback; }
    public function form() { $this->hidden('sn')->value($this->sn); }
}

class AuthorizationExplicitRuntimeForm extends AuthorizationRuntimeForm
{
    protected $contextProperties = ['callback'];
}

class AuthorizationConstructorPermissionForm extends AuthorizationRuntimeForm
{
    private $allowed = false;
    public function __construct()
    {
        parent::__construct();
        $this->allowed = Admin::user()->can('orders.refund');
    }
    public function authorize($user, $model) { return $this->allowed; }
}
