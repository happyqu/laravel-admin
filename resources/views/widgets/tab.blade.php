<div {!! $attributes !!}>
    <ul class="nav nav-tabs" role="tablist">
        @foreach($tabs as $id => $tab)
            @if($tab['type'] == \Encore\Admin\Widgets\Tab::TYPE_CONTENT)
                <li class="nav-item" role="presentation">
                    <a class="nav-link {{ $id == $active ? 'active' : '' }}" id="tab_{{ $tab['id'] }}-tab"
                       href="#tab_{{ $tab['id'] }}" data-bs-toggle="tab" role="tab"
                       aria-controls="tab_{{ $tab['id'] }}" aria-selected="{{ $id == $active ? 'true' : 'false' }}">
                        {{ $tab['title'] }}
                    </a>
                </li>
            @elseif($tab['type'] == \Encore\Admin\Widgets\Tab::TYPE_LINK)
                <li class="nav-item" role="presentation">
                    <a class="nav-link {{ $id == $active ? 'active' : '' }}" href="{{ $tab['href'] }}">
                        {{ $tab['title'] }}
                    </a>
                </li>
            @endif
        @endforeach

        @if (!empty($dropDown))
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">
                    Dropdown
                </a>
                <ul class="dropdown-menu">
                    @foreach($dropDown as $link)
                        <li><a class="dropdown-item" href="{{ $link['href'] }}">{{ $link['name'] }}</a></li>
                    @endforeach
                </ul>
            </li>
        @endif

        @if($title)
            <li class="nav-item ms-auto">
                <span class="nav-link disabled">{{ $title }}</span>
            </li>
        @endif
    </ul>

    <div class="tab-content pt-3">
        @foreach($tabs as $id => $tab)
            <div class="tab-pane fade {{ $id == $active ? 'show active' : '' }}" id="tab_{{ $tab['id'] }}" role="tabpanel"
                 aria-labelledby="tab_{{ $tab['id'] }}-tab">
                @php($content = \Illuminate\Support\Arr::get($tab, 'content'))
                @if($content instanceof \Illuminate\Contracts\Support\Renderable)
                    {!! $content->render() !!}
                @else
                    {!! $content !!}
                @endif
            </div>
        @endforeach
    </div>
</div>
