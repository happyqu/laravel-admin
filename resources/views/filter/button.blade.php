<div class="btn-group" style="margin-right: 5px">
    <button type="button" class="btn btn-sm btn-outline-secondary {{ $btn_class }} {{ $expand ? 'active' : '' }}" title="{{ trans('admin.filter') }}" aria-controls="{{ $filter_id }}" aria-expanded="{{ $expand ? 'true' : 'false' }}">
        <i class="fa fa-filter" aria-hidden="true"></i><span class="d-none d-sm-inline">&nbsp;&nbsp;{{ trans('admin.filter') }}</span>
    </button>

    @if($scopes->isNotEmpty())
        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
            <span>{{ $label }}</span>
        </button>
        <ul class="dropdown-menu">
            @foreach($scopes as $scope)
                {!! $scope->render() !!}
            @endforeach
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="{{ $cancel }}">{{ trans('admin.cancel') }}</a></li>
        </ul>
    @endif
</div>

<script>
var $btn = $('.{{ $btn_class }}');
var $filter = $('#{{ $filter_id }}');

$btn.off('click').on('click', function () {
    $filter.toggleClass('d-none');
    $btn.toggleClass('active', !$filter.hasClass('d-none')).attr('aria-expanded', String(!$filter.hasClass('d-none')));
});
</script>
