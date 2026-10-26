<?php

namespace Encore\Admin\Form\Field;

class Color extends Text
{
    protected static $css = [
        '/vendor/laravel-admin/pickr/themes/classic.min.css',
    ];

    protected static $js = [
        '/vendor/laravel-admin/pickr/pickr.min.js',
    ];

    /**
     * Use `hex` format.
     *
     * @return $this
     */
    public function hex()
    {
        return $this->options(['format' => 'hex']);
    }

    /**
     * Use `rgb` format.
     *
     * @return $this
     */
    public function rgb()
    {
        return $this->options(['format' => 'rgb']);
    }

    /**
     * Use `rgba` format.
     *
     * @return $this
     */
    public function rgba()
    {
        return $this->options(['format' => 'rgba']);
    }

    /**
     * Render this filed.
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function render()
    {
        $format = $this->options['format'] ?? 'hex';

        $this->script = <<<SCRIPT
(function () {
    var input = $("{$this->getElementClassSelector()}");
    var format = '{$format}';

    if (!window.Pickr || !input.length) {
        return;
    }

    var picker = Pickr.create({
        el: input.parent()[0],
        theme: 'classic',
        default: input.val() || '#000000',
        swatches: [],
        components: {
            preview: true,
            opacity: format === 'rgba',
            hue: true,
            interaction: {
                hex: true,
                rgba: format !== 'hex',
                input: true,
                clear: true,
                save: true
            }
        }
    });

    picker.on('save', function (color) {
        if (!color) {
            return;
        }

        var value = color.toHEXA().toString();

        if (format === 'rgb') {
            var rgba = color.toRGBA();
            value = 'rgb(' + rgba[0] + ', ' + rgba[1] + ', ' + rgba[2] + ')';
        } else if (format === 'rgba') {
            value = color.toRGBA().toString();
        }

        input.val(value).trigger('change');
        picker.hide();
    });
})();
SCRIPT;

        $this->prepend('<i></i>')
            ->defaultAttribute('style', 'width: 140px');

        return parent::render();
    }
}
