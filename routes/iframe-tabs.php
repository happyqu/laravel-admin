<?php

use Encore\Admin\IframeTabs\Http\Controllers\IframeTabsController;
use Encore\Admin\IframeTabs\IframeTabs;

Route::get('/', IframeTabsController::class.'@index')->name('iframes.index');

Route::get('/dashboard', IframeTabs::config('home_action', IframeTabsController::class.'@dashboard'))->name('iframes.dashboard');
