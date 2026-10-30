<div class="{{$viewClass['form-group']}} {!! ($errors->has($errorKey['start'].'start') || $errors->has($errorKey['end'].'end')) ? 'has-error' : ''  !!}">

    <label for="{{$id['start']}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">
        <div class="row">
            <div class="col-lg-12">
                @if($errors->has($errorKey['start'].'start'))
                    @foreach($errors->get($errorKey['start'].'start') as $message)
                        <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i> {{$message}}</label><br/>
                    @endforeach
                @endif

                <div class="input-group">
                    <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                    <input type="text"
                           name="{{$name['start']}}"
                           value="{{ old(trim(str_replace(['[', ']'], ['.', ''], $name['start']), '.'), $value['start'] ?? null) }}"
                           class="form-control {{$class['start']}}"
                           style="width: 160px"
                           autocomplete="off"
                            {!! $attributes !!}
                    />
                </div>
            </div>
        </div>

        <div class="row" style="margin-top: 5px">
            <div class="col-lg-12">
                @if($errors->has($errorKey['end'].'end'))
                    @foreach($errors->get($errorKey['end'].'end') as $message)
                        <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i> {{$message}}</label><br/>
                    @endforeach
                @endif

                <div class="input-group">
                    <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                    <input type="text"
                           name="{{$name['end']}}"
                           value="{{ old(trim(str_replace(['[', ']'], ['.', ''], $name['end']), '.'), $value['end'] ?? null) }}"
                           class="form-control {{$class['end']}}"
                           style="width: 160px"
                           autocomplete="off"
                            {!! $attributes !!}
                    />
                </div>
            </div>
        </div>

        @include('admin::form.help-block')

    </div>
</div>
