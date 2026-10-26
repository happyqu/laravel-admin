<div class="{{ $viewClass['form-group'] }}">
    <label for="{{ $id }}" class="{{ $viewClass['label'] }} col-form-label">{{ $label }}</label>

    <div class="{{ $viewClass['field'] }}" id="{{ $id }}">
        @include('admin::form.error')

        <div class="btn-group" role="group">
            @foreach($options as $option => $optionLabel)
                @php($optionId = $id.'_'.md5((string) $option))
                @php($isChecked = false !== array_search($option, array_filter(old($column, $value ?? []))) || ($value === null && in_array($option, $checked)))
                <input type="checkbox" class="btn-check {{ $class }}" id="{{ $optionId }}" name="{{ $name }}[]"
                       value="{{ $option }}" {{ $isChecked ? 'checked' : '' }} {!! $attributes !!}>
                <label class="btn btn-outline-secondary" for="{{ $optionId }}">{{ $optionLabel }}</label>
            @endforeach
        </div>

        <input type="hidden" name="{{ $name }}[]">

        @include('admin::form.help-block')
    </div>
</div>
