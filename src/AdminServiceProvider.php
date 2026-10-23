<?php

namespace Encore\Admin;

use Encore\Admin\Providers\AdminAuthServiceProvider;
use Encore\Admin\Providers\AdminConsoleServiceProvider;
use Encore\Admin\Providers\AdminCoreServiceProvider;
use Encore\Admin\Providers\AdminFrontendServiceProvider;
use Encore\Admin\Providers\AdminRoutingServiceProvider;
use Illuminate\Support\AggregateServiceProvider;

class AdminServiceProvider extends AggregateServiceProvider
{
    /**
     * The provider class names.
     *
     * @var array
     */
    protected $providers = [
        AdminCoreServiceProvider::class,
        AdminAuthServiceProvider::class,
        AdminConsoleServiceProvider::class,
        AdminRoutingServiceProvider::class,
        AdminFrontendServiceProvider::class,
    ];
}
