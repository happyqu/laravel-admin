<tr style="height: 28px;">
    <td><strong><small>{{ $label }}:</small></strong>&nbsp;&nbsp;&nbsp;</td>
    <td>
        <div class="form-check form-switch">
            <input type="checkbox" role="switch" class="form-check-input {{ $class }}" {{ $checked }}
                   data-key="{{ $key }}" data-on="{{ $states['on']['value'] }}" data-off="{{ $states['off']['value'] }}">
        </div>
    </td>
</tr>

<script>
    $('.{{ $class }}').on('change', function () {
        var checkbox = this;
        var key = $(checkbox).data('key');
        var value = checkbox.checked ? $(checkbox).data('on') : $(checkbox).data('off');

        checkbox.disabled = true;

        $.ajax({
            url: "{{ $resource }}/" + key,
            type: "POST",
            data: {
                "{{ $name }}": value,
                _token: LA.token,
                _method: 'PUT'
            },
            success: function (data) {
                if (data.status) {
                    toastr.success(data.message);
                } else {
                    toastr.warning(data.message);
                    checkbox.checked = !checkbox.checked;
                }
            },
            error: function (xhr) {
                checkbox.checked = !checkbox.checked;
                var data = xhr.responseJSON || {};
                var message = data.message || (data.errors ? Object.values(data.errors).join("\n") : null);
                toastr.error(message || 'Error');
            },
            complete: function () {
                checkbox.disabled = false;
            }
        });
    });
</script>
