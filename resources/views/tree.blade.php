<div class="card">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <div class="btn-group">
            <a class="btn btn-primary btn-sm {{ $id }}-tree-tools" data-action="expand" title="{{ trans('admin.expand') }}">
                <i class="fa fa-plus-square-o" aria-hidden="true"></i>&nbsp;{{ trans('admin.expand') }}
            </a>
            <a class="btn btn-primary btn-sm {{ $id }}-tree-tools" data-action="collapse" title="{{ trans('admin.collapse') }}">
                <i class="fa fa-minus-square-o" aria-hidden="true"></i>&nbsp;{{ trans('admin.collapse') }}
            </a>
        </div>

        @if($useSave)
            <div class="btn-group">
                <a class="btn btn-info btn-sm {{ $id }}-save" title="{{ trans('admin.save') }}">
                    <i class="fa fa-save" aria-hidden="true"></i><span class="d-none d-sm-inline">&nbsp;{{ trans('admin.save') }}</span>
                </a>
            </div>
        @endif

        @if($useRefresh)
            <div class="btn-group">
                <a class="btn btn-warning btn-sm {{ $id }}-refresh" title="{{ trans('admin.refresh') }}">
                    <i class="fa fa-refresh" aria-hidden="true"></i><span class="d-none d-sm-inline">&nbsp;{{ trans('admin.refresh') }}</span>
                </a>
            </div>
        @endif

        <div class="btn-group">{!! $tools !!}</div>

        @if($useCreate)
            <div class="btn-group ms-auto">
                <a class="btn btn-success btn-sm" href="{{ url($path) }}/create">
                    <i class="fa fa-save" aria-hidden="true"></i><span class="d-none d-sm-inline">&nbsp;{{ trans('admin.new') }}</span>
                </a>
            </div>
        @endif
    </div>

    <div class="card-body p-0 table-responsive">
        <div class="dd" id="{{ $id }}">
            <ol class="dd-list">
                @each($branchView, $items, 'branch')
            </ol>
        </div>
    </div>
</div>
