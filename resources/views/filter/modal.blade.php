<div class="btn-group float-end" style="margin-right: 10px">
    <a href="#" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#{{ $modalID }}">
        <i class="fa fa-filter" aria-hidden="true"></i>&nbsp;&nbsp;{{ trans('admin.filter') }}
    </a>
    <a href="{!! $action !!}" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-undo" aria-hidden="true"></i>&nbsp;&nbsp;{{ trans('admin.reset') }}
    </a>
</div>

<div class="modal fade" id="{{ $modalID }}" tabindex="-1" aria-labelledby="{{ $modalID }}-label" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="{{ $modalID }}-label">{{ trans('admin.filter') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="{!! $action !!}" method="get">
                <div class="modal-body">
                    @foreach($filters as $filter)
                        <div class="admin-field mb-3">
                            {!! $filter->render() !!}
                        </div>
                    @endforeach
                </div>
                <div class="modal-footer">
                    <button type="reset" class="btn btn-outline-warning me-auto">{{ trans('admin.reset') }}</button>
                    <button type="submit" class="btn btn-primary submit">{{ trans('admin.submit') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
