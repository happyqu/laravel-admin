<?php

namespace Encore\Admin\Grid\Displayers;

use Encore\Admin\Admin;

class Orderable extends AbstractDisplayer
{
    public function display()
    {
        if (!trait_exists('\Spatie\EloquentSortable\SortableTrait')) {
            throw new \Exception('To use orderable grid, please install package [spatie/eloquent-sortable] first.');
        }

        Admin::script($this->script());

        return <<<EOT

<div class="btn-group">
    <button type="button" class="btn btn-sm btn-info {$this->grid->getGridRowName()}-orderable" data-id="{$this->getKey()}" data-direction="1">
        <i class="fa fa-caret-up fa-fw"></i>
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary {$this->grid->getGridRowName()}-orderable" data-id="{$this->getKey()}" data-direction="0">
        <i class="fa fa-caret-down fa-fw"></i>
    </button>
</div>

EOT;
    }

    protected function script()
    {
        $failed = json_encode((string) trans('admin.request_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $expired = json_encode((string) trans('admin.authentication_expired'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return <<<EOT

$('.{$this->grid->getGridRowName()}-orderable').on('click', function() {

    var key = $(this).data('id');
    var direction = $(this).data('direction');

    $.post('{$this->getResource()}/' + key, {_method:'PUT', _token:LA.token, _orderable:direction}, function(data){
        if (data.status) {
            $.admin.notifyThenNavigate(data.message, 'success');
        } else {
            $.admin.notifyThenNavigate(data.message || {$failed}, 'error');
        }
    }).fail(function (xhr) {
        var message = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : {$failed};
        if (xhr.status === 401) {
            message = {$expired};
        }
        $.admin.notifyThenNavigate(message, 'error');
    });

});
EOT;
    }
}
