<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = new \Illuminate\Foundation\Application(dirname(__DIR__, 3));
$app->instance('config', new \Illuminate\Config\Repository(['app' => ['locale' => 'en']]));
$app->instance('session', new \Illuminate\Session\Store('fixture', new \Illuminate\Session\ArraySessionHandler(120)));
$app->instance('request', \Illuminate\Http\Request::create('/admin/items'));
$app->instance('files', new \Illuminate\Filesystem\Filesystem());
$app->register(\Illuminate\Translation\TranslationServiceProvider::class);
\Illuminate\Support\Facades\Facade::setFacadeApplication($app);
\Encore\Admin\Facades\Admin::swap(new \Encore\Admin\Admin());

$scripts = [];
foreach (['default', 'tab', 'table'] as $mode) {
    \Encore\Admin\Admin::$script = [];
    $field = new class('rows', ['Rows', function () {}]) extends \Encore\Admin\Form\Field\HasMany {
        public function emit($mode) { $this->mode($mode)->setupScript(''); }
    };
    $field->emit($mode);
    $scripts[$mode] = implode("\n", \Encore\Admin\Admin::$script);
}

$field = new class('labels', ['Labels']) extends \Encore\Admin\Form\Field\Tags {
    public function emit() { $this->setupScript(); return $this->getScript(); }
};
\Encore\Admin\Admin::$script = [];
$scripts['tags'] = $field->emit()."\n".implode("\n", \Encore\Admin\Admin::$script);
$scripts['select'] = (new \Encore\Admin\Form\Field\Select('owner', ['Owner']))->ajax('/api/users')->getScript();

$field = new class('items', ['Items']) extends \Encore\Admin\Form\Field\ListField {
    public function emit() { $this->setupScript(); return $this->getScript(); }
};
$scripts['collection'] = $field->emit();
\Encore\Admin\Admin::$script = [];
$field = new class('choices', ['Choices']) extends \Encore\Admin\Form\Field\CheckboxButton {
    public function emit() { $this->addScript(); }
};
$field->emit();
$scripts['checkboxButton'] = implode("\n", \Encore\Admin\Admin::$script);

$field = new class('choices', ['Choices']) extends \Encore\Admin\Form\Field\MultipleSelect {
    public function emit() {
        $this->conditions = [['operator' => 'oneIn', 'value' => ['x']]];
        $this->value = ['x'];
        $this->addCascadeScript();
    }
};
\Encore\Admin\Admin::$script = [];
$field->emit();
$scripts['cascade'] = implode("\n", \Encore\Admin\Admin::$script);

foreach (['in', 'notIn', 'has', 'oneIn', 'oneNotIn'] as $operator) {
    foreach ([0, 1, 3] as $value) {
        $field = new class('status', ['Status']) extends \Encore\Admin\Form\Field\Select {
            public function emit($operator, $value) {
                $this->conditions = [['operator' => $operator, 'value' => $operator === 'has' ? '1' : ['0', '1']]];
                $this->value = in_array($operator, ['in', 'notIn']) ? $value : [$value];
                $this->addCascadeScript();
            }
        };
        \Encore\Admin\Admin::$script = [];
        $field->emit($operator, $value);
        $scripts['cascade-'.$operator.'-'.$value] = implode("\n", \Encore\Admin\Admin::$script);
    }
}

\Encore\Admin\Admin::$script = [];
(new \Encore\Admin\Form\Field\Select('first', ['First']))->loads(['firstChild'], ['/api/first']);
(new \Encore\Admin\Form\Field\Select('second', ['Second']))->loads(['secondChild'], ['/api/second']);
$scripts['loads'] = implode("\n", \Encore\Admin\Admin::$script);
$field = new class('items', ['Items']) extends \Encore\Admin\Form\Field\Listbox {
    public function emit() { $this->loadRemoteOptions('/api/items'); return $this->getScript(); }
};
$scripts['listbox'] = $field->emit();
\Encore\Admin\Admin::$script = [];
$field = new class extends \Encore\Admin\Form\Field\BelongsToMany {
    public function __construct() { $this->column = 'related'; $this->modalID = 'related-modal'; }
    protected function getLoadUrl($multiple = 0) { return '/admin/selectable?multiple='.$multiple; }
    public function emit() { $this->addScript(); }
};
$field->emit();
$scripts['belongsToMany'] = implode("\n", \Encore\Admin\Admin::$script);

echo json_encode($scripts, JSON_THROW_ON_ERROR);
