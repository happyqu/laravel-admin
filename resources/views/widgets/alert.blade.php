<div {!! $attributes !!} role="alert">
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    <h4 class="alert-heading"><i class="icon fa fa-{{ $icon }}" aria-hidden="true"></i> {{ $title }}</h4>
    {!! $content !!}
</div>
