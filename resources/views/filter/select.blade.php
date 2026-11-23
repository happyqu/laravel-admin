<select class="form-control {{ $class }}" name="{{$name}}" style="width: 100%;"
    data-admin-select-column="{{ $selectColumn }}"
    @if($selectInitializing) data-admin-select-initializing @endif
    @isset($dependentSelect) data-admin-select-options-by="{{ $dependentSelect }}" @endisset>
    <option></option>
    @foreach($options as $select => $option)
        <option value="{{$select}}" {{ (string)$select === (string)request($name, $value) ?'selected':'' }}>{{$option}}</option>
    @endforeach
</select>
