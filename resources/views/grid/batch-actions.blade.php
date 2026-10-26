@if(!$holdAll)
<div class="btn-group {{ $all }}-btn" style="display:none;margin-right: 5px;">
    <span class="btn btn-sm btn-secondary disabled"><span class="selected"></span></span>
    <button type="button" class="btn btn-sm btn-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
        <span class="visually-hidden">Toggle Dropdown</span>
    </button>
    @if(!$actions->isEmpty())
        <ul class="dropdown-menu">
            @foreach($actions as $action)
                @if($action instanceof \Encore\Admin\Actions\BatchAction)
                    <li>{!! $action->render() !!}</li>
                @else
                    <li><a href="#" class="dropdown-item {{ $action->getElementClass(false) }}">{!! $action->render() !!}</a></li>
                @endif
            @endforeach
        </ul>
    @endif
</div>
@endif

<script>
$('.{{ $all }}').on('change', function () {
    $('.{{ $row }}-checkbox').prop('checked', this.checked).trigger('change');
});
</script>
