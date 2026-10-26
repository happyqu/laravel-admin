<?php

namespace Encore\Admin\Form\Field;

/**
 * Class ListBox.
 */
class Listbox extends MultipleSelect
{
    protected $settings = [];

    public function settings(array $settings)
    {
        $this->settings = $settings;

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
        return $this->settings(['size' => max(3, (int) round($height / 30))]);
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

    var value = listbox.data('value') + '';
    
    if (value) {
      value = value.split(',');
    }
    
    for (var key in data) {
        var selected =  ($.inArray(key, value) >= 0) ? 'selected' : '';
        listbox.append('<option value="'+key+'" '+selected+'>'+data[key]+'</option>');
    }

    listbox.trigger('change');
});
EOT;

        return $this;
    }

    public function render()
    {
        if (isset($this->settings['size'])) {
            $this->attribute('size', (int) $this->settings['size']);
        }

        $this->attribute('data-value', implode(',', (array) $this->value()));

        return parent::render();
    }
}
