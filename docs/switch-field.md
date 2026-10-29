# 表单开关

默认开启保存 `1`，关闭保存 `0`，无需配置状态数组：

```php
$form->switch('enabled', '状态');
```

用快捷方法分别设置文案、颜色、保存值，参数顺序均为“开启、关闭”：

```php
$form->switch('enabled', '状态')
    ->texts('打开', '关闭')
    ->colors('success', 'danger');

$form->switch('status', '状态')
    ->values('enabled', 'disabled')
    ->texts('启用', '停用');
```

未配置的项目沿用默认值：文案 `ON / OFF`、颜色 `primary / default`。
颜色使用 Bootstrap Switch 支持的 `primary`、`info`、`success`、`warning`、`danger`、`default`。

原有 `states()` 继续可用，也能与快捷方法混用；同一配置项以后一次设置为准。

```php
$form->switch('enabled', '状态')
    ->states(['on' => ['text' => '打开', 'color' => 'success']])
    ->texts('启用', '停用');
```

表单提交的 `on / off` 由字段转换为配置的保存值。渲染不再将字段原值改写为这两个标记。
表格的 `switch($states)` 和 `switchGroup($columns, $states)` 保持原有调用方式，使用相同的状态匹配逻辑。
