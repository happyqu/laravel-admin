<?php

namespace Encore\Admin\Form\Field;

class Year extends Date
{
    protected $format = 'Y';

    protected $nativeInput = true;
}
