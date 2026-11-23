<select class="form-control {{ $class }}" name="{{$name}}[]" multiple style="width: 100%;"
    data-admin-select-column="{{ $selectColumn }}"
    @if($selectInitializing) data-admin-select-initializing @endif
    @isset($dependentSelect) data-admin-select-options-by="{{ $dependentSelect }}" @endisset>
    <option></option>
    @foreach($options as $select => $option)
        <option value="{{$select}}" {{ in_array((string)$select, (array)$value) ?'selected':'' }}>{{$option}}</option>
    @endforeach
</select>
