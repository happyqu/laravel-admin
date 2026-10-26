<style>
    .nav-tabs > li:hover > i {
        display: inline;
    }
    .close-tab {
        position: absolute;
        font-size: 10px;
        top: 2px;
        right: 5px;
        color: #94A6B0;
        cursor: pointer;
        display: none;
    }
</style>
<div id="has-many-{{ $column }}" class="card has-many-{{ $column }}">
    <div class="card-header">
        <div class="row align-items-center">
            <div class="col-md-2 {{ $viewClass['label'] }}"><h4 class="float-end mb-0">{{ $label }}</h4></div>
            <div class="col-md-8 {{ $viewClass['field'] }}">
                <button type="button" class="btn btn-secondary btn-sm add"><i class="fa fa-plus-circle" style="font-size: large;"></i></button>
            </div>
        </div>
    </div>

    <div class="card-body">
        <ul class="nav nav-tabs" role="tablist">
            @foreach($forms as $pk => $form)
                <li class="nav-item" role="presentation">
                    <a class="nav-link @if($form == reset($forms)) active @endif"
                       href="#{{ $relationName.'_'.$pk }}" data-bs-toggle="tab" role="tab">
                        {{ $pk }} <i class="fa fa-exclamation-circle text-danger d-none"></i>
                    </a>
                    <i class="close-tab fa fa-times"></i>
                </li>
            @endforeach
        </ul>

        <div class="tab-content has-many-{{ $column }}-forms pt-3">
            @foreach($forms as $pk => $form)
                <div class="tab-pane fade fields-group has-many-{{ $column }}-form @if($form == reset($forms)) show active @endif"
                     id="{{ $relationName.'_'.$pk }}" role="tabpanel">
                    @foreach($form->fields() as $field)
                        {!! $field->render() !!}
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    <template class="nav-tab-tpl">
        <li class="nav-item new" role="presentation">
            <a class="nav-link" href="#{{ $relationName.'_new_'.\Encore\Admin\Form\NestedForm::DEFAULT_KEY_NAME }}"
               data-bs-toggle="tab" role="tab">
                &nbsp;New {{ \Encore\Admin\Form\NestedForm::DEFAULT_KEY_NAME }} <i class="fa fa-exclamation-circle text-danger d-none"></i>
            </a>
            <i class="close-tab fa fa-times"></i>
        </li>
    </template>
    <template class="pane-tpl">
        <div class="tab-pane fade fields-group new" id="{{ $relationName.'_new_'.\Encore\Admin\Form\NestedForm::DEFAULT_KEY_NAME }}" role="tabpanel">
            {!! $template !!}
        </div>
    </template>
</div>
