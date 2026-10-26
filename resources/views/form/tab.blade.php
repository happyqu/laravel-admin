<div class="card">
    <div class="card-header p-0">
        <ul class="nav nav-tabs card-header-tabs" role="tablist">
            @foreach($tabObj->getTabs() as $tab)
                <li class="nav-item" role="presentation">
                    <a class="nav-link {{ $tab['active'] ? 'active' : '' }}" id="tab-{{ $tab['id'] }}-tab"
                       href="#tab-{{ $tab['id'] }}" data-bs-toggle="tab" role="tab"
                       aria-controls="tab-{{ $tab['id'] }}" aria-selected="{{ $tab['active'] ? 'true' : 'false' }}">
                        {{ $tab['title'] }} <i class="fa fa-exclamation-circle text-danger d-none"></i>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
    <div class="card-body">
        <div class="tab-content fields-group">
            @foreach($tabObj->getTabs() as $tab)
                <div class="tab-pane fade {{ $tab['active'] ? 'show active' : '' }}" id="tab-{{ $tab['id'] }}" role="tabpanel"
                     aria-labelledby="tab-{{ $tab['id'] }}-tab">
                    @foreach($tab['fields'] as $field)
                        {!! $field->render() !!}
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
</div>
