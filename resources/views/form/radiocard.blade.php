<div class="{{$viewClass['form-group']}} {!! !$errors->has($errorKey) ? '' : 'has-error' !!}">

    <label for="{{$id}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">

        @include('admin::form.error')

        <div class="card-group radio-group-toggle">
            @foreach($options as $option => $label)
                <label class="panel panel-default {{ ($choiceValue !== null && $option == $choiceValue) || (!$hasOldChoiceInput && $choiceValue === null && in_array($option, $checked)) ?'active':'' }}">
                    <div class="panel-body">
                    <input type="radio" name="{{$name}}" value="{{$option}}" class="hide minimal {{$class}}" {{ ($choiceValue !== null && $option == $choiceValue) || (!$hasOldChoiceInput && $choiceValue === null && in_array($option, $checked)) ?'checked':'' }} {!! $attributes !!} />&nbsp;{{$label}}&nbsp;&nbsp;
                    </div>
                </label>
            @endforeach
        </div>

        @include('admin::form.help-block')

    </div>
</div>
