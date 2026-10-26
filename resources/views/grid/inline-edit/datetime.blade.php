@extends('admin::grid.inline-edit.comm')

@php($inputType = str_contains($format, 'HH') ? 'datetime-local' : 'date')

@section('field')
    <input type="{{ $inputType }}" class="form-control form-control-sm ie-input" @if($inputType === 'datetime-local') step="1" @endif>
@endsection

@section('assert')
    <script>
        @component('admin::grid.inline-edit.partials.popover', compact('trigger'))
            @slot('content')
            var value = $trigger.data('value');
            if (value) {
                value = String(value).replace(' ', 'T');
            }
            $template.find('input').attr('value', value);
            @endslot
        @endcomponent
    </script>

    <script>
        @component('admin::grid.inline-edit.partials.submit', compact('resource', 'name'))
            @slot('val')
                var val = String($popover.find('.ie-input').val() || '').replace('T', ' ');
            @endslot

            $popover.data('display').html(val);
        @endcomponent
    </script>
@endsection
