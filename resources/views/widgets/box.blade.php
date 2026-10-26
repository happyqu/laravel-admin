<div {!! $attributes !!}>
    @if($title || $tools)
        <div class="card-header d-flex align-items-center">
            <h3 class="card-title mb-0">{{ $title }}</h3>
            <div class="card-tools ms-auto d-flex align-items-center gap-2">
                @foreach($tools as $tool)
                    {!! $tool !!}
                @endforeach
            </div>
        </div>
    @endif
    <div class="card-body">
        {!! $content !!}
    </div>
    @if($footer)
        <div class="card-footer">
            {!! $footer !!}
        </div>
    @endif
</div>
{{-- 由于widget box 有可能会用于expand，加载完页面后还没有对应的html，导致script失败，故只能和html写在一起。 --}}
<script>
    {!! $script !!}
</script>
