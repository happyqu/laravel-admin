<div {!! $attributes !!}>
    <div class="inner">
        <h3>{{ $info }}</h3>
        <p>{{ $name }}</p>
    </div>
    <i class="small-box-icon fa fa-{{ $icon }}" aria-hidden="true"></i>
    <a href="{{ $link }}" class="small-box-footer">
        {{ trans('admin.more') }}&nbsp;
        <i class="fa fa-arrow-circle-right" aria-hidden="true"></i>
    </a>
</div>
