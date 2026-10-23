<?php

namespace Encore\Admin\Grid\Tools;

class BatchDelete extends BatchAction
{
    public function __construct($title)
    {
        $this->title = $title;
    }

    /**
     * Script of batch delete action.
     */
    public function script()
    {
        $trans = [
            'delete_confirm' => trans('admin.delete_confirm'),
            'confirm'        => trans('admin.confirm'),
            'cancel'         => trans('admin.cancel'),
        ];

        $js = function ($value) {
            return json_encode((string) $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
        };

        return <<<EOT

$('{$this->getElementClass()}').on('click', function() {

    var deleting = false;

    swal({
        title: {$js($trans['delete_confirm'])},
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#DD6B55",
        confirmButtonText: {$js($trans['confirm'])},
        showLoaderOnConfirm: true,
        cancelButtonText: {$js($trans['cancel'])},
        preConfirm: function() {
            return new Promise(function(resolve) {
                deleting = true;
                $.ajax({
                    method: 'post',
                    url: '{$this->resource}/' + $.admin.grid.selected().join(),
                    data: {
                        _method:'delete',
                        _token:'{$this->getToken()}'
                    },
                    success: function (data) {
                        if (data.status === false) {
                            deleting = false;
                            resolve();
                            swal(data.message || {$js(trans('admin.delete_failed'))}, '', 'error');
                            return;
                        }
                        resolve(data);
                        $.admin.notifyThenNavigate(data.message || {$js(trans('admin.delete_succeeded'))}, 'success');
                    },
                    error: function (xhr) {
                        deleting = false;
                        resolve();
                        var message = (xhr.responseJSON && xhr.responseJSON.message)
                            ? xhr.responseJSON.message
                            : {$js(trans('admin.request_failed'))};
                        if (xhr.status === 401) {
                            message = {$js(trans('admin.authentication_expired'))};
                        }
                        swal(message, '', 'error');
                    }
                });
            });
        },
        allowOutsideClick: function () { return !deleting; },
        allowEscapeKey: function () { return !deleting; }
    });
});

EOT;
    }
}
