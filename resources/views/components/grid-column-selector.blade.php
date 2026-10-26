<div class="dropdown float-end column-selector">
    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="fa fa-table" aria-hidden="true"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li>
            <ul class="list-unstyled mb-0">
                @foreach($columns as $key => $label)
                    @php
                        if (empty($visible)) {
                            $checked = 'checked';
                        } else {
                            $checked = in_array($key, $visible) ? 'checked' : '';
                        }
                    @endphp

                    <li class="form-check">
                        <input type="checkbox" class="form-check-input column-select-item" id="column-select-{{ md5($key) }}"
                               value="{{ $key }}" {{ $checked }}>
                        <label class="form-check-label" for="column-select-{{ md5($key) }}">{{ $label }}</label>
                    </li>
                @endforeach
            </ul>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li class="text-end p-1">
            <button class="btn btn-sm btn-outline-secondary column-select-all">{{ __('admin.all') }}</button>
            <button class="btn btn-sm btn-primary column-select-submit">{{ __('admin.submit') }}</button>
        </li>
    </ul>
</div>

<style>
    .column-selector {
        margin-right: 10px;
    }

    .column-selector .dropdown-menu {
        padding: 10px;
        height: auto;
        max-height: 500px;
        overflow-x: hidden;
    }

    .column-selector .dropdown-menu ul {
        padding: 0;
    }

    .column-selector .dropdown-menu ul li {
        margin: 0;
    }

    .column-selector .dropdown-menu label {
        width: 100%;
        padding: 3px;
    }
</style>

<script>
$('.column-select-submit').on('click', function () {
    var defaults = @json($defaults);
    var selected = [];

    $('.column-select-item:checked').each(function () {
        selected.push($(this).val());
    });

    if (selected.length == 0) {
        return;
    }

    var url = new URL(location);

    if (selected.sort().toString() == defaults.sort().toString()) {
        url.searchParams.delete('_columns_');
    } else {
        url.searchParams.set('_columns_', selected.join());
    }

    $.admin.redirect(url.toString());
});

$('.column-select-all').on('click', function () {
    $('.column-select-item').prop('checked', true);
    return false;
});
</script>
