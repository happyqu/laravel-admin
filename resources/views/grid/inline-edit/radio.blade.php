@extends('admin::grid.inline-edit.comm')

@section('field')
    @foreach($options as $option => $label)
        <div class="form-check">
            <input type="radio" id="ie-radio-{{ $name }}-{{ md5((string) $option) }}"
                   name="radio-{{ $name }}" class="form-check-input ie-input" value="{{ $option }}" data-label="{{ $label }}">
            <label class="form-check-label" for="ie-radio-{{ $name }}-{{ md5((string) $option) }}">{{ $label }}</label>
        </div>
    @endforeach
@endsection

@section('assert')
    <style>
        .ie-content-{{ $name }} .ie-container {
            width: 170px;
            position: relative;
        }
    </style>

    <script>
        @component('admin::grid.inline-edit.partials.popover', compact('trigger'))
            @slot('content')
            $template.find('input[type=radio]').each(function (index, radio) {
                if ($(radio).attr('value') == $trigger.data('value')) {
                    $(radio).prop('checked', true);
                }
            });
            @endslot
        @endcomponent
    </script>

    <script>
        @component('admin::grid.inline-edit.partials.submit', compact('resource', 'name'))
            @slot('val')
                var val = $popover.find('.ie-input:checked').val();
                var label = $popover.find('.ie-input:checked').data('label');
            @endslot

            $popover.data('display').html(label);
        @endcomponent
    </script>
@endsection
