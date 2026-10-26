<div class="d-flex flex-wrap gap-3">
    @foreach($options as $option => $label)
        <div class="form-check form-check-inline">
            <input type="checkbox" class="form-check-input {{ $id }}" name="{{ $name }}[]" value="{{ $option }}"
                   {{ in_array((string) $option, request($name, is_null($value) ? [] : $value)) ? 'checked' : '' }}>
            <label class="form-check-label">{{ $label }}</label>
        </div>
    @endforeach
</div>
