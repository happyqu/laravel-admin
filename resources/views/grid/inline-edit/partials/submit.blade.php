$(document).off('click.adminPage', '.{{ $trigger }}-content .ie-submit').on('click.adminPage', '.{{ $trigger }}-content .ie-submit', function () {

    var $button = $(this);
    if ($button.prop('disabled')) return;

    var $popover = $(this).closest('.ie-content');
    var $trigger = $popover.data('trigger');

    @isset($val)
        {{ $val }}
    @else
        var val = $popover.find('.ie-input').val();
    @endisset

    var original = $trigger.data('original');

    if (val == original) {
        $('[data-toggle="popover"]').popover('hide');
        return;
    }

    var data = {
        _token: LA.token,
        _method: 'PUT',
        _edit_inline: true,
    };
    // jQuery omits empty arrays; retain the field so an inline clear is saved.
    data[$trigger.data('name')] = Array.isArray(val) && val.length === 0 ? [''] : val;
    $button.prop('disabled', true);

    $.ajax({
        url: @json($resource) + '/' + encodeURIComponent($trigger.data('key')),
        type: "POST",
        data: data,
        success: function (data) {
            if ($.admin.isResponseError(data)) {
                toastr.error($.admin.responseMessage(data));
                return;
            }
            toastr.success($.admin.responseMessage(data));

            {{ $slot }}

            $trigger.data('value', val)
                .data('original', val);

            $('[data-toggle="popover"]').popover('hide');
        },
        error: function (xhr) {
            if (xhr.status === 422) {
                $popover.find('.error').empty();
                var errors = (xhr.responseJSON && (xhr.responseJSON.errors || xhr.responseJSON.validation)) || {};
                for (var key in errors) {
                    $popover.find('.error').append($('<div>').text(errors[key]));
                }
            } else if (xhr.status === 401) {
                $('[data-toggle="popover"]').popover('hide');
            } else {
                toastr.error($.admin.responseMessage(xhr.responseJSON) || @json(trans('admin.request_failed')));
            }
        },
        complete: function () {
            $button.prop('disabled', false);
        }
    });
});
