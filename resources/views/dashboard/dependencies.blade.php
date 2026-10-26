<div class="card card-outline card-secondary">
    <div class="card-header d-flex align-items-center">
        <h3 class="card-title mb-0">Dependencies</h3>
        <div class="card-tools ms-auto d-flex gap-2">
            <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Collapse">
                <i class="fa fa-minus" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-tool" data-lte-toggle="card-remove" aria-label="Remove">
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
        </div>
    </div>

    <div class="card-body dependencies overflow-auto" style="max-height: 510px;">
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                @foreach($dependencies as $dependency => $version)
                    <tr>
                        <td width="240px">{{ $dependency }}</td>
                        <td><span class="badge text-bg-primary">{{ $version }}</span></td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
</div>
