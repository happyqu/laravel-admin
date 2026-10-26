<div class="{{ $viewClass['form-group'] }}">
    <label for="{{ $id }}" class="{{ $viewClass['label'] }} col-form-label">{{ $label }}</label>

    <div class="{{ $viewClass['field'] }}" id="{{ $id }}">
        @if($canCheckAll)
            <div class="form-check mb-2">
                <input type="checkbox" class="form-check-input {{ $checkAllClass }}" id="{{ $id }}_all">
                <label class="form-check-label" for="{{ $id }}_all">{{ __('admin.all') }}</label>
            </div>
            <hr class="my-2">
        @endif

        @include('admin::form.error')

        @if($groups)
            @foreach($groups as $group => $groupOptions)
                <p class="text-body-secondary border-bottom pb-1 mt-3 mb-2">{{ $group }}</p>

                @foreach($groupOptions as $option => $optionLabel)
                    @php($optionId = $id.'_'.md5($group.'|'.$option))
                    <div class="form-check {{ $inline ? 'form-check-inline' : '' }}">
                        <input type="checkbox" id="{{ $optionId }}" name="{{ $name }}[]" value="{{ $option }}"
                               class="form-check-input {{ $class }}"
                               {{ false !== array_search($option, array_filter(old($column, $value ?? []))) || ($value === null && in_array($option, $checked)) ? 'checked' : '' }}
                               {!! $attributes !!}>
                        <label class="form-check-label" for="{{ $optionId }}">{{ $optionLabel }}</label>
                    </div>
                @endforeach
            @endforeach
        @else
            @foreach($options as $option => $optionLabel)
                @php($optionId = $id.'_'.md5((string) $option))
                <div class="form-check {{ $inline ? 'form-check-inline' : '' }}">
                    <input type="checkbox" id="{{ $optionId }}" name="{{ $name }}[]" value="{{ $option }}"
                           class="form-check-input {{ $class }}"
                           {{ false !== array_search($option, array_filter(old($column, $value ?? []))) || ($value === null && in_array($option, $checked)) ? 'checked' : '' }}
                           {!! $attributes !!}>
                    <label class="form-check-label" for="{{ $optionId }}">{{ $optionLabel }}</label>
                </div>
            @endforeach
        @endif

        <input type="hidden" name="{{ $name }}[]">

        @include('admin::form.help-block')
    </div>
</div>
