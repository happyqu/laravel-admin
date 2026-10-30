<?php

namespace Encore\Admin\Tests\Form;

use Encore\Admin\Admin;
use Encore\Admin\Form\Field;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class DateFieldsTest extends TestCase
{
    private $previous;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository([
            'app' => ['locale' => 'fr', 'fallback_locale' => 'en'],
            'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
        ]));
        $app->instance('request', \Illuminate\Http\Request::create('/admin/items'));
        $app->instance('session', new \Illuminate\Session\Store('dates', new \Illuminate\Session\ArraySessionHandler(120)));
        $app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $app->register(\Illuminate\Translation\TranslationServiceProvider::class);
        $app->register(\Illuminate\View\ViewServiceProvider::class);
        $app['view']->addNamespace('admin', dirname(__DIR__, 2).'/resources/views');
        $app['view']->share('errors', new \Illuminate\Support\ViewErrorBag());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Admin::$script = [];
        Admin::$html = [];
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previous);
        Container::setInstance($this->previous);
    }

    public function test_all_date_field_assets_exist_and_generated_metadata_matches_runtime(): void
    {
        $metadata = require dirname(__DIR__, 2).'/resources/field-assets.php';
        foreach ([Field\Date::class, Field\Datetime::class, Field\Time::class, Field\Year::class,
            Field\Month::class, Field\DateMultiple::class, Field\DateRange::class,
            Field\DatetimeRange::class, Field\TimeRange::class] as $class) {
            $this->assertSame($class::getAssets(), $metadata[$class]);
            foreach ($class::getAssets() as $files) {
                foreach ($files as $file) {
                    $this->assertStringNotContainsString('moment', $file);
                    $this->assertStringNotContainsString('datetimepicker', $file);
                    $this->assertFileExists(dirname(__DIR__, 2).'/resources/assets/'.substr(parse_url($file, PHP_URL_PATH), strlen('/vendor/laravel-admin/')));
                }
            }
        }
    }

    public function test_multiple_date_field_keeps_format_locale_values_and_width(): void
    {
        $field = (new Field\DateMultiple('dates', ['Dates']))->format('DD/MM/YYYY')->value('28/10/2026, 29/10/2026');
        $html = $field->render();
        $this->assertStringContainsString('adminDatepicker', $field->getScript());
        $this->assertStringContainsString('"format":"DD\/MM\/YYYY"', $field->getScript());
        $this->assertStringContainsString('"locale":"fr"', $field->getScript());
        $this->assertStringContainsString('"mode":"multiple"', $field->getScript());
        $this->assertStringContainsString('28/10/2026, 29/10/2026', $html);
        $this->assertStringContainsString('width: 100%', $html);
        $this->assertStringContainsString('autocomplete="off"', $html);
        $field->options(['locale' => 'zh_TW'])->render();
        $this->assertStringContainsString('"locale":"zh_TW"', $field->getScript());
    }

    public function test_range_fields_preserve_two_inputs_and_submit_empty_bounds_as_null(): void
    {
        foreach ([Field\DateRange::class => 'YYYY-MM-DD', Field\DatetimeRange::class => 'YYYY-MM-DD HH:mm:ss',
            Field\TimeRange::class => 'HH:mm:ss'] as $class => $format) {
            $field = new $class('start', ['end', 'Period']);
            $html = $field->render();
            $this->assertStringContainsString('name="start"', $html);
            $this->assertStringContainsString('name="end"', $html);
            $this->assertStringContainsString('$.admin.datepicker.range(', $field->getScript());
            $this->assertStringContainsString('"format":"'.$format.'"', $field->getScript());
            $this->assertSame(['start' => null, 'end' => '12:00:00'], $field->prepare(['start' => '', 'end' => '12:00:00']));
        }
    }

    public function test_nested_textarea_and_ranges_restore_their_own_old_input(): void
    {
        request()->setLaravelSession(app('session'));
        request()->session()->flashInput([
            'title' => 'Parent title', 'start' => 'Parent start', 'end' => 'Parent end',
            'rows' => ['new_1' => ['title' => 'Child title', 'start' => '2026-10-10', 'end' => '2026-10-20']],
        ]);
        $form = new \Encore\Admin\Form(new class extends \Illuminate\Database\Eloquent\Model {});
        foreach (['dateRange', 'datetimeRange', 'timeRange'] as $type) {
            $table = (new Field\Table('rows', ['Rows', function ($row) use ($type) {
                $row->textarea('title');
                $row->{$type}('start', 'end');
            }]))->setForm($form);
            $doc = new \DOMDocument();
            @$doc->loadHTML($table->render());
            $xpath = new \DOMXPath($doc);
            $this->assertSame('Child title', $xpath->query('//textarea[@name="rows[new_1][title]"]')->item(0)->textContent);
            foreach (['start' => '2026-10-10', 'end' => '2026-10-20'] as $column => $expected) {
                $this->assertSame($expected, $xpath->query('//input[@name="rows[new_1]['.$column.']"]')->item(0)->getAttribute('value'));
            }
        }
    }

    public function test_datetime_range_displays_both_validation_errors(): void
    {
        app()->register(\Illuminate\Validation\ValidationServiceProvider::class);
        $field = (new Field\DatetimeRange('start', ['end', 'Period']))->rules('required', ['required' => 'Please fill :attribute']);
        $validator = $field->getValidator(['start' => '', 'end' => '']);
        $this->assertFalse($validator->passes());
        $bag = new \Illuminate\Support\ViewErrorBag();
        $bag->put('default', $validator->errors());
        app('view')->share('errors', $bag);
        $html = $field->render();
        $this->assertStringContainsString('has-error', $html);
        foreach (['startstart', 'endend'] as $key) {
            $this->assertStringContainsString(e($validator->errors()->first($key)), $html);
        }
    }

    public function test_datalist_escapes_values_and_labels_as_text(): void
    {
        $key = '" onmouseover="alert(1)';
        $label = '</option></datalist><img src=x onerror="alert(1)"><datalist><option>';
        $html = (new Field\Text('city', ['City']))->datalist([$key => $label])->render();
        $doc = new \DOMDocument();
        @$doc->loadHTML($html);
        $options = $doc->getElementsByTagName('option');
        $this->assertSame(0, $doc->getElementsByTagName('img')->length);
        $this->assertSame(1, $options->length);
        $this->assertSame($key, $options->item(0)->getAttribute('value'));
        $this->assertFalse($options->item(0)->hasAttribute('onmouseover'));
        $this->assertSame($label, $options->item(0)->textContent);
    }
}
