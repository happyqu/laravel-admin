@if(Admin::user()->visible(\Illuminate\Support\Arr::get($item, 'roles', [])) && Admin::user()->can(\Illuminate\Support\Arr::get($item, 'permission')))
    @php
        $title = $item['title'];
        if (Lang::has($titleTranslation = 'admin.menu_titles.'.trim(str_replace(' ', '_', strtolower($title))))) {
            $title = __($titleTranslation);
        } else {
            $title = admin_trans($title);
        }
        $icon = $item['icon'] ?: 'fa-circle-o';
    @endphp

    @if(!isset($item['children']))
        <li class="nav-item">
            @if(url()->isValidUrl($item['uri']))
                <a href="{{ $item['uri'] }}" target="_blank" rel="noopener" class="nav-link">
            @else
                <a href="{{ admin_url($item['uri']) }}" class="nav-link">
            @endif
                    <i class="nav-icon fa {{ $icon }}" aria-hidden="true"></i>
                    <p><span>{{ $title }}</span></p>
                </a>
        </li>
    @else
        <li class="nav-item">
            <a href="#" class="nav-link">
                <i class="nav-icon fa {{ $icon }}" aria-hidden="true"></i>
                <p>
                    <span>{{ $title }}</span>
                    <i class="nav-arrow fa fa-angle-right" aria-hidden="true"></i>
                </p>
            </a>
            <ul class="nav nav-treeview">
                @foreach($item['children'] as $item)
                    @include('admin::partials.menu', $item)
                @endforeach
            </ul>
        </li>
    @endif
@endif
