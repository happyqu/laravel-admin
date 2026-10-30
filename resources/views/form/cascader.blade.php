<div class="{{ $viewClass['form-group'] }} @if($errors->hasAny((array) $errorKey)) has-error @endif">
    <label class="{{ $viewClass['label'] }} control-label">{{ $label }}</label>
    <div class="{{ $viewClass['field'] }}">
        @include('admin::components.cascader')
        @foreach((array) $errorKey as $key)
            @foreach($errors->get($key) as $message)
                <span class="help-block">{{ $message }}</span>
            @endforeach
        @endforeach
        @include('admin::form.help-block')
    </div>
</div>
