<div class="{{$viewClass['form-group']}} {!! !$errors->has($column) ?: 'has-error' !!}">

    <label for="{{$id}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}" id="{{$id}}">

        @include('admin::form.error')

        <div class="btn-group checkbox-group-toggle">
        @foreach($options as $option => $label)
            <label class="btn btn-default {{ in_array((string) $option, $choiceValues, true) || (!$hasOldChoiceInput && $choiceValue === null && in_array($option, $checked)) ?'active':'' }}">
                <input type="checkbox" name="{{$name}}[]" value="{{$option}}" class="hide {{$class}}" {{ in_array((string) $option, $choiceValues, true) || (!$hasOldChoiceInput && $choiceValue === null && in_array($option, $checked)) ?'checked':'' }} {!! $attributes !!} />&nbsp;{{$label}}&nbsp;&nbsp;
            </label>
        @endforeach
        </div>

        <input type="hidden" name="{{$name}}[]">

        @include('admin::form.help-block')

    </div>
</div>
