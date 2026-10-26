<span class="grid-selector" data-bs-toggle="modal" data-bs-target="#{{ $modal }}" data-key="{{ $key }}" data-val="{{ $original }}" role="button" tabindex="0">
   <a href="javascript:void(0)" class="text-body-secondary text-decoration-none">
       <i class="fa fa-check-square-o" aria-hidden="true"></i>&nbsp;&nbsp;
       <span class="text">{{ $value }}</span>
   </a>
</span>

<style>
    .belongsto.modal tr {
        cursor: pointer;
    }

    .belongsto.modal .card {
        border-top: none;
        margin-bottom: 0;
        box-shadow: none;
    }

    .belongsto.modal .loading {
        margin: 50px;
    }
</style>

<template>
    <div class="modal fade belongsto" id="{{ $modal }}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ admin_trans('admin.choose') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="loading text-center">
                        <i class="fa fa-spinner fa-pulse fa-3x fa-fw"></i>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ admin_trans('admin.cancel') }}</button>
                    <button type="button" class="btn btn-primary submit">{{ admin_trans('admin.submit') }}</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
    var $modal = $('#{{ $modal }}');
    var $related = null;

    var update = function (callback) {
        $.ajax({
            url: "{{ $resource }}/" + $related.data('key'),
            type: "POST",
            data: {
                '{{ $name }}': selected.length ? selected : [''],
                _token: LA.token,
                _method: 'PUT'
            },
            success: function (data) {
                callback(data);
            },
            error: function (xhr) {
                AdminUI.modal($modal[0], 'hide');
                if (xhr.status !== 401) {
                    var message = (xhr.responseJSON && xhr.responseJSON.message)
                        ? xhr.responseJSON.message
                        : "{{ trans('admin.request_failed') }}";
                    toastr.error(message);
                }
            }
        });
    };

@if($relation == \Encore\Admin\Grid\Displayers\BelongsTo::class)

    var selected = null;

    var load = function (url) {
        $.get(url, function (data) {
            $modal.find('.modal-body').html(data);
            $modal.find('.card-header:first').hide();

            $modal.find('input.select').each(function (index, el) {
                if ($(el).val() == selected) {
                    $(el).prop('checked', true);
                }
            });
        });
    };

    $modal.on('show.bs.modal', function (e) {
        $related = $(e.relatedTarget);
        selected = $related.data('val');
        load("{!! $url !!}");
    }).on('change', 'input.select', function () {
        selected = $(this).val();
    }).find('.modal-footer .submit').click(function () {
        update(function (data) {
            $related.data('val', selected);
            $related.find('.text').html(data.display["{{ $name }}"]);
            $related.find('a').addClass('text-success').removeClass('text-body-secondary');

            setTimeout(function () {
                $related.find('a').removeClass('text-success');
            }, 2000);

            AdminUI.modal($modal[0], 'toggle');

            toastr.success(data.message);
        });
    });

@else

    var selected = [];

    var load = function (url) {
        $.get(url, function (data) {
            $modal.find('.modal-body').html(data);
            $modal.find('.card-header:first').hide();

            $modal.find('input.select').each(function (index, el) {
                if ($.inArray($(el).val().toString(), selected) >= 0) {
                    $(el).prop('checked', true);
                }
            });
        });
    };

    $modal.on('show.bs.modal', function (e) {
        $related = $(e.relatedTarget);
        selected = ($related.data('val') || []).map(function (value) {
            return value.toString();
        });

        load("{!! $url !!}");
    }).on('change', 'input.select', function () {
        var val = $(this).val().toString();

        if (this.checked) {
            if (selected.indexOf(val) < 0) {
                selected.push(val);
            }
        } else {
            var index = selected.indexOf(val);
            if (index !== -1) {
                selected.splice(index, 1);
            }
        }
    }).find('.modal-footer .submit').click(function () {
        update(function (data) {
            $related.data('val', selected);
            $related.find('.text').html(data.display["{{ $name }}"]);
            $related.find('a').addClass('text-success').removeClass('text-body-secondary');

            setTimeout(function () {
                $related.find('a').removeClass('text-success');
            }, 2000);

            AdminUI.modal($modal[0], 'toggle');

            toastr.success(data.message);
        });
    });
@endif

    $modal.on('click', '.page-item a, .filter-box a', function (e) {
        load($(this).attr('href'));
        e.preventDefault();
    }).on('click', 'tr', function (e) {
        $(this).find('input.select').prop('checked', function () { return !this.checked; }).trigger('change');
        e.preventDefault();
    }).on('submit', '.card-header form', function (e) {
        load($(this).attr('action') + '&' + $(this).serialize());
        e.preventDefault();
        return false;
    });
</script>
