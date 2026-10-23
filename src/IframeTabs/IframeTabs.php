<?php

namespace Encore\Admin\IframeTabs;

use Encore\Admin\Admin;
use Illuminate\Support\Facades\Route;

class IframeTabs
{
    public static $manifestData = [];

    /**
     * Reset request-scoped manifest data.
     */
    public static function reset(): void
    {
        static::$manifestData = [];
    }

    public static function config($key = null, $default = null)
    {
        return config('admin.iframe_tabs'.($key === null ? '' : '.'.$key), $default);
    }

    public static function boot()
    {
        return static::config('enable', true) !== false;
    }

    public static function routes($routes)
    {
        Route::group(array_merge([
            'prefix' => config('admin.route.prefix'),
            'middleware' => config('admin.route.middleware'),
        ], static::config('route', [])), $routes);
    }

    /** Initialize default menu and permission during a fresh installation. */
    public static function initialize()
    {
        $menuModel = config('admin.database.menu_model');
        $permissionModel = config('admin.database.permissions_model');
        if ($menu = $menuModel::where('uri', '/')->first()) {
            $menu->update(['uri' => 'dashboard']);
        }
        $permissionModel::firstOrCreate(['slug' => 'tabs.dashboard'], [
            'name' => 'Tab-dashboard', 'http_path' => '/dashboard', 'http_method' => '',
        ]);
    }

    public static function fixMinify()
    {
        if (!static::isMinify()) {
            return;
        }
        Admin::$baseJs = Admin::$baseCss = Admin::$css =  Admin::$js = [];

        Admin::js(static::getManifestData('js'));
        Admin::css(static::getManifestData('css'));
    }

    public static function isMinify()
    {
        if (!isset(Admin::$manifest)) {
            return false;
        }

        if (!config('admin.minify_assets.enabled') || !file_exists(public_path(Admin::$manifest))) {
            return false;
        }

        return true;
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    public static function getManifestData($key)
    {
        if (!empty(static::$manifestData)) {
            return static::$manifestData[$key];
        }

        static::$manifestData = json_decode(
            file_get_contents(public_path(Admin::$manifest)),
            true
        );

        return static::$manifestData[$key];
    }
}
