<?php

namespace Encore\Admin\Tests\Assets;

use Encore\Admin\Admin;
use PHPUnit\Framework\TestCase;

class AssetOrderTest extends TestCase
{
    private function exists(string $asset): bool
    {
        $prefix = 'vendor/laravel-admin/';

        return str_starts_with($asset, $prefix)
            && is_file(dirname(__DIR__, 2).'/resources/assets/'.substr($asset, strlen($prefix)));
    }

    public function test_base_css_uses_adminlte4_and_has_no_adminlte2_assets(): void
    {
        $css = Admin::baseCss();

        $this->assertSame('vendor/laravel-admin/adminlte4/css/adminlte.min.css', $css[0]);
        $this->assertContains('vendor/laravel-admin/laravel-admin/themes.css', $css);

        foreach ($css as $asset) {
            $this->assertStringNotContainsString('AdminLTE/', $asset);
            $this->assertStringNotContainsString('bootstrap3-editable', $asset);
            $this->assertTrue($this->exists($asset), "Missing CSS asset: {$asset}");
        }
    }

    public function test_base_js_loads_bootstrap_and_adminlte_in_order(): void
    {
        $js = Admin::baseJs();

        $bootstrap = array_search('vendor/laravel-admin/bootstrap5/js/bootstrap.bundle.min.js', $js, true);
        $adminlte = array_search('vendor/laravel-admin/adminlte4/js/adminlte.min.js', $js, true);
        $adminUi = array_search('vendor/laravel-admin/laravel-admin/admin-ui.js', $js, true);
        $core = array_search('vendor/laravel-admin/laravel-admin/laravel-admin.js', $js, true);

        $this->assertNotFalse($bootstrap);
        $this->assertNotFalse($adminlte);
        $this->assertNotFalse($adminUi);
        $this->assertNotFalse($core);
        $this->assertLessThan($adminlte, $bootstrap);
        $this->assertLessThan($adminUi, $adminlte);
        $this->assertLessThan($core, $adminUi);

        foreach ($js as $asset) {
            $this->assertStringNotContainsString('AdminLTE/', $asset);
            $this->assertStringNotContainsString('slimScroll', $asset);
            $this->assertTrue($this->exists($asset), "Missing JS asset: {$asset}");
        }
    }

    public function test_jquery_is_v3(): void
    {
        $this->assertSame('vendor/laravel-admin/jquery/jquery-3.7.1.min.js', Admin::$jQuery);
        $this->assertTrue($this->exists(Admin::$jQuery));
    }
}
