<?php

namespace Encore\Admin\Form\Field;

class Month extends Date
{
    protected $format = 'Y-m';

    protected $nativeInput = true;
}
