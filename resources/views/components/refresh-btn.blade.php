<li class="nav-item">
    <a href="javascript:void(0);" class="nav-link container-refresh" role="button" title="{{ trans('admin.refresh') }}" aria-label="{{ trans('admin.refresh') }}">
        <i class="fa fa-refresh" aria-hidden="true"></i>
    </a>
</li>
<script>
    $('.container-refresh').off('click').on('click', function() {
        $.admin.reload();
        $.admin.toastr.success('{{ __('admin.refresh_succeeded') }}', '', {positionClass:"toast-top-center"});
    });
</script>
