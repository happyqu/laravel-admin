<?php

namespace Encore\Admin\Tests\Cascader;

use Encore\Admin\Cascader\ArraySource;

class FixtureSource extends ArraySource
{
    protected function options(): array
    {
        if (!empty($this->context['empty'])) {
            return [];
        }

        return [
            ['value' => '0', 'label' => 'Zero province', 'children' => [
                ['value' => '1', 'label' => '<b>City</b>', 'children' => [
                    ['value' => '2', 'label' => 'District'],
                ]],
            ]],
            ['value' => '3', 'label' => 'Other province', 'children' => [
                ['value' => '1', 'label' => 'Same local ID', 'children' => [
                    ['value' => '4', 'label' => 'Other district'],
                ]],
            ]],
            ['value' => '5', 'label' => 'Blocked', 'disabled' => true, 'children' => [
                ['value' => '6', 'label' => 'Blocked child'],
            ]],
        ];
    }
}
