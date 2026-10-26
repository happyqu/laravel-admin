<div class="{{ $viewClass['form-group'] }}">
    <label for="{{ $id }}" class="{{ $viewClass['label'] }} col-form-label">{{ $label }}</label>

    <div class="{{ $viewClass['field'] }}">
        @include('admin::form.error')

        <div class="btn-group" role="group">
            @foreach($options as $option => $optionLabel)
                @php($optionId = $id.'_'.md5((string) $option))
                @php($isChecked = ($option == old($column, $value)) || ($value === null && in_array($optionLabel, $checked)))
                <input type="radio" class="btn-check {{ $class }}" id="{{ $optionId }}" name="{{ $name }}"
                       value="{{ $option }}" {{ $isChecked ? 'checked' : '' }} {!! $attributes !!}>
                <label class="btn btn-outline-secondary" for="{{ $optionId }}">{{ $optionLabel }}</label>
            @endforeach
        </div>

        @include('admin::form.help-block')
    </div>
</div>
