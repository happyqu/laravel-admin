<?php

namespace Encore\Admin\Cascader;

/** A complete local tree, with value/label/leaf/disabled/children on each node. */
interface TreeSource
{
    public function tree(): array;
}
