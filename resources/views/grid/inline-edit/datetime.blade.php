@extends('admin::grid.inline-edit.comm')

@section('field')
    <input class="form-control ie-input"/>
@endsection

@section('assert')
    <style>
        .ie-content-{{ $name }} .ie-container  {
            min-height: 290px;
        }

        .ie-content-{{ $name }} .ie-input {
            margin-bottom: 6px;
        }
    </style>

    <script>
        @component('admin::grid.inline-edit.partials.popover', compact('trigger'))
            @slot('content')
            $template.find('input').attr('value', $trigger.data('value'));
            @endslot
            @slot('shown')
            var $input  = $popover.find('.ie-input');

            $input.adminDatepicker({
                inline: true,
                format: {!! json_encode($format, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!},
                date: $input.val(),
                locale: {!! json_encode($locale, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}
            });
            @endslot
        @endcomponent
    </script>

    {{--after submit--}}
    <script>
    @component('admin::grid.inline-edit.partials.submit', compact('resource', 'name', 'trigger'))
        $popover.data('display').text(val);
    @endcomponent
    </script>

@endsection


