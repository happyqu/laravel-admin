<div class="admin-cascader" data-admin-cascader="{{ json_encode($cascaderConfig, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}">
    @foreach($cascaderNames as $index => $inputName)
        <input type="hidden" data-cascader-value name="{{ $inputName }}" value="{{ is_scalar($cascaderValues[$index] ?? null) ? $cascaderValues[$index] : '' }}" @if($cascaderDisabled) disabled @endif />
    @endforeach
    <div class="admin-cascader-control form-control">
        <button type="button" class="admin-cascader-toggle" aria-haspopup="dialog" aria-expanded="false" aria-label="{{ $label }}" @if($cascaderConfig['locked']) disabled @endif>
            <span data-cascader-label>{{ $cascaderConfig['placeholder'] }}</span><span class="admin-cascader-arrow" aria-hidden="true"><b></b></span>
        </button>
        <button type="button" class="admin-cascader-clear" aria-label="{{ $cascaderConfig['messages']['clear'] }}" hidden><svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><circle cx="8" cy="8" r="6" /><path d="m6 6 4 4m0-4-4 4" /></svg></button>
    </div>
    <div class="admin-cascader-status" aria-live="polite"></div>
    <div class="admin-cascader-panel" role="dialog" aria-label="{{ $label }}" hidden>
        <div class="admin-cascader-breadcrumb"></div>
        <div class="admin-cascader-columns"></div>
    </div>
</div>
