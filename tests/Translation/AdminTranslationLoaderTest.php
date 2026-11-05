<?php

namespace Encore\Admin\Tests\Translation;

use Encore\Admin\Translation\AdminTranslationLoader;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

class AdminTranslationLoaderTest extends TestCase
{
    private function translator(string $locale, string $fallback): Translator
    {
        $loader = new AdminTranslationLoader(
            new FileLoader(new Filesystem(), dirname(__DIR__, 2).'/resources/lang')
        );
        $translator = new Translator($loader, $locale);
        $translator->setFallback($fallback);

        return $translator;
    }

    public function test_packaged_locales_never_render_a_raw_key_with_an_unexpected_fallback(): void
    {
        // Every locale ships a partial admin group. Without the merge a host that configures a
        // fallback other than en/zh-CN renders "admin.operation_log_failed" to the administrator.
        foreach (['de' => 'de', 'ja' => 'ja', 'zh-TW' => 'zh-TW', 'ru' => 'ru'] as $locale => $fallback) {
            $translator = $this->translator($locale, $fallback);
            foreach ([
                'admin.action_context_invalid',
                'admin.action_context_expired',
                'admin.operation_log_result',
                'admin.operation_log_failed',
                'admin.operation_log_succeeded',
                'admin.operation_log_redirected',
                'admin.operation_log_completed',
                'admin.force_delete_trashed_only',
            ] as $key) {
                $line = $translator->get($key);
                $this->assertNotSame($key, $line, "locale {$locale} renders the raw key {$key}");
                $this->assertNotSame('', $line, "locale {$locale} has an empty translation for {$key}");
            }
        }
    }

    public function test_locale_specific_translations_win_over_the_english_completion(): void
    {
        // de translates the delete confirmation itself; the English default must not override it.
        $german = require dirname(__DIR__, 2).'/resources/lang/de/admin.php';
        $translator = $this->translator('de', 'de');
        $this->assertSame($german['delete_confirm'], $translator->get('admin.delete_confirm'));
        $this->assertNotSame('Are you sure to delete this item ?', $translator->get('admin.delete_confirm'));
    }

    public function test_unknown_keys_still_fall_through_to_the_configured_fallback(): void
    {
        $translator = $this->translator('de', 'en');
        $this->assertSame('admin.not_a_real_key', $translator->get('admin.not_a_real_key'));
    }

    public function test_english_defaults_cover_every_shipped_locale(): void
    {
        $english = require dirname(__DIR__, 2).'/resources/lang/en/admin.php';
        foreach (glob(dirname(__DIR__, 2).'/resources/lang/*/admin.php') as $file) {
            $locale = basename(dirname($file));
            $translator = $this->translator($locale, 'en');
            foreach (array_keys($english) as $key) {
                if (is_array($english[$key])) {
                    continue;
                }
                $this->assertNotSame('admin.'.$key, $translator->get('admin.'.$key), "locale {$locale} is missing {$key}");
            }
        }
    }
}
