<a href="javascript:void(0);" class="text-body-secondary inline-upload-trigger" data-ie-target="{{ $target }}">
    <i class="fa fa-upload"></i>&nbsp;{!! $value !!}
</a>
<div class="d-none">
    <input type="file" class="inline-upload" id="{{ $target }}" data-key="{{ $key }}" {{ $multiple ? 'multiple' : '' }}/>
</div>

<script>
$('.inline-upload-trigger').on('click', function () {
    $('#' + $(this).data('ie-target')).trigger('click');
});

$('input.inline-upload').on('change', function (event) {
    var formData = new FormData();

    @if ($multiple)
    Array.from(event.target.files).forEach(function (file) {
        formData.append("{{ $name }}[]", file);
    });
    @else
    formData.append("{{ $name }}", event.target.files[0]);
    @endif

    formData.append('_token', LA.token);
    formData.append('_method', 'PUT');

    $.ajax({
        url: "{{ $resource }}/" + $(this).data('key'),
        type: "POST",
        processData: false,
        contentType: false,
        data: formData,
        success: function (data) {
            if (data && data.status === false) {
                toastr.error(data.message || "{{ trans('admin.request_failed') }}");
                return;
            }
            $.admin.notifyThenNavigate(data.message, 'success');
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
