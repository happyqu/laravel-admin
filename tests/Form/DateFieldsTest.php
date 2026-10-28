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
                    $this->assertFileExists(dirname(__DIR__, 2).'/resources/assets/'.substr($file, strlen('/vendor/laravel-admin/')));
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
}
