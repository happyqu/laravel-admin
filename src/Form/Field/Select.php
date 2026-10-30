<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Form\Field;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class Select extends Field
{
    use CanCascadeFields;
    use HasChoiceInput;

    /**
     * @var array
     */
    protected static $css = [
        '/vendor/laravel-admin/AdminLTE/plugins/select2/select2.min.css',
    ];

    /**
     * @var array
     */
    protected static $js = [
        '/vendor/laravel-admin/AdminLTE/plugins/select2/select2.full.min.js',
    ];

    /**
     * @var array
     */
    protected $groups = [];

    /**
     * @var array
     */
    protected $config = [];

    /**
     * @var string
     */
    protected $cascadeEvent = 'change';

    /**
     * Set options.
     *
     * @param array|callable|string $options
     *
     * @return $this|mixed
     */
    public function options($options = [])
    {
        // remote options
        if (is_string($options)) {
            // reload selected
            if (class_exists($options) && in_array(Model::class, class_parents($options))) {
                return $this->model(...func_get_args());
            }

            return $this->loadRemoteOptions(...func_get_args());
        }

        if ($options instanceof Arrayable) {
            $options = $options->toArray();
        }

        if (is_callable($options)) {
            $this->options = $options;
        } else {
            $this->options = (array) $options;
        }

        return $this;
    }

    /**
     * @param array $groups
     */

    /**
     * Set option groups.
     *
     * eg: $group = [
     *        [
     *        'label' => 'xxxx',
     *        'options' => [
     *            1 => 'foo',
     *            2 => 'bar',
     *            ...
     *        ],
     *        ...
     *     ]
     *
     * @param array $groups
     *
     * @return $this
     */
    public function groups(array $groups)
    {
        $this->groups = $groups;

        return $this;
    }

    /**
     * Load options for other select on change.
     *
     * @param string $field
     * @param string $sourceUrl
     * @param string $idField
     * @param string $textField
     *
     * @return $this
     */
    public function load($field, $sourceUrl, $idField = 'id', $textField = 'text', bool $allowClear = true)
    {
        $eventRoot = $this->elementScope === null ? '$(document)' : '$("'.$this->getElementClassSelector().'").closest("form")';
        if (Str::contains($field, '.')) {
            $field = $this->formatName($field);
            $class = str_replace(['[', ']'], '_', $field);
        } else {
            $class = $field;
        }

        $placeholder = json_encode([
            'id'   => '',
            'text' => trans('admin.choose'),
        ]);

        $strAllowClear = var_export($allowClear, true);

        $script = <<<EOT
{$eventRoot}.off('change.adminPage', "{$this->getElementClassSelector()}");
{$eventRoot}.on('change.adminPage', "{$this->getElementClassSelector()}", function () {
    var source = this, value = this.value;
    $(this).closest('.fields-group').find(".$class").each(function () {
    var target = $(this), request = {};
    target.data('adminSelectRequest', request);
    $.get("$sourceUrl",{q : value}, function (data) {
        if (target.data('adminSelectRequest') !== request || source.value !== value || !document.documentElement.contains(target[0])) return;
        target.find("option").remove();
        $(target).select2({
            placeholder: $placeholder,
            allowClear: $strAllowClear,
            data: $.map(data, function (d) {
                d.id = d.$idField;
                d.text = d.$textField;
                return d;
            })
        });
        var selected = target.attr('data-value');
        if (selected !== undefined) target.val(selected === '' ? null : selected.split(','));
        $(target).trigger('change');
    });
    });
});
EOT;

        Admin::script($script);

        return $this;
    }

    /**
     * Load options for other selects on change.
     *
     * @param array  $fields
     * @param array  $sourceUrls
     * @param string $idField
     * @param string $textField
     *
     * @return $this
     */
    public function loads($fields = [], $sourceUrls = [], $idField = 'id', $textField = 'text', bool $allowClear = true)
    {
        $eventRoot = $this->elementScope === null ? '$(document)' : '$("'.$this->getElementClassSelector().'").closest("form")';
        $fieldsStr = implode('.', $fields);
        $urlsStr = implode('^', $sourceUrls);

        $placeholder = json_encode([
            'id'   => '',
            'text' => trans('admin.choose'),
        ]);

        $strAllowClear = var_export($allowClear, true);

        $script = <<<EOT
;(function () {
var fields = '$fieldsStr'.split('.');
var urls = '$urlsStr'.split('^');

var refreshOptions = function(url, targets, source, value) {
    targets.each(function () {
    var target = $(this), request = {};
    target.data('adminSelectRequest', request);
    $.get(url).then(function(data) {
        if (target.data('adminSelectRequest') !== request || source.value !== value || !document.documentElement.contains(target[0])) return;
        target.find("option").remove();
        $(target).select2({
            placeholder: $placeholder,
            allowClear: $strAllowClear,
            data: $.map(data, function (d) {
                d.id = d.$idField;
                d.text = d.$textField;
                return d;
            })
        }).trigger('change');
    });
    });
};

{$eventRoot}.off('change.adminPage', "{$this->getElementClassSelector()}");
{$eventRoot}.on('change.adminPage', "{$this->getElementClassSelector()}", function () {
    var _this = this;
    var promises = [];

    fields.forEach(function(field, index){
        var target = $(_this).closest('.fields-group').find('.' + fields[index]);
        var url = urls[index] + (urls[index].indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(_this.value);
        promises.push(refreshOptions(url, target, _this, _this.value));
    });
});
})();
EOT;

        Admin::script($script);

        return $this;
    }

    /**
     * Load options from current selected resource(s).
     *
     * @param string $model
     * @param string $idField
     * @param string $textField
     *
     * @return $this
     */
    public function model($model, $idField = 'id', $textField = 'name')
    {
        if (!class_exists($model)
            || !in_array(Model::class, class_parents($model))
        ) {
            throw new \InvalidArgumentException("[$model] must be a valid model class");
        }

        $this->options = function ($value) use ($model, $idField, $textField) {
            if ($value instanceof Arrayable) {
                $value = $value->toArray();
            }

            if ($value === null || $value === '' || $value === []) {
                return [];
            }

            $resources = [];

            if (is_array($value)) {
                if (array_key_exists($idField, $value) && !is_array($value[$idField])) {
                    $resources[] = Arr::get($value, $idField);
                } elseif (is_array(reset($value)) || reset($value) instanceof Model) {
                    $resources = collect($value)->pluck($idField)->all();
                } else {
                    $resources = array_values($value);
                }
            } else {
                $resources[] = $value;
            }

            return $model::find($resources)->pluck($textField, $idField)->toArray();
        };

        return $this;
    }

    /**
     * Load options from remote.
     *
     * @param string $url
     * @param array  $parameters
     * @param array  $options
     *
     * @return $this
     */
    protected function loadRemoteOptions($url, $parameters = [], $options = [])
    {
        $ajaxOptions = [
            'url' => $url.'?'.http_build_query($parameters),
        ];
        $configs = array_merge([
            'allowClear'         => true,
            'placeholder'        => [
                'id'        => '',
                'text'      => trans('admin.choose'),
            ],
        ], $this->config);

        $configs = json_encode($configs);
        $configs = substr($configs, 1, strlen($configs) - 2);

        $ajaxOptions = json_encode(array_merge($ajaxOptions, $options));

        $this->script = <<<EOT

$.ajax($ajaxOptions).done(function(data) {

  $("{$this->getElementClassSelector()}").each(function(index, element) {
      $(element).select2({
        data: data,
        $configs
      });
      var value = $(element).attr('data-value') || '';
      $(element).val(value === '' ? null : value.split(',')).trigger("change");
  });
});

EOT;

        return $this;
    }

    /**
     * Load options from ajax results.
     *
     * @param string $url
     * @param $idField
     * @param $textField
     *
     * @return $this
     */
    public function ajax($url, $idField = 'id', $textField = 'text')
    {
        $configs = array_merge([
            'allowClear'         => true,
            'placeholder'        => $this->label,
            'minimumInputLength' => 1,
        ], $this->config);

        $configs = json_encode($configs);
        $configs = substr($configs, 1, strlen($configs) - 2);

        $this->script = <<<EOT

$("{$this->getElementClassSelector()}").select2({
  ajax: {
    url: "$url",
    dataType: 'json',
    delay: 250,
    data: function (params) {
      return {
        q: params.term,
        page: params.page
      };
    },
    processResults: function (data, params) {
      params.page = params.page || 1;

      return {
        results: $.map(data.data, function (d) {
                   d.id = d.$idField;
                   d.text = d.$textField;
                   return d;
                }),
        pagination: {
          more: data.next_page_url
        }
      };
    },
    cache: true
  },
    $configs
});

EOT;

        return $this;
    }

    /**
     * Set config for select2.
     *
     * all configurations see https://select2.org/configuration/options-api
     *
     * @param string $key
     * @param mixed  $val
     *
     * @return $this
     */
    public function config($key, $val)
    {
        $this->config[$key] = $val;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function readOnly()
    {
        return parent::readOnly();
    }

    /**
     * {@inheritdoc}
     */
    public function render()
    {
        $this->initChoiceInput();
        $configs = array_merge([
            'allowClear'  => true,
            'placeholder' => [
                'id'   => '',
                'text' => $this->label,
            ],
        ], $this->config);

        $configs = json_encode($configs);

        if (empty($this->script)) {
            $this->script = "$(\"{$this->getElementClassSelector()}\").select2($configs);";
        }

        if ($this->options instanceof \Closure) {
            if ($this->form) {
                $this->options = $this->options->bindTo($this->form->model());
            }

            $this->options(call_user_func($this->options, $this->choiceValue(), $this));
        }

        $this->options = array_filter($this->options, 'strlen');

        $this->addVariables([
            'options' => $this->options,
            'groups'  => $this->groups,
        ]);

        $this->addCascadeScript();

        $this->attribute('data-value', implode(',', (array) $this->choiceValue()));

        return parent::render();
    }
}
