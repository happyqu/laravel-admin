<div class="{{$viewClass['form-group']}}">
    <label class="{{$viewClass['label']}} col-form-label">{{$label}}</label>
    <div class="{{$viewClass['field']}}">
        <div class="card card-outline card-secondary mb-0">
            <!-- /.card-header -->
            <div class="card-body">
                @if($escape ?? true)
                    {{ $value }}&nbsp;
                @else
                    {!! $value !!}&nbsp;
                @endif
            </div><!-- /.card-body -->
        </div>

        @include('admin::form.help-block')

    </div>
</div>