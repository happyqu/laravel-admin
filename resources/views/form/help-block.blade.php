@if($help)
    <div class="form-text">
        <i class="fa {{ \Illuminate\Support\Arr::get($help, 'icon') }}" aria-hidden="true"></i>&nbsp;{!! \Illuminate\Support\Arr::get($help, 'text') !!}
    </div>
@endif
