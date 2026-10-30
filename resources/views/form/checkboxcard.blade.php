<div class="{{$viewClass['form-group']}} {!! !$errors->has($column) ?: 'has-error' !!}">

    <label for="{{$id}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}" id="{{$id}}">

        @include('admin::form.error')

        <div class="card-group checkbox-group-toggle">
        @foreach($options as $option => $label)
            <label class="panel panel-default {{ in_array((string) $option, $choiceValues, true) || (!$hasOldChoiceInput && $choiceValue === null && in_array($option, $checked)) ?'active':'' }}">
                <div class="panel-body">
                <input type="checkbox" name="{{$name}}[]" value="{{$option}}" class="hide {{$class}}" {{ in_array((string) $option, $choiceValues, true) || (!$hasOldChoiceInput && $choiceValue === null && in_array($option, $checked)) ?'checked':'' }} {!! $attributes !!} />&nbsp;{{$label}}&nbsp;&nbsp;
                </div>
            </label>
        @endforeach
        </div>

        <input type="hidden" name="{{$name}}[]">

        @include('admin::form.help-block')

    </div>
</div>
