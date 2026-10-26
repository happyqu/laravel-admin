@extends('admin::grid.inline-edit.comm')

@section('field')
    @foreach($options as $option => $label)
        <div class="form-check">
            <input type="checkbox" id="ie-checkbox-{{ $name }}-{{ md5((string) $option) }}"
                   name="radio-{{ $name }}[]" class="form-check-input ie-input" value="{{ $option }}" data-label="{{ $label }}">
            <label class="form-check-label" for="ie-checkbox-{{ $name }}-{{ md5((string) $option) }}">{{ $label }}</label>
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
            var current = $trigger.data('value');
            if (typeof current === 'string') {
                try { current = JSON.parse(current); } catch (error) { current = [current]; }
            }
            $template.find('input[type=checkbox]').each(function (index, checkbox) {
                if ($.inArray($(checkbox).attr('value'), current || []) >= 0) {
                    $(checkbox).prop('checked', true);
                }
            });
            @endslot
        @endcomponent
    </script>

    <script>
        @component('admin::grid.inline-edit.partials.submit', compact('resource', 'name'))
            @slot('val')
                var val = [];
                var label = [];
                $popover.find('.ie-input:checked').each(function () {
                    val.push($(this).val());
                    label.push($(this).data('label'));
                });
            @endslot

            $popover.data('display').html(label.join(';'));
        @endcomponent
    </script>
@endsection
