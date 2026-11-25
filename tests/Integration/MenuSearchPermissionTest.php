<?php

namespace Encore\Admin\Tests\Integration;

use DOMDocument;
use DOMXPath;
use Encore\Admin\Admin;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Permission;
use Encore\Admin\Auth\Database\Role;
use Encore\Admin\Facades\Admin as AdminFacade;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Lang;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\View\ViewServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MenuSearchPermissionTest extends TestCase
{
    private Container $previousContainer;
    private $previousFacadeApplication;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository([
            'app' => ['locale' => 'en'],
            'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
            'admin' => ['route' => ['prefix' => 'admin'], 'enable_menu_search' => true],
        ]));
        $app->instance('files', new Filesystem());
        $app->instance('request', Request::create('http://localhost/admin'));
        foreach ([EventServiceProvider::class, RoutingServiceProvider::class,
            TranslationServiceProvider::class, ViewServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app['view']->addNamespace('admin', dirname(__DIR__, 2).'/resources/views');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $aliases = AliasLoader::getInstance();
        $aliases->alias('Admin', AdminFacade::class);
        $aliases->alias('Lang', Lang::class);
        $aliases->register();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
    }

    public static function navigationModes(): array
    {
        return ['classic' => [false], 'global with classic sidebar' => [true]];
    }

    #[DataProvider('navigationModes')]
    public function test_classic_search_only_renders_visible_leaf_menus(bool $globalNavigation): void
    {
        config(['admin.navigation.enabled' => $globalNavigation]);

        $open = $this->menu('Overview', 'public-overview');
        $allowed = $this->menu('Daily report', 'daily-report', [
            'roles' => [['slug' => 'operator']], 'permission' => 'reports.view',
        ]);
        $wrongRole = $this->menu('Billing roles', 'billing-roles', ['roles' => [['slug' => 'billing']]]);
        $wrongPermission = $this->menu('Payroll export', 'payroll-export', ['permission' => 'payroll.export']);
        $hiddenChild = $this->menu('Hidden descendant', 'hidden-descendant');
        $tree = [
            $open,
            $this->menu('Reports folder', '', ['children' => [$allowed]]),
            $wrongRole,
            $wrongPermission,
            $this->menu('Private folder', '', ['roles' => [['slug' => 'billing']], 'children' => [$hiddenChild]]),
        ];

        $html = $this->renderSidebar($tree, 'operator', [new Permission(['slug' => 'reports.view'])]);
        $this->assertSame(['Overview', 'Daily report'], $this->searchTitles($html));
        $this->assertStringContainsString('http://localhost/admin/daily-report', $html);
        foreach ([$wrongRole, $wrongPermission, $hiddenChild] as $hidden) {
            $this->assertStringNotContainsString($hidden['title'], $html);
            $this->assertStringNotContainsString($hidden['uri'], $html);
        }

        $html = $this->renderSidebar($tree, 'administrator');
        $this->assertSame(['Overview', 'Daily report', 'Billing roles', 'Payroll export', 'Hidden descendant'], $this->searchTitles($html));

        config(['admin.enable_menu_search' => false]);
        $html = view('admin::partials.sidebar')->render();
        $this->assertStringNotContainsString('class="sidebar-form"', $html);
        $this->assertStringContainsString('http://localhost/admin/daily-report', $html);
    }

    private function menu(string $title, string $uri, array $attributes = []): array
    {
        return $attributes + ['id' => $title, 'title' => $title, 'uri' => $uri, 'icon' => 'fa-circle-o'];
    }

    private function renderSidebar(array $tree, string $roleSlug, array $permissions = []): string
    {
        $role = (new Role(['slug' => $roleSlug]))->setRelation('permissions', new Collection());
        $user = new Administrator(['name' => $roleSlug, 'avatar' => 'http://localhost/avatar.png']);
        $user->setRelation('roles', new Collection([$role]));
        $user->setRelation('permissions', new Collection($permissions));
        $admin = $this->getMockBuilder(Admin::class)->onlyMethods(['menu', 'user'])->getMock();
        $admin->method('menu')->willReturn($tree);
        $admin->method('user')->willReturn($user);
        AdminFacade::swap($admin);

        return view('admin::partials.sidebar')->render();
    }

    private function searchTitles(string $html): array
    {
        $document = new DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $titles = [];
        foreach ((new DOMXPath($document))->query('//form[@class="sidebar-form"]//li/a') as $link) {
            $titles[] = trim($link->textContent);
        }

        return $titles;
    }
}
