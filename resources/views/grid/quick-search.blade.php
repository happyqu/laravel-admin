<form action="{!! $action !!}" class="d-inline-block">
    <div class="input-group input-group-sm">
        <input type="text" name="{{ $key }}" class="form-control grid-quick-search" style="width: 200px;"
               value="{{ $value }}" placeholder="{{ $placeholder }}">
        <button type="submit" class="btn btn-outline-secondary"><i class="fa fa-search" aria-hidden="true"></i></button>
    </div>
</form>
