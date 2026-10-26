<div class="row mb-3">
    <label class="col-sm-{{ $width['label'] }} col-form-label">{{ $label }}</label>
    <div class="col-sm-{{ $width['field'] }}">
        @if($wrapped)
            <div class="card card-outline card-secondary mb-0">
                <div class="card-body py-2">
                    @if($escape)
                        {{ $content }}&nbsp;
                    @else
                        {!! $content !!}&nbsp;
                    @endif
                </div>
            </div>
        @else
            @if($escape)
                {{ $content }}
            @else
                {!! $content !!}
            @endif
        @endif
    </div>
</div>
