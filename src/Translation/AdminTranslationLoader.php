<?php

namespace Encore\Admin\Translation;

use Illuminate\Contracts\Translation\Loader;

/** Add package defaults only when the root admin group is requested. */
class AdminTranslationLoader implements Loader
{
    public function __construct(private Loader $loader)
    {
    }

    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->loader->load($locale, $group, $namespace);

        if ($group !== 'admin' || ($namespace !== null && $namespace !== '*')
            || !in_array($locale, ['en', 'zh-CN'], true)) {
            return $lines;
        }

        $defaults = require __DIR__.'/../../resources/lang/'.$locale.'/admin.php';

        return $this->mergeDefaults($defaults, $lines);
    }

    public function addNamespace($namespace, $hint)
    {
        $this->loader->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->loader->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->loader->namespaces();
    }

    public function __call($method, $parameters)
    {
        return $this->loader->{$method}(...$parameters);
    }

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
