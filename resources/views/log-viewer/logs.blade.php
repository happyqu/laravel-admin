<div class="row" id="admin-log-viewer">
    <div class="col-md-10">
        <div class="card card-primary">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <button type="button" class="btn btn-primary btn-sm log-refresh">
                    <i class="fa fa-refresh" aria-hidden="true"></i> {{ trans('admin.refresh') }}
                </button>

                @if($file !== '')
                    <form action="{{ route('log-viewer-delete', ['file' => $file, 'type' => $type]) }}" method="post" class="log-delete-form d-inline-block">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-sm">{{ trans('admin.log_viewer.delete_file') }}</button>
                    </form>
                @endif

                @if(count($logFiles))
                    <form action="{{ route('log-viewer-deleteall', ['type' => $type]) }}" method="post" class="log-delete-form d-inline-block">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-sm">{{ trans('admin.log_viewer.delete_all') }}</button>
                    </form>
                @endif

                <button type="button" class="btn btn-outline-secondary btn-sm log-live" @if($tailPath === '') disabled @endif>
                    <i class="fa fa-play" aria-hidden="true"></i> <span>{{ trans('admin.log_viewer.live') }}</span>
                </button>

                <div class="btn-group ms-auto">
                    @if($prevUrl)
                        <a href="{{ $prevUrl }}" class="btn btn-outline-secondary btn-sm" title="{{ trans('admin.log_viewer.newer') }}">
                            <i class="fa fa-chevron-left" aria-hidden="true"></i>
                        </a>
                    @endif
                    @if($nextUrl)
                        <a href="{{ $nextUrl }}" class="btn btn-outline-secondary btn-sm" title="{{ trans('admin.log_viewer.older') }}">
                            <i class="fa fa-chevron-right" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </div>

            <div class="card-body p-0 table-responsive">
                <table class="table table-hover align-middle log-table mb-0">
                    <thead>
                    <tr>
                        <th>{{ trans('admin.log_viewer.level') }}</th>
                        <th>{{ trans('admin.log_viewer.environment') }}</th>
                        <th>{{ trans('admin.log_viewer.time') }}</th>
                        <th>{{ trans('admin.log_viewer.message') }}</th>
                        <th></th>
                    </tr>
                    </thead>
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
            <div class="card card-outline card-secondary">
                <div class="card-header"><h3 class="card-title mb-0">{{ trans('admin.log_viewer.directory') }}</h3></div>
                <div class="card-body p-0">
                    <ul class="nav flex-column nav-pills p-2">
                        @foreach($directories as $directory)
                            <li class="nav-item">
                                <a class="nav-link {{ $type === $directory ? 'active' : '' }}"
                                   href="{{ route('log-viewer-index', ['type' => $directory]) }}">{{ $directory }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <div class="card card-outline card-secondary">
            <div class="card-header"><h3 class="card-title mb-0">{{ trans('admin.log_viewer.files') }}</h3></div>
            <div class="card-body p-0">
                <ul class="nav flex-column nav-pills p-2">
                    @foreach($logFiles as $logFile)
                        <li class="nav-item">
                            <a class="nav-link {{ $file === $logFile ? 'active' : '' }}"
                               href="{{ route('log-viewer-file', ['file' => $logFile, 'type' => $type]) }}">
                                <i class="fa fa-file-text-o" aria-hidden="true"></i> {{ $logFile }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="card-footer">{{ trans('admin.log_viewer.size') }}: {{ $size }}</div>
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
    var confirming = false;

    function stop() {
        clearInterval(interval);
        interval = null;
        $viewer.find('.log-live i').removeClass('fa-pause').addClass('fa-play');
    }

    $viewer.find('.log-refresh').on('click', function () { $.admin.reload(); });

    $viewer.find('.log-delete-form').on('submit', function (event) {
        event.preventDefault();
        if (confirming) { return; }
        confirming = true;
        var form = this;
        var saving = false;
        $.admin.swal({
            title: @json(trans('admin.delete_confirm')),
            type: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#DD6B55',
            confirmButtonText: @json(trans('admin.confirm')),
            cancelButtonText: @json(trans('admin.cancel')),
            showLoaderOnConfirm: true,
            allowOutsideClick: function () { return !saving; },
            allowEscapeKey: function () { return !saving; },
            preConfirm: function () {
                if (saving) { return Promise.reject(); }
                saving = true;
                return $.admin.submitForm(form).then(function (saved) {
                    if (!saved) { throw new Error('Deletion failed.'); }
                    return saved;
                }).finally(function () { saving = false; });
            }
        }).catch(function () {}).finally(function () { confirming = false; });
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

    $(window).on('pagehide.adminLogViewer admin:before-navigate.adminLogViewer', function () {
        stop();
        if (pending) { pending.abort(); }
        $(window).off('.adminLogViewer');
    });
});
</script>
