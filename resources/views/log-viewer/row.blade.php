@php($rowId = 'log-trace-'.uniqid())
<tr @if($highlight ?? false) style="background-color: #ffffd5" @endif>
    <td><span class="label bg-{{ \Encore\Admin\LogViewer\LogViewer::$levelColors[$log['level']] ?? 'black' }}">{{ $log['level'] }}</span></td>
    <td>{{ $log['env'] }}</td>
    <td style="width: 160px">{{ $log['time'] }}</td>
    <td><code style="word-break: break-all">{{ $log['info'] }}</code></td>
    <td>
        @if($log['trace'] !== '')
            <button type="button" class="btn btn-primary btn-xs" data-toggle="collapse" data-target="#{{ $rowId }}"><i class="fa fa-info"></i> {{ trans('admin.log_viewer.trace') }}</button>
        @endif
    </td>
</tr>
@if($log['trace'] !== '')
<tr class="collapse" id="{{ $rowId }}">
    <td colspan="5"><pre style="white-space: pre-wrap; background: #333; color: #fff">{{ $log['trace'] }}</pre></td>
</tr>
@endif
