<div class="{{$viewClass['form-group']}}">

    <label for="{{$id['start']}}" class="{{$viewClass['label']}} col-form-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">
        <div class="row">
            <div class="col-lg-12">
                @if($errors->has($errorKey['start']))
                    @foreach($errors->get($errorKey['start']) as $message)
                        <div class="invalid-feedback d-block"><i class="fa fa-times-circle-o"></i> {{ $message }}</div>
                    @endforeach
                @endif

                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-calendar"></i></span>
                    <input type="text"
                           name="{{$name['start']}}"
                           value="{{ old($column['start'], $value['start'] ?? null) }}"
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
                @if($errors->has($errorKey['end']))
                    @foreach($errors->get($errorKey['end']) as $message)
                        <div class="invalid-feedback d-block"><i class="fa fa-times-circle-o"></i> {{ $message }}</div>
                    @endforeach
                @endif

                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-calendar"></i></span>
                    <input type="text"
                           name="{{$name['end']}}"
                           value="{{ old($column['end'], $value['end'] ?? null) }}"
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
