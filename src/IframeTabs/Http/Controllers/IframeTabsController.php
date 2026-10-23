<?php

namespace Encore\Admin\IframeTabs\Http\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\IframeTabs\IframeTabs;
use Illuminate\Routing\Controller;
use \Encore\Admin\Widgets\Navbar;

class IframeTabsController extends Controller
{
    public function index(Content $content)
    {
        if (!IframeTabs::boot()) {
            return redirect(admin_base_path('dashboard'));
        }

        $items = [
            'header' => '',
            'trans' => [
                'oprations' => trans('admin.iframe_tabs.oprations'),
                'refresh_current' => trans('admin.iframe_tabs.refresh_current'),
                'close_current' => trans('admin.iframe_tabs.close_current'),
                'close_all' => trans('admin.iframe_tabs.close_all'),
                'close_other' => trans('admin.iframe_tabs.close_other'),
                'open_in_new' => trans('admin.iframe_tabs.open_in_new'),
                'open_in_pop' => trans('admin.iframe_tabs.open_in_pop'),
                'scroll_left' => trans('admin.iframe_tabs.scroll_left'),
                'scroll_right' => trans('admin.iframe_tabs.scroll_right'),
                'scroll_current' => trans('admin.iframe_tabs.scroll_current'),
                'refresh_succeeded' => trans('admin.refresh_succeeded'),
            ],
            'home_uri' => admin_base_path('dashboard'),
            'home_title' => IframeTabs::config('home_title', 'Index'),
            'home_icon' => IframeTabs::config('home_icon', 'fa-home'),
            'use_icon' => IframeTabs::config('use_icon', true) ? '1' : '',
            'pass_urls' => implode(',', IframeTabs::config('pass_urls', ['/auth/logout'])),
            'iframes_index' => admin_url(),
            'tabs_left' => IframeTabs::config('tabs_left', '42'),
            'bind_urls' => IframeTabs::config('bind_urls', 'none'),
            'bind_selecter' => IframeTabs::config('bind_selecter', '.box-body table.table tbody a.grid-row-view,.box-body table.table tbody a.grid-row-edit,.box-header .pull-right .btn-success'),
        ];

        \View::share($items);

        Admin::navbar(function (Navbar $navbar) {
            $navbar->left(view('admin::iframe-tabs.ext.tabs'));
            $navbar->right(view('admin::iframe-tabs.ext.options'));
        });

        return $content;
    }

    public function dashboard(Content $content)
    {
        return $content
            ->header('Dashboard')
            ->description('Laravel Admin')
            ->body(view('admin::package-dashboard'));
    }
}
