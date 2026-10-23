<?php

namespace Encore\Admin\Providers;

use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;

class AdminAuthServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->loadAdminAuthConfig();
    }

    protected function loadAdminAuthConfig()
    {
        config(Arr::dot(config('admin.auth', []), 'auth.'));
    }
}
