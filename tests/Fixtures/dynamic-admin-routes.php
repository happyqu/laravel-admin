<?php

if (config('test.route_load_error')) {
    throw new RuntimeException('Route registration failed.');
}
if (request()->segment(2) === 'settings' && config('test.source_enabled', true)) {
    \Illuminate\Support\Facades\Route::get('admin/settings', function () {
        config(['test.source_executed' => true]);
    })->middleware('admin.permission:check,manage-settings');
}
