<div {!! $attributes !!} style="padding: 5px; border: 1px solid var(--bs-border-color); background-color: var(--bs-body-bg); width: {{ $width }}px;">
    <div class="carousel-indicators">
        @foreach($items as $key => $item)
            <button type="button" data-bs-target="#{!! $id !!}" data-bs-slide-to="{{ $key }}"
                    class="{{ $key == 0 ? 'active' : '' }}" aria-label="Slide {{ $key + 1 }}"></button>
        @endforeach
    </div>

    <div class="carousel-inner">
        @foreach($items as $key => $item)
            <div class="carousel-item {{ $key == 0 ? 'active' : '' }}">
                <img src="{{ url($item['image']) }}" alt="{{ $item['caption'] }}"
                     style="max-width: {{ $width }}px; max-height: {{ $height }}px; display: block; margin-left: auto; margin-right: auto;">
                <div class="carousel-caption">
                    {{ $item['caption'] }}
                </div>
            </div>
        @endforeach
    </div>

    <button class="carousel-control-prev" type="button" data-bs-target="#{!! $id !!}" data-bs-slide="prev">
        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
        <span class="visually-hidden">Previous</span>
    </button>
    <button class="carousel-control-next" type="button" data-bs-target="#{!! $id !!}" data-bs-slide="next">
        <span class="carousel-control-next-icon" aria-hidden="true"></span>
        <span class="visually-hidden">Next</span>
    </button>
</div>
