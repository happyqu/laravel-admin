<?php

namespace Encore\Admin\Grid\Displayers;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

class Date extends AbstractDisplayer
{
    public function display($format = 'Y-m-d')
    {
        return e($this->formatValue($format));
    }

    /**
     * Keep invalid values inspectable and never turn an empty value into today.
     */
    protected function formatValue($format, bool $iso = false): string
    {
        $value = $this->getValue();
        if ($value === null || $value === '') {
            return '';
        }

        $timezone = config('app.timezone') ?: date_default_timezone_get();
        $locale = config('app.locale', 'en');

        if ($iso && is_scalar($value)) {
            // A year, month, or localized date may already use the editor's format.
            try {
                $date = Carbon::createFromIsoFormat('!'.$format, (string) $value, $timezone, $locale);
                if ($date && $date->locale($locale)->isoFormat($format) === (string) $value) {
                    return (string) $value;
                }
            } catch (InvalidFormatException $exception) {
                // Fall back to the database timestamp format below.
            }
        }

        try {
            $date = Carbon::parse($value, $timezone);
            $errors = Carbon::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
                return (string) $value;
            }
            $date->setTimezone($timezone);

            return $iso ? $date->locale($locale)->isoFormat($format) : $date->format($format);
        } catch (InvalidFormatException $exception) {
            return (string) $value;
        }
    }
}
