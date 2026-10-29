
<div class="row">
    <div class="{{$viewClass['label']}}"><h4 class="pull-right">{{ $label }}</h4></div>
    <div class="{{$viewClass['field']}}"></div>
</div>

<hr style="margin-top: 0px;">

<div id="embed-{{$domName ?? $column}}" class="embed-{{$domName ?? $column}}">

    <div class="embed-{{$domName ?? $column}}-forms">

        <div class="embed-{{$domName ?? $column}}-form fields-group">

            @foreach($form->fields() as $field)
                {!! $field->render() !!}
            @endforeach

        </div>
    </div>
</div>

<hr style="margin-top: 0px;">
