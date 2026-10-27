<input type="checkbox" class="{{ $class }}" {{ $checked }} data-key="{{ $key }}" />

<script>
    (function () {
        var onValue = @json($states['on']['value']);
        var offValue = @json($states['off']['value']);

        $('.{{ $class }}').bootstrapSwitch({
            size: 'mini',
            onText: @json($states['on']['text']),
            offText: @json($states['off']['text']),
            onColor: @json($states['on']['color']),
            offColor: @json($states['off']['color']),
            onSwitchChange: function (event, state) {
                var $switch = $(this);
                var failed = false;

                // The switch already shows the new state; saving runs in the
                // background and reverts on failure instead of blocking the UI.
                $switch.bootstrapSwitch('disabled', true);

                $.ajax({
                    url: @json($resource) + '/' + $switch.data('key'),
                    type: 'POST',
                    data: {
                        @json($name): state ? onValue : offValue,
                        _token: LA.token,
                        _method: 'PUT',
                        _edit_inline: true
                    },
                    adminLoading: false
                }).done(function (data) {
                    if ($.admin.isResponseError(data)) {
                        failed = true;
                        $.admin.toastr.warning($.admin.responseMessage(data));
                    } else {
                        $.admin.toastr.success($.admin.responseMessage(data));
                    }
                }).fail(function (xhr) {
                    failed = true;
                    var data = xhr.responseJSON;
                    var message = $.admin.responseMessage(data)
                        || (data && data.errors ? Object.values(data.errors).join('\n') : '');
                    $.admin.toastr.error(message || @json(trans('admin.request_failed')));
                }).always(function () {
                    $switch.bootstrapSwitch('disabled', false);
                    if (failed) {
                        $switch.bootstrapSwitch('state', !state, true);
                    }
                });
            }
        });
    })();
</script>
