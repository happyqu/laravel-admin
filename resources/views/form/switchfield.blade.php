<div class="{{ $viewClass['form-group'] }}">
    <label for="{{ $id }}" class="{{ $viewClass['label'] }} col-form-label">{{ $label }}</label>

    <div class="{{ $viewClass['field'] }}">
        @include('admin::form.error')

        <div class="form-check form-switch">
            <input type="checkbox" role="switch" id="{{ $id }}" class="form-check-input {{ $class }}"
                   {{ old($column, $value) == 'on' ? 'checked' : '' }} {!! $attributes !!}>
        </div>
        <input type="hidden" class="{{ $class }}" name="{{ $name }}" value="{{ old($column, $value) }}">

        @include('admin::form.help-block')
    </div>
</div>
