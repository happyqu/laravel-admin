<form {!! $attributes !!}>
    <div class="card-body fields-group">
        @foreach($fields as $field)
            {!! $field->render() !!}
        @endforeach
    </div>

    @if ($method != 'GET')
        <input type="hidden" name="_token" value="{{ csrf_token() }}">
    @endif

    @if(count($buttons) > 0)
        <div class="card-footer">
            <div class="row">
                <div class="col-md-{{ $width['label'] }}"></div>
                <div class="col-md-{{ $width['field'] }} d-flex gap-2">
                    @if(in_array('reset', $buttons))
                        <button type="reset" class="btn btn-outline-warning">{{ trans('admin.reset') }}</button>
                    @endif

                    @if(in_array('submit', $buttons))
                        <button type="submit" class="btn btn-primary ms-auto">{{ trans('admin.submit') }}</button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</form>
