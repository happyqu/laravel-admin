@php($rowId = 'log-trace-'.uniqid())
<tr @if($highlight ?? false) class="table-warning" @endif>
    <td><span class="badge text-bg-{{ \Encore\Admin\LogViewer\LogViewer::$levelColors[$log['level']] ?? 'dark' }}">{{ $log['level'] }}</span></td>
    <td>{{ $log['env'] }}</td>
    <td style="width: 160px">{{ $log['time'] }}</td>
    <td><code style="word-break: break-all">{{ $log['info'] }}</code></td>
    <td>
        @if($log['trace'] !== '')
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#{{ $rowId }}" aria-expanded="false" aria-controls="{{ $rowId }}">
                <i class="fa fa-info" aria-hidden="true"></i> {{ trans('admin.log_viewer.trace') }}
            </button>
        @endif
    </td>
</tr>
@if($log['trace'] !== '')
    <tr class="collapse" id="{{ $rowId }}">
        <td colspan="5">
            <pre class="bg-dark text-white p-3 mb-0" style="white-space: pre-wrap;">{{ $log['trace'] }}</pre>
        </td>
    </tr>
@endif
