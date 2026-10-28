<?php

namespace Encore\Admin\Support;

class Url
{
    /** Allow navigation links without executable schemes or embedded control characters. */
    public static function sanitizeHref($value): ?string
    {
        $href = trim((string) $value);

        if (preg_match('/[\x00-\x20\x7f]/', $href)
            || (preg_match('/^([a-z][a-z0-9+.-]*):/i', $href, $matches)
                && !in_array(strtolower($matches[1]), ['http', 'https', 'mailto', 'tel'], true))) {
            return null;
        }

        return $href;
    }
}
