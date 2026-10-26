<div class="{{ $viewClass['form-group'] }}">
    <label for="{{ $id }}" class="{{ $viewClass['label'] }} col-form-label">{{ $label }}</label>

    <div class="{{ $viewClass['field'] }}">
        @include('admin::form.error')

        <div class="input-group">
            @if ($prepend)
                <span class="input-group-text">{!! $prepend !!}</span>
            @endif

            <input {!! $attributes !!} />

            @if ($append)
                <span class="input-group-text">{!! $append !!}</span>
            @endif

            @isset($btn)
                {!! $btn !!}
            @endisset
        </div>

        @include('admin::form.help-block')
    </div>
</div>
