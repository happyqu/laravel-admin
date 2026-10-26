<div class="card-footer">
    {{ csrf_field() }}

    <div class="row align-items-center g-2">
        <div class="col-md-{{ $width['label'] }}"></div>

        <div class="col-md-{{ $width['field'] }} d-flex flex-wrap align-items-center gap-3">
            @if(in_array('reset', $buttons))
                <button type="reset" class="btn btn-outline-warning">{{ trans('admin.reset') }}</button>
            @endif

            @if(in_array('submit', $buttons))
                @foreach($submit_redirects as $value => $redirect)
                    @if(in_array($redirect, $checkboxes))
                        <div class="form-check form-check-inline mb-0">
                            <input type="checkbox" class="form-check-input after-submit" id="after-submit-{{ $value }}"
                                   name="after-save" value="{{ $value }}" {{ ($default_check == $redirect) ? 'checked' : '' }}>
                            <label class="form-check-label" for="after-submit-{{ $value }}">{{ trans("admin.{$redirect}") }}</label>
                        </div>
                    @endif
                @endforeach

                <button type="submit" class="btn btn-primary ms-auto">{{ trans('admin.submit') }}</button>
            @endif
        </div>
    </div>
</div>
