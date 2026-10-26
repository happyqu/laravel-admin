<div class="card admin-form-card">
    <div class="card-header d-flex align-items-center">
        <h3 class="card-title mb-0">{{ $form->title() }}</h3>
        <div class="card-tools ms-auto">{!! $form->renderTools() !!}</div>
    </div>

    {!! $form->open() !!}

    <div class="card-body">
        @if(!$tabObj->isEmpty())
            @include('admin::form.tab', compact('tabObj'))
        @else
            <div class="fields-group">
                @if($form->hasRows())
                    @foreach($form->getRows() as $row)
                        {!! $row->render() !!}
                    @endforeach
                @else
                    <div class="row">
                        @foreach($layout->columns() as $column)
                            <div class="col-md-{{ $column->width() }}">
                                @foreach($column->fields() as $field)
                                    {!! $field->render() !!}
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>

    {!! $form->renderFooter() !!}

    @foreach($form->getHiddenFields() as $field)
        {!! $field->render() !!}
    @endforeach

    {!! $form->close() !!}
</div>
