<div class="row" id="admin-log-viewer">
    <div class="col-md-10">
        <div class="box box-primary">
            <div class="box-header with-border">
                <button type="button" class="btn btn-primary btn-sm log-refresh"><i class="fa fa-refresh"></i> {{ trans('admin.refresh') }}</button>
                @if($file !== '')
                    <form action="{{ route('log-viewer-delete', ['file' => $file, 'type' => $type]) }}" method="post" class="log-delete-form" style="display: inline-block">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-sm">{{ trans('admin.log_viewer.delete_file') }}</button>
                    </form>
                @endif
                @if(count($logFiles))
                    <form action="{{ route('log-viewer-deleteall', ['type' => $type]) }}" method="post" class="log-delete-form" style="display: inline-block">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-sm">{{ trans('admin.log_viewer.delete_all') }}</button>
                    </form>
                @endif
                <button type="button" class="btn btn-default btn-sm log-live" @if($tailPath === '') disabled @endif><i class="fa fa-play"></i> <span>{{ trans('admin.log_viewer.live') }}</span></button>
                <div class="btn-group pull-right">
                    @if($prevUrl)
                        <a href="{{ $prevUrl }}" class="btn btn-default btn-sm" title="{{ trans('admin.log_viewer.newer') }}"><i class="fa fa-chevron-left"></i></a>
                    @endif
                    @if($nextUrl)
                        <a href="{{ $nextUrl }}" class="btn btn-default btn-sm" title="{{ trans('admin.log_viewer.older') }}"><i class="fa fa-chevron-right"></i></a>
                    @endif
                </div>
            </div>
            <div class="box-body no-padding table-responsive">
                <table class="table table-hover log-table">
                    <thead><tr>
                        <th>{{ trans('admin.log_viewer.level') }}</th>
                        <th>{{ trans('admin.log_viewer.environment') }}</th>
                        <th>{{ trans('admin.log_viewer.time') }}</th>
                        <th>{{ trans('admin.log_viewer.message') }}</th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                        @forelse($logs as $log)
                            @include('admin::log-viewer.row', ['log' => $log])
                        @empty
                            <tr class="log-empty"><td colspan="5">{{ trans('admin.log_viewer.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        @if(count($directories) > 1)
            <div class="box box-solid">
                <div class="box-header"><h3 class="box-title">{{ trans('admin.log_viewer.directory') }}</h3></div>
                <ul class="nav nav-pills nav-stacked">
                    @foreach($directories as $directory)
                        <li @if($type === $directory) class="active" @endif><a href="{{ route('log-viewer-index', ['type' => $directory]) }}">{{ $directory }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endif
        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">{{ trans('admin.log_viewer.files') }}</h3></div>
            <ul class="nav nav-pills nav-stacked">
                @foreach($logFiles as $logFile)
                    <li @if($file === $logFile) class="active" @endif><a href="{{ route('log-viewer-file', ['file' => $logFile, 'type' => $type]) }}"><i class="fa fa-file-text-o"></i> {{ $logFile }}</a></li>
                @endforeach
            </ul>
            <div class="box-footer">{{ trans('admin.log_viewer.size') }}: {{ $size }}</div>
        </div>
    </div>
</div>
<script>
$(function () {
    var $viewer = $('#admin-log-viewer');
    var pos = @json($end);
    var tailUrl = @json($tailPath);
    var interval = null;
    var pending = null;

    function stop() {
        clearInterval(interval);
        interval = null;
        $viewer.find('.log-live i').removeClass('fa-pause').addClass('fa-play');
    }

    $viewer.find('.log-refresh').on('click', function () { window.location.reload(); });
    $viewer.find('.log-delete-form').on('submit', function () {
        return window.confirm(@json(trans('admin.delete_confirm')));
    });
    $viewer.find('.log-live').on('click', function () {
        if (interval !== null) { stop(); return; }
        if (!tailUrl) { return; }
        $(this).find('i').removeClass('fa-play').addClass('fa-pause');
        interval = setInterval(function () {
            if (pending) { return; }
            pending = $.ajax({url: tailUrl, data: {offset: pos}, dataType: 'json', adminLoading: false}).done(function (data) {
                if (data.logs.length) {
                    var $body = $viewer.find('.log-table > tbody');
                    $body.find('.log-empty').remove();
                    $body.prepend(data.logs.join(''));
                    $body.children().slice(2000).remove();
                }
                pos = data.pos;
            }).fail(function (xhr) {
                stop();
                if (xhr.status !== 401 && xhr.status !== 419) {
                    toastr.error(@json(trans('admin.request_failed')));
                }
            }).always(function () { pending = null; });
        }, 2000);
    });
    $(window).on('pagehide', function () { stop(); if (pending) { pending.abort(); } });
});
</script>
