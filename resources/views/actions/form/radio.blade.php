<div class="admin-field mb-3">
    <label class="form-label">{{ $label }}</label>
    <div class="d-flex flex-wrap gap-3">
        @foreach($options as $option => $optionLabel)
            <div class="form-check form-check-inline">
                <input type="radio" name="{{ $name }}" value="{{ $option }}" class="form-check-input {{ $class }}"
                       {{ ($option == old($column, $value)) || ($value === null && in_array($optionLabel, $checked)) ? 'checked' : '' }}
                       {!! $attributes !!}>
                <label class="form-check-label">{{ $optionLabel }}</label>
            </div>
        @endforeach
    </div>
    @include('admin::actions.form.help-block')
</div>
