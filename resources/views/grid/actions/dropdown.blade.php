<div class="grid-dropdown-actions dropdown dropstart">
    <a href="#" class="grid-row-actions-toggle" data-bs-toggle="dropdown" aria-expanded="false" role="button" aria-label="{{ trans('admin.action') }}">
        <i class="fa fa-ellipsis-v" aria-hidden="true"></i>
    </a>
    <ul class="dropdown-menu dropdown-menu-end">
        @foreach($default as $action)
            <li>{!! $action->render() !!}</li>
        @endforeach

        @if(!empty($custom))
            @if(!empty($default))
                <li><hr class="dropdown-divider"></li>
            @endif

            @foreach($custom as $action)
                <li>{!! $action->render() !!}</li>
            @endforeach
        @endif
    </ul>
</div>

<script>
    $('.table-responsive').off('.adminGridDropdown').on('shown.bs.dropdown.adminGridDropdown', function (e) {
        var t = $(this),
            m = $(e.target).closest('.grid-dropdown-actions').find('.dropdown-menu');
        if (!m.length) { return; }
        var tb = t.offset().top + t.height(),
            mb = m.offset().top + m.outerHeight(true),
            d = 20;
        if (t[0].scrollWidth > t.innerWidth()) {
            if (mb + d > tb) {
                t.css('padding-bottom', ((mb + d) - tb));
            }
        } else {
            t.css('overflow', 'visible');
        }
    }).on('hidden.bs.dropdown.adminGridDropdown', function () {
        $(this).css({'padding-bottom': '', 'overflow': ''});
    });
</script>

@yield('child')
