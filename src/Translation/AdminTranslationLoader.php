<?php

namespace Encore\Admin\Translation;

use Illuminate\Contracts\Translation\Loader;

/** Complete the package admin group from its own defaults, then from English. */
class AdminTranslationLoader implements Loader
{
    public function __construct(private Loader $loader)
    {
    }

    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->loader->load($locale, $group, $namespace);

        if ($group !== 'admin' || ($namespace !== null && $namespace !== '*')) {
            return $lines;
        }

        return $this->mergeDefaults($this->defaults($locale), is_array($lines) ? $lines : []);
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

    /**
     * Package defaults for a locale, completed from English so that languages with a partial
     * translation file never render a raw key when the configured fallback locale differs.
     */
    private function defaults(string $locale): array
    {
        $english = $this->loadDefaults('en');
        if ($locale === 'en') {
            return $english;
        }

        return $this->mergeDefaults($english, $this->loadDefaults($locale));
    }

    private function loadDefaults(string $locale): array
    {
        static $loaded = [];

        if (!isset($loaded[$locale])) {
            $file = __DIR__.'/../../resources/lang/'.$locale.'/admin.php';
            $loaded[$locale] = is_file($file) ? (array) require $file : [];
        }

        return $loaded[$locale];
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
