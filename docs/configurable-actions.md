# 可配置 Action

`CallbackAction`、`CallbackRowAction`、`CallbackBatchAction` 直接执行服务端配置的公共静态方法，无需为每个按钮声明子类。回调接收解析后的模型（批量 Action 接收选中的整个 Eloquent 集合）和当前请求。

```php
use Encore\Admin\Actions\CallbackRowAction;

$action = (new CallbackRowAction())
    ->setName('通过')
    ->setDialog('确定通过该订单？')
    ->setCallback(OrderActions::class, 'approve');
```

回调配置和动态名称通过签名后的 Action 上下文恢复。请求参数 `_class`、`_cb`、`_model` 不参与选择回调或模型。非表格 Action 用 `setModel($model)` 绑定记录；表格 Action 使用表格自身的记录上下文。

回调可以返回 Action `Response` 以控制提示和跳转。否则返回字符串表示错误信息，返回 `false` 表示失败，其它返回值表示成功，且不会自动追加刷新。覆盖 `callbackResponse($result)` 可以自定义这套判定。回调缺失、类或方法不存在、方法不是公共静态方法时均抛出异常，而不是报告成功。

## 确认弹窗

`setDialog()` 为没有表单的 Action 启用确认弹窗，包括未声明 `dialog()` 的 Action。

```php
(new CallbackRowAction())->setName('删除')->setDialog('确定删除？');
```

`form()` 和 `dialog()` 不能声明在同一个类上；对已声明其中任意一个的 Action 调用 `setDialog()` 会抛出 `LogicException`，避免 setter 静默覆盖类自身的配置。

## 名称

`setName()` 设置按钮文案。配置的名称会随签名上下文传递，因此执行时的 Action 与页面渲染时的文案一致。直接给 `$name` 赋值仍然只影响渲染，不进入凭据。

## 链接按钮

`RowAction::setHref($url, $target = null)` 输出转义后的链接，文案和属性都会转义。

```php
(new CallbackRowAction())->setName('详情')->setHref('/admin/orders/1', '_blank');
```

`ButtonAction` 在此基础上增加 `setButtonStyle()`、`setIcon()`，以及同样的 `setHref()` / `getHref()` 接口。

```php
use Encore\Admin\Actions\ButtonAction;

$button = (new ButtonAction())
    ->setName('同步')
    ->setIcon('fa-refresh')
    ->setButtonStyle('btn-primary')
    ->setCallback(OrderActions::class, 'sync');
```

链接形态只负责跳转，不会触发 Action 执行。`target` 为 `_blank` 时自动补充 `noopener noreferrer`。额外的链接属性用 `attribute()` 设置。链接配置同样进入签名上下文，重建后的 Action 保持相同的跳转目标。
