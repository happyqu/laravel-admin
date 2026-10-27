<div data-collection-field class="{{$viewClass['form-group']}}">

    <input type="hidden" name="{{ $name }}[_present]" value="1" />

    <label class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">
        <table class="table table-hover">
            <thead>
            <tr>
                <th>{{ __('Key') }}</th>
                <th>{{ __('Value') }}</th>
                <th style="width: 75px;"></th>
            </tr>
            </thead>
            <tbody data-collection-body class="kv-{{$column}}-table">

            @foreach(old("{$errorKey}.keys", ($value ?: [])) as $k => $v)

                @php($keysErrorKey = "{$errorKey}.keys.{$loop->index}")
                @php($valsErrorKey = "{$errorKey}.values.{$loop->index}")

                <tr>
                    <td>
                        <div class="form-group {{ $errors->has($keysErrorKey) ? 'has-error' : '' }}">
                            <div class="col-sm-12">
                                <input name="{{ $name }}[keys][]" value="{{ old("{$errorKey}.keys.{$k}", $k) }}" class="form-control" required/>

                                @if($errors->has($keysErrorKey))
                                    @foreach($errors->get($keysErrorKey) as $message)
                                        <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i> {{$message}}</label><br/>
                                    @endforeach
                                @endif
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="form-group {{ $errors->has($valsErrorKey) ? 'has-error' : '' }}">
                            <div class="col-sm-12">
                                <input name="{{ $name }}[values][]" value="{{ old("{$errorKey}.values.{$k}", $v) }}" class="form-control" />
                                @if($errors->has($valsErrorKey))
                                    @foreach($errors->get($valsErrorKey) as $message)
                                        <label class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i> {{$message}}</label><br/>
                                    @endforeach
                                @endif
                            </div>
                        </div>
                    </td>

                    <td class="form-group">
                        <div>
                            <div data-collection-remove class="{{$column}}-remove btn btn-warning btn-sm pull-right">
                                <i class="fa fa-trash">&nbsp;</i>{{ __('admin.remove') }}
                            </div>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td></td>
                    <td></td>
                    <td>
                        <div data-collection-add class="{{ $column }}-add btn btn-success btn-sm pull-right">
                            <i class="fa fa-save"></i>&nbsp;{{ __('admin.new') }}
                        </div>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
    <template data-collection-template class="{{$column}}-tpl">
        <tr>
            <td>
                <div class="form-group  ">
                    <div class="col-sm-12">
                        <input name="{{ $name }}[keys][]" class="form-control" required/>
                    </div>
                </div>
            </td>
            <td>
                <div class="form-group  ">
                    <div class="col-sm-12">
                        <input name="{{ $name }}[values][]" class="form-control" />
                    </div>
                </div>
            </td>

            <td class="form-group">
                <div>
                    <div data-collection-remove class="{{$column}}-remove btn btn-warning btn-sm pull-right">
                        <i class="fa fa-trash">&nbsp;</i>{{ __('admin.remove') }}
                    </div>
                </div>
            </td>
        </tr>
    </template>
</div>
