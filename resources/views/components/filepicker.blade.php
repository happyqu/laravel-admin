<template>
    <div class="modal fade picker" id="{{ $modal }}" tabindex="-1" aria-hidden="true">
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
var pickInput = $("{{ $selector }}");
var separator = '{{ $separator }}';
var modal = $('#{{ $modal }}');
var value;
var refresh = function () {};

modal.on('show.bs.modal', function () {
    load("{!! $url !!}");
}).on('click', '.page-item a, .filter-box a', function (e) {
    load($(this).attr('href'));
    e.preventDefault();
}).on('click', 'tr', function (e) {
    var input = $(this).find('input.select');
    input.prop('checked', function () { return !this.checked; }).trigger('change');
    e.preventDefault();
}).on('submit', '.card-header form', function (e) {
    load($(this).attr('action') + '&' + $(this).serialize());
    return false;
});

@if($multiple)

var updateValue = function () {
    value = pickInput.val().split(separator).filter(function (val) {
        return val != '';
    });
};

var load = function (url) {
    $.get(url, function (data) {
        modal.find('.modal-body').html(data);
        modal.find('.card-header:first').hide();

        modal.find('input.select').each(function (index, el) {
            if ($.inArray($(el).val().toString(), value) >= 0) {
                $(el).prop('checked', true);
            }
        });
    });
};

modal.on('change', 'input.select', function () {
    var val = $(this).val();

    if (this.checked) {
        if (value.indexOf(val) < 0) {
            value.push(val);
        }
    } else {
        var index = value.indexOf(val);
        if (index !== -1) {
            value.splice(index, 1);
        }
    }
}).find('.modal-footer .submit').click(function () {
    pickInput.val(value.join(separator));
    AdminUI.modal(modal[0], 'toggle');
    refresh();
});

@else

var updateValue = function () {
    value = pickInput.val();
};

var load = function (url) {
    $.get(url, function (data) {
        modal.find('.modal-body').html(data);
        modal.find('.card-header:first').hide();

        modal.find('input.select').each(function (index, el) {
            if ($(el).val() == value) {
                $(el).prop('checked', true);
            }
        });
    });
};

modal.on('change', 'input.select', function () {
    value = $(this).val();
}).find('.modal-footer .submit').click(function () {
    pickInput.val(value);
    AdminUI.modal(modal[0], 'toggle');
    refresh();
});

@endif

updateValue();

$('.picker-file-preview').on('click', 'a.remove', function () {
    var preview = $(this).parents('.file-preview-frame');
    var current = preview.data('val');

    preview.addClass('d-none');

    var input = pickInput.val().split(separator);
    var index = input.indexOf(current);

    if (index !== -1) {
        input.splice(index, 1);
    }

    pickInput.val(input.join(separator));
    updateValue();

    if (input.length === 0) {
        $(this).parents('.picker-file-preview').addClass('d-none');
    }
});

@if($is_file)
refresh = function () {
    var values = (typeof value == 'string') ? [value] : value;
    var preview = pickInput.parent().siblings('.picker-file-preview');
    var url_tpl = '{{ $url_tpl }}';

    @if($is_image)
    var template = $('template#image-preview');
    @else
    var template = $('template#file-preview');
    @endif

    preview.empty();

    values.forEach(function (item) {
        var url = url_tpl.replace('__URL__', item);
        preview.append(
            template.html()
                .replace(/_url_/g, url)
                .replace(/_val_/g, item)
                .replace(/_name_/g, url.split('/').pop())
        );
    });

    if (values.length > 0) {
        preview.removeClass('d-none');
    }
};
@endif
</script>

<style>
    .picker.modal tr {
        cursor: pointer;
    }

    .picker.modal .card {
        border-top: none;
        margin-bottom: 0;
        box-shadow: none;
    }

    @if($is_file)
    .picker-file-preview {
        overflow: hidden;
        border-radius: 5px;
        border: 1px solid var(--bs-border-color);
        padding: 8px;
        width: 100%;
        margin-bottom: 5px;
    }

    .picker-file-preview .file-preview-frame {
        margin: 8px;
        border: 1px solid rgba(0, 0, 0, .2);
        box-shadow: 0 0 10px 0 rgba(0, 0, 0, .2);
        padding: 6px;
        float: left;
        text-align: center;
        width: 213px;
    }

    .picker-file-preview .file-content {
        font-size: 6em;
    }

    .picker-file-preview .file-caption-info {
        display: block;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        width: 160px;
        height: 20px;
        margin: auto;
        font-size: 11px;
        color: var(--bs-secondary-color);
    }

    .picker-file-preview .file-actions {
        text-align: right;
        margin-top: 20px;
    }

    .picker-file-preview img {
        max-width: 160px;
        max-height: 160px;
    }
    @endif
</style>
