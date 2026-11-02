<?php

namespace Encore\Admin\Tests\Grid;

use Encore\Admin\Admin;
use Encore\Admin\Grid;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class DateDisplayTest extends TestCase
{
    private $previousContainer;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository([
            'app' => ['locale' => 'en', 'timezone' => 'Asia/Shanghai'],
            'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()],
        ]));
        $app->instance('request', Request::create('http://localhost/admin/records'));
        $app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $app->register(\Illuminate\Translation\TranslationServiceProvider::class);
        $app->register(\Illuminate\View\ViewServiceProvider::class);
        $app['view']->addNamespace('admin', dirname(__DIR__, 2).'/resources/views');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Grid\Column::resetState();
        Admin::$script = [];
        Admin::$html = [];
    }

    protected function tearDown(): void
    {
        Grid\Column::resetState();
        Admin::$script = [];
        Admin::$html = [];
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousContainer);
        Container::setInstance($this->previousContainer);
    }

    private function renderDate($value, string $method, array $arguments = []): string
    {
        $row = (new DateDisplayRecord())->forceFill(['id' => 7]);
        Grid\Column::setOriginalGridModels(collect([$row]));
        $grid = (new Grid(new DateDisplayRecord()))->setResource('/admin/records');
        $column = $grid->column('created_at', 'Created')->{$method}(...$arguments);

        return $column->fill([['created_at' => $value]])[0]['created_at'];
    }

    private function editor(string $html): array
    {
        $dom = new \DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
        $xpath = new \DOMXPath($dom);
        $trigger = $xpath->query('//a[@data-toggle="popover"]')->item(0);

        return [
            $xpath->query('//span[@class="ie-display"]')->item(0)->textContent,
            $trigger->getAttribute('data-value'),
            $trigger->getAttribute('data-original'),
        ];
    }

    public function test_explicit_php_format_renders_a_read_only_date_in_the_application_timezone(): void
    {
        foreach (['Y-m-d' => '2026-11-02', 'Y/m/d' => '2026/11/02', 'd.m.Y' => '02.11.2026'] as $format => $expected) {
            $html = $this->renderDate('2026-11-01T16:30:00.000000Z', 'date', [$format]);
            $this->assertSame($expected, $html);
            $this->assertStringNotContainsString('data-toggle="popover"', $html);
        }
        $this->assertSame([], Admin::$script);
    }

    public function test_legacy_inline_pickers_format_the_display_and_initial_editor_value(): void
    {
        foreach ([
            ['date', [], '2026-11-02'],
            ['datetime', [], '2026-11-02 12:34:56'],
            ['datetime', ['DD/MM/YYYY HH:mm'], '02/11/2026 12:34'],
            ['time', [], '12:34:56'],
            ['year', [], '2026'],
            ['month', [], '11'],
            ['day', [], '02'],
        ] as [$method, $arguments, $expected]) {
            $html = $this->renderDate('2026-11-02 12:34:56', $method, $arguments);
            $this->assertSame([$expected, $expected, $expected], $this->editor($html));
        }
        $html = $this->renderDate('2026-11-01T16:30:00.000000Z', 'date');
        $this->assertSame(['2026-11-02', '2026-11-02', '2026-11-02'], $this->editor($html));
    }

    public function test_empty_and_invalid_values_do_not_become_today_or_executable_html(): void
    {
        foreach ([null, ''] as $value) {
            $this->assertSame('', $this->renderDate($value, 'date', ['Y-m-d']));
            $this->assertSame(['', '', ''], $this->editor($this->renderDate($value, 'date')));
        }
        foreach (['not-a-date', '2026-02-30', '<img src=x onerror=alert(1)>'] as $value) {
            $this->assertSame(e($value), $this->renderDate($value, 'date', ['Y-m-d']));
            $html = $this->renderDate($value, 'date');
            $this->assertSame([$value, $value, $value], $this->editor($html));
            $this->assertStringNotContainsString('<img', $html);
        }
    }

    public function test_inline_pickers_preserve_already_formatted_partial_dates(): void
    {
        foreach ([['year', '2025'], ['month', '07'], ['day', '09'], ['time', '01:02:03']] as [$method, $value]) {
            $this->assertSame([$value, $value, $value], $this->editor($this->renderDate($value, $method)));
        }
        $html = $this->renderDate('01/02/2025 03:04', 'datetime', ['DD/MM/YYYY HH:mm']);
        $this->assertSame(['01/02/2025 03:04', '01/02/2025 03:04', '01/02/2025 03:04'], $this->editor($html));
    }
}

class DateDisplayRecord extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s';
}
