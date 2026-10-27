<a href='javascript:void(0);' class='text-muted inline-upload-trigger' data-target="{{ $target }}">
    <i class="fa fa-upload"></i>&nbsp;{{ is_array($value) ? implode(';', $value) : $value }}
</a>
<div class="hide">
  <input type="file" class="inline-upload" id="{{ $target }}" data-key="{{ $key }}" {{ $multiple ? 'multiple' : '' }}/>
</div>

<script>
$('.inline-upload-trigger').off('click.adminUpload').on('click.adminUpload', function () {
    $('#'+$(this).data('target')).trigger('click');
});

$(@json('#'.$target)).off('change.adminUpload').on('change.adminUpload', function (event) {

    if (!event.target.files.length) return;

    var formData = new FormData();

    @if ($multiple)
        Array.from(event.target.files).forEach(function (file) {
            formData.append(@json($name.'[]'), file);
        });
    @else
    formData.append(@json($name), event.target.files[0]);
    @endif
    formData.append('_token', LA.token);
    formData.append('_method', 'PUT');
    formData.append('_edit_inline', 'true');

    $.ajax({
        url: @json($resource) + '/' + encodeURIComponent($(this).data('key')),
        type: "POST",
        processData: false,
        contentType: false,
        enctype: 'multipart/form-data',
        data: formData,
        success: function (data) {
            if ($.admin.isResponseError(data)) {
                toastr.error($.admin.responseMessage(data) || @json(trans('admin.request_failed')));
                return;
            }
            $.admin.notifyThenNavigate($.admin.responseMessage(data), 'success');
        },
        error: function (xhr) {
            var message = (xhr.responseJSON && xhr.responseJSON.message)
                ? xhr.responseJSON.message
                : "{{ trans('admin.request_failed') }}";
            if (xhr.status !== 401) {
                toastr.error(message);
            }
        }
    });
});
</script>
