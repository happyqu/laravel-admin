<div class="{{ $viewClass['form-group'] }}">
    <label for="{{ $id }}" class="{{ $viewClass['label'] }} col-form-label">{{ $label }}</label>

    <div class="{{ $viewClass['field'] }}">
        @include('admin::form.error')

        @foreach($options as $option => $optionLabel)
            @php($optionId = $id.'_'.md5((string) $option))
            <div class="form-check {{ $inline ? 'form-check-inline' : '' }}">
                <input type="radio" id="{{ $optionId }}" name="{{ $name }}" value="{{ $option }}"
                       class="form-check-input {{ $class }}"
                       {{ ($option == old($column, $value)) || ($value === null && in_array($optionLabel, $checked)) ? 'checked' : '' }}
                       {!! $attributes !!}>
                <label class="form-check-label" for="{{ $optionId }}">{{ $optionLabel }}</label>
            </div>
        @endforeach

        @include('admin::form.help-block')
    </div>
</div>
