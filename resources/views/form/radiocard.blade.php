<div class="{{ $viewClass['form-group'] }}">
    <label for="{{ $id }}" class="{{ $viewClass['label'] }} col-form-label">{{ $label }}</label>

    <div class="{{ $viewClass['field'] }}">
        @include('admin::form.error')

        <div class="row row-cols-1 row-cols-md-3 g-3">
            @foreach($options as $option => $optionLabel)
                @php($optionId = $id.'_'.md5((string) $option))
                @php($isChecked = ($option == old($column, $value)) || ($value === null && in_array($optionLabel, $checked)))
                <div class="col">
                    <label class="card h-100 {{ $isChecked ? 'border-primary' : '' }}" for="{{ $optionId }}">
                        <div class="card-body d-flex align-items-start gap-2">
                            <input type="radio" class="form-check-input mt-1 {{ $class }}" id="{{ $optionId }}"
                                   name="{{ $name }}" value="{{ $option }}" {{ $isChecked ? 'checked' : '' }} {!! $attributes !!}>
                            <span>{{ $optionLabel }}</span>
                        </div>
                    </label>
                </div>
            @endforeach
        </div>

        @include('admin::form.help-block')
    </div>
</div>
