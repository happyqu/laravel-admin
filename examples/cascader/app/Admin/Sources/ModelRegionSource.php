<?php

namespace App\Admin\Sources;

use App\Models\City;
use App\Models\District;
use App\Models\Province;
use Encore\Admin\Cascader\ModelSource;

/** Optional Model-backed alternative to the package's built-in RegionSource. */
class ModelRegionSource extends ModelSource
{
    protected function levels(): array
    {
        return [
            ['model' => Province::class],
            ['model' => City::class, 'parent' => 'province_id'],
            ['model' => District::class, 'parent' => 'city_id'],
        ];
    }
}
