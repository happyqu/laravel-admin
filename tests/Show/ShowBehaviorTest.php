<?php

namespace Encore\Admin\Tests\Show;

use Encore\Admin\Admin;
use Encore\Admin\Show;
use Encore\Admin\Show\Relation;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class ShowBehaviorTest extends TestCase
{
    private $previousContainer;
    private $previousResolver;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository([
            'app' => ['locale' => 'en', 'url' => 'http://localhost'],
            'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
        ]));
        $app->instance('request', Request::create('http://localhost/admin/posts/9'));
        $app->instance('files', new \Illuminate\Filesystem\Filesystem());
        foreach ([\Illuminate\Events\EventServiceProvider::class, \Illuminate\Translation\TranslationServiceProvider::class,
            \Illuminate\Routing\RoutingServiceProvider::class, \Illuminate\View\ViewServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app['view']->addNamespace('admin', dirname(__DIR__, 2).'/resources/views');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $resolver = new ConnectionResolver(['review' => new MySqlConnection(static fn () => null, 'review')]);
        $resolver->setDefaultConnection('review');
        Model::setConnectionResolver($resolver);
        Admin::$script = [];
    }

    protected function tearDown(): void
    {
        Admin::$script = [];
        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousContainer);
        Container::setInstance($this->previousContainer);
    }

    public function test_array_builder_only_renders_requested_fields_including_an_empty_list(): void
    {
        $model = (new DetailPost())->forceFill(['id' => 9, 'name' => 'Alice', 'secret' => 'private-token']);
        foreach ([['id', 'name'], ['name' => 'Display name'], []] as $fields) {
            $html = (new Show($model, $fields))->render();
            $this->assertStringNotContainsString('private-token', $html);
            if ($fields) {
                $this->assertStringContainsString('Alice', $html);
            } else {
                $this->assertStringNotContainsString('Alice', $html);
            }
        }
    }

    public function test_dynamic_attribute_accessor_is_rendered_as_a_field(): void
    {
        $show = new Show((new DetailPost())->forceFill(['name' => 'Alice']));
        $show->displayName('Name');
        $this->assertStringContainsString('Display Alice', $show->render());
    }

    public function test_related_panels_do_not_offer_mutations_on_the_parent_resource(): void
    {
        foreach ([new DetailPost(), (new DetailPost())->forceFill(['id' => 3, 'name' => 'Alice'])] as $author) {
            $post = (new DetailPost())->forceFill(['id' => 9])->setRelation('author', $author);
            $html = (new Relation('author', function (Show $show) { $show->field('name'); }))
                ->setModel($post)->render();
            $this->assertStringNotContainsString('fa-trash', $html);
            $this->assertStringNotContainsString('fa-edit', $html);
            $this->assertStringNotContainsString('/admin/posts/3', $html);
        }
        $this->assertSame([], Admin::$script);
    }

    public function test_related_panel_can_explicitly_enable_tools_for_its_own_resource(): void
    {
        $post = (new DetailPost())->forceFill(['id' => 9])
            ->setRelation('author', (new DetailPost())->forceFill(['id' => 3, 'name' => 'Alice']));
        $html = (new Relation('author', function (Show $show) {
            $show->field('name');
            $show->setResource('/admin/authors');
            $show->panel()->tools(function ($tools) { $tools->disableEdit(false)->disableDelete(false); });
        }))->setModel($post)->render();

        $this->assertStringContainsString('http://localhost/admin/authors/3/edit', $html);
        $this->assertStringContainsString("url: 'http://localhost/admin/authors/3'", implode('', Admin::$script));
        $this->assertStringNotContainsString('/admin/posts/3', implode('', Admin::$script));
    }
}

class DetailPost extends Model
{
    protected $hidden = ['secret'];

    public function author() { return $this->belongsTo(self::class, 'author_id'); }

    protected function displayName(): Attribute
    {
        return Attribute::make(get: fn () => 'Display '.$this->name);
    }
}
