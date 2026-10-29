<style>
    td .form-group {
        margin-bottom: 0 !important;
    }
</style>

<div class="row">
    <div class="{{$viewClass['label']}}"><h4 class="pull-right">{{ $label }}</h4></div>
    <div class="{{$viewClass['field']}}">
        <div id="has-many-{{$domName ?? $column}}" @if($jsonTable ?? false) data-json-table @endif style="margin-top: 15px;">
            @if($jsonTable ?? false)
                <input type="hidden" name="{{$name}}[_present]" value="1">
            @endif
            <table class="table table-has-many has-many-{{$domName ?? $column}}">
                <thead>
                <tr>
                    @foreach($headers as $header)
                        <th>{{ $header }}</th>
                    @endforeach

                    <th class="hidden"></th>

                    @if($options['allowDelete'] || !($jsonTable ?? false))
                        <th></th>
                    @endif
                </tr>
                </thead>
                <tbody class="has-many-{{$domName ?? $column}}-forms">
                @foreach($forms as $pk => $form)
                    <tr class="has-many-{{$domName ?? $column}}-form fields-group" data-persisted="true" @if(!($jsonTable ?? false)) data-new="{{ $form->isNew() ? 'true' : 'false' }}" @endif>

                        <?php $hidden = ''; ?>

                        @foreach($form->fields() as $field)

                            @if (is_a($field, \Encore\Admin\Form\Field\Hidden::class))
                                <?php $hidden .= $field->render(); ?>
                                @continue
                            @endif

                            <td>{!! $field->setLabelClass(['hidden'])->setWidth(12, 0)->render() !!}</td>
                        @endforeach

                        <td class="hidden">{!! $hidden !!}</td>

                        @if($options['allowDelete'] || !($jsonTable ?? false))
                            <td class="form-group">
                                @if($options['allowDelete'] || $form->isNew())
                                <div>
                                    <div class="remove btn btn-warning btn-sm pull-right"><i class="fa fa-trash">&nbsp;</i>{{ trans('admin.remove') }}</div>
                                </div>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>

            <template class="{{$domName ?? $column}}-tpl">
                <tr class="has-many-{{$domName ?? $column}}-form fields-group" data-new="true">

                    {!! $template !!}

                    @if($options['allowDelete'] || !($jsonTable ?? false))
                    <td class="form-group">
                        <div>
                            <div class="remove btn btn-warning btn-sm pull-right"><i class="fa fa-trash">&nbsp;</i>{{ trans('admin.remove') }}</div>
                        </div>
                    </td>
                    @endif
                </tr>
            </template>

            @if($options['allowCreate'])
                <div class="form-group">
                    <div class="{{$viewClass['field']}}">
                        <div class="add btn btn-success btn-sm"><i class="fa fa-save"></i>&nbsp;{{ trans('admin.new') }}</div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

<hr style="margin-top: 0px;">

