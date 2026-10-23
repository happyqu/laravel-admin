<?php

namespace Encore\Admin\Traits;

use Carbon\Carbon;

trait DefaultDatetimeFormat
{
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format(Carbon::DEFAULT_TO_STRING_FORMAT);
    }
}
