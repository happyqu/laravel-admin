<div class="admin-field mb-3">
    <label>{{ $label }}</label>
    <input type="file" class="{{$class}}" name="{{$name}}[]" {!! $attributes !!} multiple/>
    @include('admin::actions.form.help-block')
</div>
