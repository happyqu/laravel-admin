# Laravel Admin

`happyqu/laravel-admin` 是一个 Laravel 后台 Composer 包，内置 iframe 标签页、全局菜单、AJAX 内容导航，以及表格、表单和详情页组件。保留 `Encore\Admin` 命名空间，可通过 Composer 自动发现注册。

- 支持 Laravel 10、11、12、13，包自身要求 PHP 8.1+。
- 运行时仅依赖 `laravel/framework` 和必要的 PHP 扩展；图片处理、Excel 导出等功能按需安装依赖。
- 前端资源随包分发，安装和发布资源无需 npm 构建。
- 2.0 使用 AJAX 更新内容区，iframe 标签页已并入核心。

## 目录

- [环境要求](#环境要求)
- [安装与初始化](#安装与初始化)
- [标签页与菜单](#标签页与菜单)
- [页面导航与前端资源](#页面导航与前端资源)
- [表单与动态组件](#表单与动态组件)
- [操作与工具权限](#操作与工具权限)
- [上传安全](#上传安全)
- [日志查看器](#日志查看器)
- [升级指南](#升级指南)
- [常见问题](#常见问题)
- [开发与分发](#开发与分发)

## 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | `^8.1`；还需满足所选 Laravel 版本的 PHP 要求 |
| Laravel | `^10.0`、`^11.0`、`^12.0`、`^13.0` |
| PHP 扩展 | `ext-fileinfo`、`ext-dom` |

以下功能的依赖不默认安装：

| 功能 | 可选依赖 |
| --- | --- |
| 图片裁切、缩略图 | `intervention/image:^2.7` |
| Orderable Grid | `spatie/eloquent-sortable` |
| Excel 导出 | `maatwebsite/excel:^3.1` |
| 资源压缩 | `matthiasmullie/minify:^1.3` |

## 安装与初始化

### 安装包

```bash
composer require happyqu/laravel-admin:^2.0
```

本包支持 Composer 自动发现，正常情况下无需手工注册 Provider 或 Alias。宿主关闭自动发现时，先完成下方的手工注册，再执行发布与初始化命令。

### 发布与初始化

数据库配置完成后执行：

```bash
php artisan vendor:publish --tag=laravel-admin-config
php artisan vendor:publish --tag=laravel-admin-lang
php artisan vendor:publish --tag=laravel-admin-migrations
php artisan vendor:publish --tag=laravel-admin-assets
php artisan admin:install
```

`admin:install` 执行迁移、初始化后台目录和基础数据，并自动初始化内置标签页的首页菜单与权限。默认地址 `/admin`，初始账号、密码均为 `admin`，首次登录后请修改密码。

后台用户表已有数据时，`admin:install` 不会再次执行基础数据初始化；后台目录已存在时也不会覆盖该目录。

### 关闭自动发现时的手工注册

如果宿主关闭了自动发现，再按版本手工注册。

Laravel 10 的 `config/app.php`：

```php
'providers' => [
    // ...
    Encore\Admin\AdminServiceProvider::class,
],

'aliases' => [
    // ...
    'Admin' => Encore\Admin\Facades\Admin::class,
],
```

Laravel 11/12/13 的 `bootstrap/providers.php`：

```php
return [
    App\Providers\AppServiceProvider::class,
    Encore\Admin\AdminServiceProvider::class,
];
```

Alias 可在 `config/app.php` 的 `aliases` 中注册，或由宿主自行注册。

## 标签页与菜单

标签页默认开启，配置位于 `config/admin.php` 的 `iframe_tabs`：

```php
'iframe_tabs' => [
    'enable' => true,
    'home_action' => App\Admin\Controllers\HomeController::class.'@index',
    'home_title' => '首页',
],
```

未配置自定义首页时使用包内 dashboard。`/admin` 是标签容器，`/admin/dashboard` 是首页内容。设置 `iframe_tabs.enable=false` 后，安装生成的路由使用标准 `HomeController@index`。`admin.route.file` 可指定后台目录中的路由文件，默认 `routes.php`。

默认使用经典侧栏，在 `.env` 中设置 `ADMIN_GLOBAL_NAVIGATION=true` 可启用全局菜单。它支持搜索、业务分类、收藏、最近访问和导航模式切换，并按管理员隔离状态。

## 页面导航与前端资源

### 内容导航

后台内容区的同源链接与表单默认使用 AJAX；文件上传使用 `FormData`，保留服务器重定向、表单校验和 CSRF 校验。导出下载、外部链接及新窗口链接保持浏览器原有行为。个别链接或表单需要整页请求时，添加 `data-admin-ajax="false"`。

### 资源与加载反馈

所有资源统一维护在 `resources/assets`，发布到 `public/vendor/laravel-admin`。内置标签页资源位于该目录的 `iframe-tabs` 子目录。JS、CSS、图片、字体均随包分发，无需 npm 构建。

AJAX 请求超过 180 毫秒时显示居中的“加载中”提示，并发请求全部结束后关闭；不再显示顶部进度条。后台轮询等静默请求可在 `$.ajax` 选项中设置 `adminLoading: false`，其他错误处理保持正常。表单类 Action 在加载完成后才弹出表单，加载期间只显示“加载中”提示。

表单提交立即显示加载提示，并将提交按钮切换为“提交中”，避免重复提交。请求失败或校验未通过时保留表单内容并恢复按钮；普通页面切换采用 AJAX，浏览器前进后退会重新加载对应 URL 的内容并恢复滚动位置。

## 表单与动态组件

### 动态选择器、异步展开与弹窗

`_handle_selectable_` 和 `_handle_renderable_` 的 URL 由内置组件自动附带 `_component_token_`，凭据绑定当前会话用户、来源页面、组件类和参数；加载时重新检查来源页面权限。自定义代码若直接拼接这两个接口的 URL，需改用 `Encore\Admin\Security\ComponentSubmission::url($routeName, $routeParameters, $kind, $class, $payload)`：`$kind` 为 `selectable` 时，`$payload` 是构造参数列表；为 `renderable` 时，是包含当前记录主键的单元素数组。缺少或过期的凭据返回 419，篡改参数或失去来源权限返回 403。

### Widget 表单

Widget 表单的 `form()` 在每个实例中只构建一次，重复渲染和校验不会重复添加字段；`fill([])` 会清空已填数据，PATCH/DELETE 等方法通过 POST 与 `_method` 提交。表单渲染也会检查 `authorize()`，提交时递归过滤嵌套编辑器中未声明或只读的字段。分步表单的流程绑定用户、后台配置和页面查询参数，升级前已创建的分步流程需重新开始。

### 账号与布局

内置用户编辑和个人设置页面不回填密码哈希。编辑时密码留空保留原密码，创建用户要求密码及确认。布局列宽支持 1–12、比例值和响应式映射；无效列宽会抛出 `InvalidArgumentException`。

## 操作与工具权限

Action 和 Tool 共用一套角色、权限和 Gate / Policy 配置。根据按钮的执行方式选择类型：

| 类型 | 适用场景 | 授权范围 |
| --- | --- | --- |
| `Actions\RowAction` | 当前记录的操作按钮或 Action 列 | 展示、表单加载及执行时检查当前记录 |
| `Actions\BatchAction` | 批量操作 | 展示时检查通用条件，加载及执行时检查选中记录 |
| `Actions\Action` / `Actions\GridAction` | 通用按钮、工具栏动作 | 通过签名动作流程检查加载和执行权限 |
| `Grid\Tools\AbstractTool` / `Widgets\Tool` | 链接、下载、已有工具的包装 | 容器在渲染前检查权限；独立接口需自行授权 |

上表类型均位于 `Encore\Admin` 命名空间。以下 `RefundOrder`、`BatchApprove`、`ExportOrdersTool`、`Order` 和 `OrderPolicy` 为宿主项目自定义类。

### 通用配置 API

| 方法 | 规则 |
| --- | --- |
| `allowRoles(['finance', 'manager'])` | 至少属于其中一个角色，使用角色 slug |
| `denyRoles(['auditor'])` | 命中任一角色即拒绝，优先于允许条件 |
| `requirePermissions(['orders.refund', 'orders.view'])` | 必须拥有全部权限，使用权限 slug |
| `requireAnyPermission(['orders.export', 'reports.export'])` | 至少拥有其中一个权限 |
| `ability('refund')` | 通过当前后台用户的 Laravel Gate / Policy 检查当前记录；无记录的普通工具栏按钮检查全局 Gate |
| `ability('create', Order::class)` | 检查模型级别的 Policy，适合创建按钮 |
| `deniedAsDisabled('无退款权限')` | 无权时显示禁用文本及提示；默认隐藏 |

不同条件之间取交集；同一配置方法重复调用会替换该项。角色和权限列表不能为空，也不接受 ID、闭包或对象。权限检查沿用后台用户的 `can()`，保留其管理员行为；角色限制、显式拒绝及 Policy 不额外放行管理员。Action 以及配置了权限的 Tool 均要求用户登录。

### 行按钮、批量按钮与操作列

继承 `Encore\Admin\Actions\Action`、`RowAction`、`BatchAction` 的按钮可以在添加时配置权限：

```php
$grid->actions(function ($actions) {
    $actions->add(
        (new RefundOrder())
            ->allowRoles(['administrator', 'finance'])
            ->requirePermissions(['orders.refund'])
            ->ability('refund')
    );
});

$grid->batchActions(function ($batch) {
    $batch->add((new BatchApprove())->requirePermissions(['orders.approve'])->ability('approve'));
});

// Action 列支持类名，也支持配置好的实例；每行使用独立实例。
$grid->column('status')->action((new RefundOrder())->requirePermissions(['orders.refund'])->ability('refund'));
```

行按钮在展示时检查当前记录；批量按钮展示时只检查角色、权限和显式指定模型类的 ability，记录 Policy 在选择后逐条检查。批量中任意记录不通过就拒绝整次操作，不会静默跳过。加载表单和最终执行均重新查询记录并检查权限。已有 Action 的 `authorize($user, $model)` 和模型的 `actionAuthorize($user, $actionClass)` 继续生效；批量 Action 的 `authorize()` 仍接收完整选中集合。

直接跳转 URL 的 Action 只能通过上述配置控制按钮展示，目标路由仍须自行授权。旧的 `Encore\Admin\Grid\Tools\BatchAction` 及原始 HTML 按钮不属于此 API；自定义覆盖 `render()` 时，应先调用 `passesAuthorizationForRendering()`，拒绝时返回 `renderAuthorizationDenied()`，再生成按钮和凭据。

### Gate 与 Policy

`ability()` 使用 `Gate::forUser(Admin::user())`，以当前后台用户执行 Laravel Gate / Policy 检查。例如，退款功能权限通过后，再由 Policy 判断订单归属和状态。

在宿主 Provider 的 `boot()` 中注册模型与 Policy：

```php
\Illuminate\Support\Facades\Gate::policy(Order::class, OrderPolicy::class);
```

Policy 方法示例：

```php
class OrderPolicy
{
    public function refund($user, Order $order): bool
    {
        return $user->company_id === $order->company_id
            && $order->status === 'paid'
            && !$order->refunded_at;
    }
}
```

### 自定义 Tool

继承 `Encore\Admin\Grid\Tools\AbstractTool` 的工具直接支持相同的角色、权限、ability 和禁用提示配置：

```php
$grid->tools(function ($tools) {
    $tools->append(
        (new ExportOrdersTool())
            ->allowRoles(['administrator', 'finance'])
            ->requirePermissions(['orders.export'])
            ->ability('export', Order::class)
            ->deniedAsDisabled('无导出权限')
    );
});
```

其他自定义工具可以继承 `Encore\Admin\Widgets\Tool` 并实现 `render()`；已有的 Renderable、Htmlable 或 HTML 片段可以直接包装，不必修改原类：

```php
use Encore\Admin\Widgets\Tool;

$form->tools(function ($tools) {
    $tools->append((new Tool(new ExistingExportTool()))->requirePermissions(['orders.export']));
});

$show->panel()->tools(function ($tools) use ($order) {
    $tools->append(
        (new Tool('<a href="/admin/orders/'.$order->getKey().'/refund">退款</a>'))
            ->requirePermissions(['orders.refund'])
            ->ability('refund')
            ->forModel($order)
    );
});
```

Grid、Form、Detail、Tree、Navbar 的工具容器以及 Grid header/footer 返回的 Tool，都会先授权再渲染，拒绝时不执行工具的 `render()`，不注册其脚本。`forModel($model)` 用于绑定记录级 Policy；不绑定记录时 `ability('export')` 检查全局 Gate，也可以用 `ability('export', Order::class)` 检查模型级别的 Policy。Tool 可以定义 `authorize($user, $model)` 追加条件。不配置规则且没有 `authorize()` 的既有工具保持原行为。

多层 Tool 包装会同时检查外层和内层 Tool 的权限，`passesAuthorization()` 也遵循此规则。Grid 工具容器会在授权前逐层传递 Grid 上下文，包装的 `AbstractTool` 或 `GridAction` 可以继续访问所属 Grid。

在这些容器之外自行输出 Tool 时，使用 `$tool->renderAuthorized()` 或 `(string) $tool`；直接调用自定义的 `render()` 不经过容器检查。普通 Tool 的权限配置控制渲染，独立下载、跳转或 AJAX 接口仍应使用相同规则重建工具并调用 `abort_unless($tool->passesAuthorization(), 403)`，记录需由接口重新查询。如果工具需要统一的加载与提交授权，建议继承 `Actions\GridAction` 或 `Actions\Action`，使用前述签名动作流程。

### Action 加载与提交

动作凭据 `_action_token` 使用精简 JSON 参数与 HMAC 签名，绑定当前用户、登录会话和后台环境，不依赖 session 动作映射，也没有动作自身的固定过期时间。仅携带动作类、模型定位、回调参数、来源页面、权限配置、允许的记录范围和必要版本信息；每次执行重新查询记录并检查权限。凭据内容可读取，不应放入密码、密钥、查询结果或完整模型。退出重登、CSRF token 轮换或升级前旧格式凭据需要刷新来源页面；动作错误为独立业务错误，不使用 CSRF 的 419。

表单动作打开时调用一次 `POST _load_action_`，提交时直接调用 `_handle_action_`；普通确认框和批量确认直接提交，不再预加载、定时过期或续签。表单加载后绑定选择范围和用户看到的记录版本，冲突与失败保留输入，写请求不会自动重发。原有锁与完成标记继续使用原缓存，不新增数据库或文件存储，也不承诺缓存清空后的永久防重。

动作固定属性从类定义重建；动态回调继续使用 `parameters()`。少量非敏感运行时配置通过 `protected $contextProperties = ['headerList'];` 显式声明，默认不收集任何对象状态，显式配置上限 4 KiB、完整参数上限 64 KiB。RowAction 的 row、工具栏通过 `contextModel()` 绑定的模型以及批量记录均由服务端重新查询，模型关联按需加载，不写进凭据。

### 权限规则升级

按钮规则自动进入签名凭据，不需要加入 `$contextProperties`，客户端传入的规则不会生效。部署时如果收紧按钮的配置（例如新增退款权限），必须同时修改宿主 `config/admin.php` 的 `actions.authorization_version`（默认 `'1'`），例如改为 `'2'`，使旧页面凭据失效并要求刷新；仅撤销用户角色、权限或修改 Policy 判定会在下次请求立即检查，无需更新版本。

```php
// 宿主 config/admin.php；部署更严格的按钮规则时更新版本。
'actions' => [
    'authorization_version' => '2',
],
```

## 上传安全

内置文件、图片、多文件和多图片字段均执行服务器类型白名单、实际 MIME、文件名及目标路径检查，包含头像接口；保存前再次校验，防止绕过表单 validator。图片允许 JPEG、PNG、GIF、WebP、BMP，普通附件白名单位于 `admin.upload.allowed_extensions`。

PHP、PHTML、PHAR、HTML、SVG 等危险上传类型被禁止，不能通过白名单开启。历史上传文件和业务自建上传接口需要自行检查。

默认 `admin` 磁盘为 `public/uploads`；宿主自定义同名磁盘优先。图片裁切、缩略图等功能按需安装 `intervention/image:^2.7`。

## 日志查看器

内置日志查看器，无需额外安装或注册 Provider。默认入口为 `/admin/logs`（跟随后台前缀），支持文件切换、分页、异常详情、实时追加、删除当前文件和删除当前目录内的全部 `.log` 文件。

新安装会自动创建菜单和 `ext.log-viewer` 权限；已有项目执行一次：

```bash
php artisan admin:import log-viewer
```

重复执行不会重复添加菜单和权限。为普通角色分配 Logs 权限后即可访问；入口沿用后台认证、路由权限和 CSRF 中间件。操作日志仍在 `auth/logs`，应用文件日志在 `logs`。

默认读取 `storage/logs`。可在宿主 `config/admin.php` 配置多个目录，页面通过目录名切换；路径由服务端配置，不接受请求传入任意路径：

```php
'log_viewer' => [
    'enabled' => true,
    'default' => 'default',
    'directories' => [
        'default' => storage_path('logs'),
        'production' => storage_path('logs/production'),
        'staging' => storage_path('logs/staging'),
    ],
    'max_bytes' => 1024 * 1024,
],
```

支持标准 Laravel/Monolog 文本格式 `[时间] channel.LEVEL: 内容`，默认每页 20 条，较新日志在前。单次读取默认最多 1 MiB（配置上限 4 MiB），超大单条日志可能只显示部分内容。实时查看每 2 秒轮询，离开页面时停止，检测到文件缩短时从头读取。

## 升级指南

新增或收紧 Action 按钮权限时，同时按[权限规则升级](#权限规则升级)更新版本；升级前已打开的动作页面和分步表单需重新打开。

### 更新前端资源

更新包后，按需重新发布前端资源并清理缓存：

```bash
php artisan vendor:publish --tag=laravel-admin-assets --force
php artisan optimize:clear
```

资源升级会覆盖宿主同路径文件，修改前请保留宿主自身定制。`happy-admin-assets` 是同一资源树的附加标签。配置、语言和迁移仍使用各自发布标签。资源地址统一为 `/vendor/laravel-admin/...`。

### 从旧版导航与 API 迁移

2.0 已彻底移除 PJAX：

- 不再加载 `jquery-pjax`；
- 不再注册 `admin.pjax` 中间件；
- 不再支持 `X-PJAX`、`_pjax`、`#pjax-container`；
- 表单、筛选、分页和删除通过 AJAX 更新 `#admin-content`，地址栏和前进后退同步更新；
- iframe 标签页保留，顶层 AdminLTE 壳不刷新。

宿主升级时：

1. 将自定义 `$.pjax` / `pjax:*` 代码改用 `$.admin.navigate(url)`、`$.admin.reload()` 或 `$.admin.redirect(url)`；
2. 将 `#pjax-container` 选择器改为 `#admin-content`；
3. 删除 `Admin::disablePjax()`、`Widgets\Form::disablePjax()` 调用；
4. 表单不再需要 `pjax-container` 属性；
5. 页面需要清理定时器时监听 `admin:before-navigate`，需要在更新后运行时监听 `admin:navigated`。

2.0 同时移除了旧版废弃 API：

- `Admin::grid()`、`Admin::form()`、`Admin::show()`、`Admin::content()`、`Admin::registerAuthRoutes()`：直接使用 `new Grid(...)`、`new Form(...)`、`new Show(...)`、`Content` 注入控制器，或 `Admin::routes()`；
- `_handle_selectable_` / `_handle_renderable_` 只接受 `Grid\Selectable` 子类 / 实现 `Illuminate\Contracts\Support\Renderable` 的类，并校验页面签发的组件凭据；
- 表单 `Display` 字段默认转义 HTML，需输出富文本时显式调用 `->unescape()`；
- 菜单树的拖拽排序通过向 `admin/auth/menu` POST `_order` JSON 完成，不再依赖旧模型保存钩子；
- `admin:make`、`admin:export-seed` 不再需要 `doctrine/dbal`，表结构读取使用 Laravel 原生 Schema。

### 从独立日志扩展迁移

从独立日志查看器扩展升级时，移除旧扩展及手工注册的 `LogViewerServiceProvider`。如果宿主曾手动配置 `Encore\\Admin\\LogViewer\\` 自动加载映射，也需移除，避免覆盖本包中的类；自定义日志目录统一通过上述配置设置。

原路由名 `log-viewer-index`、`log-viewer-file`、`log-viewer-tail` 保留；删除入口 `log-viewer-delete` 和 `log-viewer-deleteall` 改为 **DELETE** 请求，页面表单已包含 CSRF Token 和方法字段。旧的独立 `laravel-admin-ext/log-viewer` 扩展不能与本包同时安装。

## 常见问题

### 控制台 unload 提示

如果控制台出现 `[Violation] Permissions policy violation: unload is not allowed in this document.`，可检查当前 frame 中注册 `unload` 事件的第三方脚本、编辑器或统计组件。

后台响应通过 `admin.permissions_policy` 配置 Permissions Policy，默认包含 `unload=self`，标签页 iframe 声明 `allow="unload"`。如需不发送该策略，将 `admin.permissions_policy` 设为 `[]`；最终行为还取决于浏览器及上层页面的策略。

本包自身不注册 `unload`。定位第三方注册位置时，可在相关脚本运行前临时加入下面的诊断代码，根据调用栈排查；自定义代码可按场景改用 `pagehide` 或 `visibilitychange`：

```js
EventTarget.prototype.addEventListener = (raw => function (type, listener, options) {
  if (type === 'unload') console.warn('[unload]', new Error().stack);
  return raw.apply(this, arguments);
})(EventTarget.prototype.addEventListener);
```

## 开发与分发

图片、字体及其他前端资源均作为普通 Git 文件分发。发布前可使用 `source-manifest.json` 中的 SHA256 校验资源完整性。

```bash
composer install
composer validate --strict
composer test
npm ci --ignore-scripts
npm test
composer archive --format=zip --dir=build --file=happyqu-laravel-admin-2.0.0
```

开发时，Node.js 用于运行前端回归测试，安装本包和发布前端资源不需要 Node.js 或 npm 构建。

CI 配置覆盖以下 Laravel 约束与 PHP 组合；实际安装的 Laravel 版本由 Composer 解析：

| Laravel 约束 | PHP |
| --- | --- |
| `^10.0` | 8.1、8.3 |
| `^11.0` | 8.2 |
| `^12.0` | 8.3 |
| `^13.0` | 8.3、8.4 |

保留原代码与前端组件的版权和许可证，见 [第三方来源](THIRD_PARTY_NOTICES.md)。
