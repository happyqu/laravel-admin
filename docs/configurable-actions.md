# Configurable actions

`CallbackAction`, `CallbackRowAction`, and `CallbackBatchAction` execute a public
static method configured by the server. The callback receives the resolved model
(or the entire selected Eloquent collection for a batch) and the request.

```php
use Encore\Admin\Actions\CallbackRowAction;

$action = (new CallbackRowAction())
    ->setName('Approve')
    ->setDialog('Approve this order?')
    ->setCallback(OrderActions::class, 'approve');
```

Callback configuration and dynamic names are restored through the signed action
context. Request fields such as `_class`, `_cb`, and `_model` do not select the
callback or model. Configure a model for a non-grid action with `setModel($model)`;
grid actions use the grid's record context.

A callback may return an action `Response` to control messages and navigation.
Otherwise, a string becomes an error message, `false` becomes a failure, and other
results indicate success. No refresh is added automatically. Override
`callbackResponse($result)` to customize this policy. An absent or invalid
callback throws instead of reporting success.

`setDialog()` enables confirmation on actions without a form, including actions
that do not declare `dialog()`. Both `form()` and `dialog()` may not be declared on
the same class, and calling `setDialog()` on an action that declares either one
throws a `LogicException`.

`setName()` sets the button label. The configured name is carried through the signed
context, so the executing action uses the same label that was rendered. Assigning
`$name` directly remains display-only and never enters the ticket.

`RowAction::setHref($url, $target = null)` produces a link with escaped text and
attributes. `ButtonAction` adds `setButtonStyle()`, `setIcon()`, and the same
`setHref()` / `getHref()` API. Links skip action execution. Setting `_blank`
adds `noopener noreferrer`. Custom link attributes use `attribute()`.
The link configuration is signed as well, so a rebuilt action keeps the same target.

```php
use Encore\Admin\Actions\ButtonAction;

$button = (new ButtonAction())
    ->setName('Sync')
    ->setIcon('fa-refresh')
    ->setButtonStyle('btn-primary')
    ->setCallback(OrderActions::class, 'sync');
```
