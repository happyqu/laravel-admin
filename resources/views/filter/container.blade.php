<div class="{{ $expand ? '' : 'd-none' }} filter-box border-bottom p-3" id="{{ $filterID }}">
    <form action="{!! $action !!}" method="get">
        <div class="row g-3">
            @foreach($layout->columns() as $column)
                <div class="col-md-{{ $column->width() }}">
                    <div class="fields-group">
                        @foreach($column->filters() as $filter)
                            {!! $filter->render() !!}
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="d-flex gap-2 mt-3">
            <button class="btn btn-primary submit btn-sm">
                <i class="fa fa-search" aria-hidden="true"></i>&nbsp;&nbsp;{{ trans('admin.search') }}
            </button>
            <a href="{!! $action !!}" class="btn btn-outline-secondary btn-sm">
                <i class="fa fa-undo" aria-hidden="true"></i>&nbsp;&nbsp;{{ trans('admin.reset') }}
            </a>
        </div>
    </form>
</div>
