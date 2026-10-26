<div class="{{ $expand ? '' : 'd-none' }} filter-box border-bottom p-3" id="{{ $filterID }}">
    <form action="{!! $action !!}" method="get">
        <div class="row g-3">
            @foreach($layout->columns() as $column)
                <div class="col-md-{{ $column->width() }} admin-filter-column">
                    <div class="fields-group">
                        @foreach($column->filters() as $filter)
                            {!! $filter->render() !!}
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="row mt-2">
            <div class="col-md-{{ $layout->columns()->first()->width() }}">
                <div class="row">
                    <div class="offset-sm-2 col-sm-8 d-flex gap-2">
                        <button class="btn btn-primary submit btn-sm">
                            <i class="fa fa-search" aria-hidden="true"></i>&nbsp;&nbsp;{{ trans('admin.search') }}
                        </button>
                        <a href="{!! $action !!}" class="btn btn-outline-secondary btn-sm">
                            <i class="fa fa-undo" aria-hidden="true"></i>&nbsp;&nbsp;{{ trans('admin.reset') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
