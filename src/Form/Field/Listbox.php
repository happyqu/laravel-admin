<?php

namespace Encore\Admin\Form\Field;

/**
 * Class ListBox.
 */
class Listbox extends MultipleSelect
{
    protected static $css = [];

    protected static $js = ['/vendor/laravel-admin/laravel-admin/listbox.js'];

    protected $settings = [];

    public function settings(array $settings)
    {
        $this->settings = array_replace($this->settings, $settings);

        return $this;
    }

    /**
     * Set listbox height.
     *
     * @param int $height
     *
     * @return Listbox
     */
    public function height($height = 200)
    {
        return $this->settings(['selectorMinimalHeight' => max(0, (int) $height)]);
    }

    /**
     * {@inheritdoc}
     */
    protected function loadRemoteOptions($url, $parameters = [], $options = [])
    {
        $ajaxOptions = json_encode(array_merge([
            'url' => $url.'?'.http_build_query($parameters),
        ], $options));

        $this->script = <<<EOT
        
$.ajax($ajaxOptions).done(function(data) {

  var listbox = $("{$this->getElementClassSelector()}");

    if (!listbox.length || !document.documentElement.contains(listbox[0])) { return; }
    var values = JSON.parse(listbox.attr('data-listbox-values') || '[]').map(String);
    $.each(data, function (key, label) {
        listbox.append(new Option(label, key, false, values.indexOf(String(key)) !== -1));
    });
    listbox.each(function () { AdminListbox.refresh(this); });
    listbox.trigger('change');
});
EOT;

        return $this;
    }

    public function render()
    {
        $settings = json_encode(array_merge([
            'infoText' => trans('admin.listbox.text_total'),
            'infoTextEmpty' => trans('admin.listbox.text_empty'),
            'infoTextFiltered' => trans('admin.listbox.filtered'),
            'filterTextClear' => trans('admin.listbox.filter_clear'),
            'filterPlaceHolder' => trans('admin.listbox.filter_placeholder'),
            'selectorMinimalHeight' => 200,
        ], $this->settings), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);

        $this->script .= <<<SCRIPT
$("{$this->getElementClassSelector()}").each(function () {
    AdminListbox.mount(this, {$settings});
});
SCRIPT;

        $this->attribute('data-value', implode(',', (array) $this->value()));
        $this->attribute('data-admin-listbox', 'true');
        $this->attribute('data-listbox-values', json_encode(array_values((array) old($this->column, $this->value()))));

        return parent::render();
    }
}
