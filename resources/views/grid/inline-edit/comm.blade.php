{{--inline edit popover--}}

<span class="ie-wrap">
    <a
        href="javascript:void(0);"
        class="{{ $trigger }}"
        data-ie-popover="1"
        data-ie-target="{{ $target }}"
        data-value="{{ $value }}"
        data-original="{{ $value }}"
        data-key="{{ $key }}"
        data-name="{{ $name }}"
        role="button"
    >
        <span class="ie-display">{{ $display }}</span>
        <i class="fa fa-edit" style="visibility: hidden;" aria-hidden="true"></i>
    </a>
</span>

<template>
    <template id="{{ $target }}">
        <div class="ie-content ie-content-{{ $name }}">
            <div class="ie-container">
                @yield('field')
                <div class="error text-danger small mt-1"></div>
            </div>
            <div class="ie-action d-flex justify-content-end gap-2 mt-2">
                <button type="button" class="btn btn-outline-secondary btn-sm ie-cancel">{{ __('admin.cancel') }}</button>
                <button type="button" class="btn btn-primary btn-sm ie-submit">{{ __('admin.submit') }}</button>
            </div>
        </div>
    </template>
</template>

<style>
    .ie-wrap > a {
        padding: 3px;
        border-radius: 3px;
        color: var(--bs-secondary-color);
        text-decoration: none;
    }

    .ie-wrap > a:hover {
        background-color: var(--bs-tertiary-bg);
        color: var(--bs-body-color);
    }

    .ie-wrap > a:hover i {
        visibility: visible !important;
    }

    .ie-container {
        width: 250px;
        position: relative;
    }

    .ie-container .error {
        font-weight: 600;
    }
</style>

<script>
    function hideInlinePopovers() {
        document.querySelectorAll('.ie-wrap > a').forEach(function (element) {
            var instance = window.bootstrap && window.bootstrap.Popover.getInstance(element);
            if (instance) {
                instance.hide();
            }
        });
    }

    $(document).on('click.adminPage', '.ie-action .ie-cancel', function () {
        hideInlinePopovers();
    });

    $('body').on('click.adminPage', function (e) {
        if ($(e.target).closest('.ie-wrap > a, .popover').length === 0) {
            hideInlinePopovers();
        }
    });
</script>

@yield('assert')
