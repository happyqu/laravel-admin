<?php

namespace Encore\Admin\Grid\Filter\Presenter;

use Encore\Admin\Admin;
use Illuminate\Support\Arr;

class DateTime extends Presenter
{
    /**
     * @var array
     */
    protected $options = [];

    /**
     * @var string
     */
    protected $format = 'YYYY-MM-DD HH:mm:ss';

    /**
     * DateTime constructor.
     *
     * @param array $options
     */
    public function __construct($options = [])
    {
        $this->options = $this->getOptions($options);
    }

    /**
     * @param array $options
     *
     * @return mixed
     */
    protected function getOptions(array $options): array
    {
        $options['format'] = Arr::get($options, 'format', $this->format);
        $options['locale'] = Arr::get($options, 'locale', config('app.locale'));

        return $options;
    }

    protected function prepare()
    {
        $format = strtr($this->format, [
            'YYYY' => 'Y', 'YY' => 'y', 'MM' => 'm', 'DD' => 'd',
            'HH' => 'H', 'mm' => 'i', 'ss' => 'S',
        ]);

        $options = [
            'dateFormat'    => $format,
            'allowInput'    => true,
            'enableTime'    => str_contains($format, 'H'),
            'time_24hr'     => true,
            'enableSeconds' => str_contains($format, 'S'),
            'locale'        => str_starts_with((string) config('app.locale'), 'zh') ? 'zh' : 'default',
        ];

        $id = json_encode($this->filter->getId());
        $options = json_encode($options);

        Admin::css('vendor/laravel-admin/flatpickr/dist/flatpickr.min.css');
        Admin::js('vendor/laravel-admin/flatpickr/dist/flatpickr.js');
        Admin::js('vendor/laravel-admin/flatpickr/dist/l10n/zh.js');

        $script = <<<SCRIPT
(function () {
    var element = document.getElementById({$id});
    if (element && window.flatpickr) {
        flatpickr(element, {$options});
    }
})();
SCRIPT;

        Admin::script($script);
    }

    public function variables(): array
    {
        $this->prepare();

        return [
            'group' => $this->filter->group,
        ];
    }
}
