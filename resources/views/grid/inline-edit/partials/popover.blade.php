$('.{{ $trigger }}').each(function () {
    var $trigger = this;
    var $template = $($('template#' + $($trigger).data('ie-target')).html());

    {{ $content }}

    var popover = new bootstrap.Popover($trigger, {
        html: true,
        sanitize: false,
        container: 'body',
        trigger: 'manual',
        placement: 'bottom',
        fallbackPlacements: ['top', 'right', 'left'],
        content: $template[0]
    });

    $($trigger).on('shown.bs.popover', function () {
        var instance = bootstrap.Popover.getInstance(this);
        var $popover = $(instance.tip).find('.ie-content');
        var $display = $(this).parents('.ie-wrap').find('.ie-display');
        var $trigger = $(this);

        $popover.data('display', $display);
        $popover.data('trigger', $trigger);

        {{ $shown ?? '' }}
    }).on('click', function (event) {
        event.preventDefault();

        var instance = bootstrap.Popover.getInstance(this);

        if (instance && instance.tip && instance.tip.classList.contains('show')) {
            instance.hide();
            return;
        }

        hideInlinePopovers();

        instance = instance || new bootstrap.Popover(this, {
            html: true,
            sanitize: false,
            container: 'body',
            trigger: 'manual',
            placement: 'bottom',
            fallbackPlacements: ['top', 'right', 'left'],
            content: $template[0]
        });

        instance.show();
    });
});
