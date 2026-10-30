<?php

namespace Encore\Admin\Providers;

use Encore\Admin\Actions;
use Encore\Admin\Form;
use Encore\Admin\Translation\AdminTranslationLoader;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\ServiceProvider;

class AdminCoreServiceProvider extends ServiceProvider
{
    /**
     * Register core services and configuration.
     */
    public function register()
    {
        $this->app->scoped(Actions\Context::class);
        $this->app->scoped(Form\UploadTransaction::class);

        $this->app['events']->listen(
            \Illuminate\Database\Events\TransactionRolledBack::class,
            function ($event) {
                $this->app->make(Form\UploadTransaction::class)->rolledBack($event);
            }
        );

        $this->app->extend('translation.loader', function (Loader $loader) {
            return new AdminTranslationLoader($loader);
        });

        $this->mergeAdminConfiguration();
        if (config('admin.log_viewer.enabled', true)) {
            \Encore\Admin\Admin::extend('log-viewer', \Encore\Admin\LogViewer\LogViewer::class);
        }
        $this->registerPublishing();
    }

    /**
     * Register translations when the translator resolves.
     */
    public function boot()
    {
        \Encore\Admin\Rules\Password::register();
        $this->registerTranslations();
    }

    protected function mergeAdminConfiguration()
    {
        static $defaults;

        $defaults ??= require __DIR__.'/../../config/admin.php';

        $this->app['config']->set('admin', $this->mergeDefaults(
            $defaults,
            $this->app['config']->get('admin', [])
        ));

        if ($this->app['config']->get('filesystems.disks.admin') === null) {
            $this->app['config']->set('filesystems.disks.admin', [
                'driver' => 'local',
                'root' => public_path('uploads'),
                'url' => rtrim($this->app['config']->get('app.url', ''), '/').'/uploads',
                'visibility' => 'public',
            ]);
        }
    }

    protected function registerTranslations()
    {
        $this->loadTranslationsFrom(__DIR__.'/../../resources/lang', 'admin');

        $this->callAfterResolving('translator', function ($translator) {
            if ($translator->getLoader() instanceof AdminTranslationLoader) {
                return;
            }

            // A translator constructed before package registration still holds its original loader.
            foreach (['en', 'zh-CN'] as $locale) {
                $fallbacks = require __DIR__.'/../../resources/lang/'.$locale.'/admin.php';
                $existing = $translator->get('admin', [], $locale, false);
                $lines = [];
                foreach ($this->mergeDefaults($fallbacks, is_array($existing) ? $existing : []) as $key => $value) {
                    $lines['admin.'.$key] = $value;
                }
                $translator->addLines($lines, $locale);
            }
        });
    }

    protected function registerPublishing()
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([__DIR__.'/../../config' => config_path()], 'laravel-admin-config');
        $this->publishes([__DIR__.'/../../resources/lang' => $this->app->langPath()], 'laravel-admin-lang');
        $this->publishes([__DIR__.'/../../database/migrations' => database_path('migrations')], 'laravel-admin-migrations');
        $this->publishes([__DIR__.'/../../resources/assets' => public_path('vendor/laravel-admin')], 'laravel-admin-assets');
        $this->publishes([__DIR__.'/../../resources/assets' => public_path('vendor/laravel-admin')], 'happy-admin-assets');
    }

    /** Merge maps recursively, but replace lists and explicit empty arrays. */
    private function mergeDefaults(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && $value !== [] && !array_is_list($value)
                && isset($defaults[$key]) && is_array($defaults[$key]) && !array_is_list($defaults[$key])) {
                $defaults[$key] = $this->mergeDefaults($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }
}
