<li class="nav-item tab-options dropdown">
    <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
        {{ $trans['oprations'] }}
    </a>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item tabReload" href="javascript:;" onclick="refreshTab();">{{ $trans['refresh_current'] }}</a></li>
        <li><a class="dropdown-item tabCloseCurrent" href="javascript:;" onclick="closeCurrentTab();">{{ $trans['close_current'] }}</a></li>
        <li><a class="dropdown-item tabCloseAll" href="javascript:;" onclick="closeOtherTabs(true);">{{ $trans['close_all'] }}</a></li>
        <li><a class="dropdown-item tabCloseOther" href="javascript:;" onclick="closeOtherTabs();">{{ $trans['close_other'] }}</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item tabscrollLeft" href="javascript:;" onclick="scrollTabLeft();">{{ $trans['scroll_left'] }}</a></li>
        <li><a class="dropdown-item tabscrollRight" href="javascript:;" onclick="scrollTabRight();">{{ $trans['scroll_right'] }}</a></li>
        <li><a class="dropdown-item tabscrollRight" href="javascript:;" onclick="scrollTabCurrent();">{{ $trans['scroll_current'] }}</a></li>
    </ul>
</li>
