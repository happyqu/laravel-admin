<div class="card card-{{ $style }}">
    <div class="card-header d-flex align-items-center">
        <h3 class="card-title mb-0">{{ $title }}</h3>
        <div class="card-tools ms-auto">{!! $tools !!}</div>
    </div>
    <div class="card-body">
        <div class="fields-group">
            @foreach($fields as $field)
                {!! $field->render() !!}
            @endforeach
        </div>
    </div>
</div>
