<style>
    .ext-icon {
        color: rgba(0, 0, 0, 0.5);
    }
    .installed {
        color: var(--bs-success);
    }
</style>

<div class="card card-outline card-secondary">
    <div class="card-header d-flex align-items-center">
        <h3 class="card-title mb-0">Available extensions</h3>
        <div class="card-tools ms-auto d-flex gap-2">
            <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Collapse">
                <i class="fa fa-minus" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-tool" data-lte-toggle="card-remove" aria-label="Remove">
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
        </div>
    </div>

    <ul class="list-group list-group-flush">
        @foreach($extensions as $extension)
            <li class="list-group-item d-flex align-items-center">
                <i class="fa fa-{{ $extension['icon'] }} fa-2x ext-icon me-3" aria-hidden="true"></i>
                <a href="{{ $extension['link'] }}" target="_blank" rel="noopener" class="flex-grow-1 text-decoration-none">
                    {{ $extension['name'] }}
                </a>
                @if($extension['installed'])
                    <span class="installed" title="Installed"><i class="fa fa-check" aria-hidden="true"></i></span>
                @endif
            </li>
        @endforeach
    </ul>

    <div class="card-footer text-center">
        <a href="https://github.com/laravel-admin-extensions" target="_blank" rel="noopener">View All Extensions</a>
    </div>
</div>
