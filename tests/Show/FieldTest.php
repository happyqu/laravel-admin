<?php

namespace Encore\Admin\Tests\Show;

use Encore\Admin\Show;
use Encore\Admin\Show\Field;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\ViewServiceProvider;
use PHPUnit\Framework\TestCase;

class FieldTest extends TestCase
{
    private $previousContainer;
    private Application $app;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Repository([
            'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
        ]));
        $this->app->instance('files', new Filesystem());
        $this->app->register(\Illuminate\Events\EventServiceProvider::class);
        $this->app->register(ViewServiceProvider::class);
        $this->app['view']->addNamespace('admin', dirname(__DIR__, 2).'/resources/views');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousContainer);
        Container::setInstance($this->previousContainer);
    }

    private function render(Field $field, Model $model): string
    {
        $show = $this->createMock(Show::class);
        $show->method('getModel')->willReturn($model);

        return $field->setParent($show)->setValue($model)->render()->render();
    }

    private function model($value): Model
    {
        return (new FieldTestModel())->forceFill(['value' => $value]);
    }

    public function test_links_reject_executable_and_obfuscated_schemes(): void
    {
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', "java\nscript:alert(1)", "java\tscript:alert(1)", 'data:text/html,<script>alert(1)</script>', 'vbscript:msgbox(1)'] as $url) {
            $html = $this->render((new Field('value'))->link('', '_self'), $this->model($url));
            $this->assertStringNotContainsString('<a ', $html);
            $this->assertStringContainsString(e($url), $html);

            $html = $this->render((new Field('value'))->link($url), $this->model('Click'));
            $this->assertStringNotContainsString('<a ', $html);
        }
    }

    public function test_links_keep_safe_urls_and_escape_attributes_and_text(): void
    {
        foreach (['https://example.com/?a=1&b=2', 'http://example.com', 'mailto:a@example.com', 'tel:+123', '/admin/users', '../users', '#details', '//example.com'] as $url) {
            $html = $this->render((new Field('value'))->link($url), $this->model('<b>Click</b>'));
            $this->assertStringContainsString("href='".e($url)."'", $html);
            $this->assertStringContainsString('&lt;b&gt;Click&lt;/b&gt;', $html);
        }
    }

    public function test_chained_relation_fields_read_the_selected_attribute(): void
    {
        $author = (new FieldTestModel())->forceFill(['name' => 'Alice', 'secret' => 'private-data']);
        $model = (new FieldTestModel())->setRelation('author', $author);
        $field = (new Field('author'))->setRelation('author')->name();
        $html = $this->render($field, $model);
        $this->assertStringContainsString('Alice', $html);
        $this->assertStringNotContainsString('private-data', $html);

        $model->setRelation('author', null);
        $this->assertStringNotContainsString('Alice', $this->render($field, $model));
    }

    public function test_deep_relation_fields_read_the_leaf_and_allow_missing_relations(): void
    {
        $company = (new FieldTestModel())->forceFill(['name' => 'Acme', 'secret' => 'private-data']);
        $author = (new FieldTestModel())->setRelation('company', $company);
        $model = (new FieldTestModel())->setRelation('author', $author);
        $field = new Field('author.company.name');

        $html = $this->render($field, $model);
        $this->assertStringContainsString('Acme', $html);
        $this->assertStringNotContainsString('private-data', $html);
        $this->assertStringNotContainsString('&quot;name&quot;', $html);

        $author->setRelation('company', null);
        $this->assertStringNotContainsString('Acme', $this->render($field, $model));
        $model->setRelation('author', null);
        $this->assertStringNotContainsString('Acme', $this->render($field, $model));
    }

    public function test_unselected_relation_still_passes_the_model_to_callbacks(): void
    {
        $author = (new FieldTestModel())->forceFill(['name' => 'Alice']);
        $model = (new FieldTestModel())->setRelation('author', $author);
        $test = $this;
        $field = (new Field('author'))->setRelation('author')->as(function ($value) use ($author, $test) {
            $test->assertSame($author, $value);
            return $value->name;
        });
        $this->assertStringContainsString('Alice', $this->render($field, $model));
    }

    public function test_json_arrays_do_not_inherit_previous_decode_errors(): void
    {
        json_decode('{');
        $html = $this->render((new Field('value'))->json(), $this->model(['name' => '<script>']));
        $this->assertStringContainsString('<pre><code>', $html);
        $this->assertStringContainsString('name', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_json_preserves_invalid_text_and_handles_encoding_failure(): void
    {
        $html = $this->render((new Field('value'))->json(), $this->model('<invalid>'));
        $this->assertStringContainsString('&lt;invalid&gt;', $html);
        $this->assertStringNotContainsString('<pre>', $html);
        $html = $this->render((new Field('value'))->json(), $this->model(['invalid' => "\xB1"]));
        $this->assertStringNotContainsString('<pre>', $html);
    }

    public function test_formatters_support_all_callable_forms_and_keep_closure_model_binding(): void
    {
        $object = new class {
            public function format($value) { return strtoupper($value); }
            public function __invoke($value) { return strtoupper($value); }
        };
        foreach (['strtoupper', [$object, 'format'], $object, static fn ($value) => strtoupper($value)] as $callback) {
            $field = (new Field('value'))->as($callback)->as(function ($value) {
                return $value.'-'.$this->suffix;
            });
            $model = $this->model('alice')->forceFill(['suffix' => 'bound']);
            $this->assertStringContainsString('ALICE-bound', $this->render($field, $model));
        }
    }
}

class FieldTestModel extends Model
{
}
