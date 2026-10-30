# Laravel Admin

`happyqu/laravel-admin` 是一个 Laravel 后台 Composer 包，提供表格、表单、详情页、iframe 标签页、全局菜单和 AJAX 内容导航。保留 `Encore\Admin` 命名空间，支持 Composer 自动发现；前端资源随包分发，安装时无需 npm 构建。

本文中的业务类、角色、权限和字段名称均为通用示例，使用时请替换为宿主项目自己的定义。

## 目录

- [环境要求](#环境要求)
- [安装与初始化](#安装与初始化)
- [导航与资源](#导航与资源)
- [表单组件](#表单组件)
- [Action 与工具权限](#action-与工具权限)
- [文件与图片](#文件与图片)
- [日志查看器](#日志查看器)
- [升级与迁移](#升级与迁移)
- [常见问题](#常见问题)
- [开发与分发](#开发与分发)

## 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | `^8.1`，同时满足所选 Laravel 版本的要求 |
| Laravel | `^10.0`、`^11.0`、`^12.0`、`^13.0` |
| PHP 扩展 | `ext-fileinfo`、`ext-dom` |

以下依赖按功能安装：

| 功能 | 可选依赖 |
| --- | --- |
| 服务端图片裁切、缩略图 | `intervention/image:^2.7` |
| Orderable Grid | `spatie/eloquent-sortable` |
| Excel 导出 | `maatwebsite/excel:^3.1` |
| 资源压缩 | `matthiasmullie/minify:^1.3` |

## 安装与初始化

配置宿主项目的数据库后执行：

```bash
composer require happyqu/laravel-admin:^2.0
php artisan vendor:publish --tag=laravel-admin-config
php artisan vendor:publish --tag=laravel-admin-lang
php artisan vendor:publish --tag=laravel-admin-migrations
php artisan vendor:publish --tag=laravel-admin-assets
php artisan admin:install
```

`admin:install` 会运行迁移、初始化后台目录和基础数据，并初始化内置菜单与权限。默认访问地址为 `/admin`。初始化账号定义见[基础数据初始化代码](src/Auth/Database/AdminTablesSeeder.php)，对外开放后台前请更新初始密码。

后台用户表已有数据时，不重复执行基础数据初始化；后台目录已存在时，不覆盖该目录。

### 手工注册

正常情况下由 Composer 自动发现注册。宿主关闭自动发现时，需要先注册 Provider，再执行初始化命令。

Laravel 10 在 `config/app.php` 的 `providers` 中添加：

```php
Encore\Admin\AdminServiceProvider::class,
```

Laravel 11/12/13 在 `bootstrap/providers.php` 中添加：

```php
return [
    App\Providers\AppServiceProvider::class,
    Encore\Admin\AdminServiceProvider::class,
];
```

需要使用 `Admin` 别名时，在宿主的别名配置中注册：

```php
'Admin' => Encore\Admin\Facades\Admin::class,
```

## 导航与资源

### 标签页与菜单

标签页默认开启，在宿主 `config/admin.php` 中配置：

```php
'iframe_tabs' => [
    'enable' => true,
    'home_action' => App\Admin\Controllers\HomeController::class.'@index',
    'home_title' => '首页',
],
```

未自定义首页时使用包内 dashboard。默认 `/admin` 为标签容器，`/admin/dashboard` 为首页内容。关闭 `iframe_tabs.enable` 后，安装生成的路由使用标准 `HomeController@index`。`admin.route.file` 可指定后台目录中的路由文件，默认为 `routes.php`。

默认使用经典侧栏；在 `.env` 中设置 `ADMIN_GLOBAL_NAVIGATION=true` 可启用全局菜单，支持搜索、分类、收藏和最近访问，状态按管理员隔离。

### AJAX 导航

内容区的同源链接与表单默认使用 AJAX。文件通过 `FormData` 提交，保留服务器重定向、表单校验和 CSRF 校验；导出、外部链接及新窗口链接保持浏览器原有行为。

- 使用 `data-admin-ajax="false"` 让个别链接或表单走整页请求。
- 请求超过 180 毫秒时显示加载提示，并发请求全部结束后关闭。
- 静默请求可设置 `$.ajax({ adminLoading: false })`，错误处理仍保留。
- 提交时显示加载状态并防止重复提交；失败或校验未通过时保留输入。
- 浏览器前进、后退会重新加载对应 URL，并恢复滚动位置。

前端资源统一位于 `resources/assets`，发布到 `public/vendor/laravel-admin`。更新包后需要[重新发布资源](#更新资源)。

## 表单组件

### 常用字段

```php
$form->text('title', '标题')->rules('required');
$form->textarea('description', '说明');
$form->number('quantity', '数量')->min(0)->max(100);
$form->select('status', '状态')->options([1 => '启用', 2 => '停用']);
$form->multipleSelect('labels', '标签')->options([1 => '标签一', 2 => '标签二']);
```

选择类字段还包括 `checkbox`、`radio`、`radioButton`、`radioCard`、`checkboxButton`、`checkboxCard` 和 `listbox`。选项键是提交值，标签用于显示；数值 `0` 是有效选择。

`readonly()` 表示只读，`disable()` 表示禁用。内置表单在服务端过滤这些字段的客户端修改；自定义提交接口需要自行执行同等校验。数值字段允许清空，完成编辑或提交时处理上下限，业务规则仍应通过 `rules()` 声明。

### 条件联动

`select`、`multipleSelect`、`belongsTo`、`belongsToMany` 及上述单选、复选变体支持 `when()`：

```php
$form->radio('mode', '模式')->options([1 => '简要', 2 => '详细'])
    ->when(2, function ($form) {
        $form->textarea('details', '详细说明')->rules('required');
    });
```

分支预先渲染；隐藏时保留页面输入，但不参与浏览器校验或提交。服务端根据提交的控制值判断分支，仅校验和处理命中分支的字段；隐藏分支不会自动清空数据库旧值。嵌套分支须同时满足全部上层条件。

只读或禁用的控制字段使用服务端已有值。PATCH 省略可编辑控制字段时使用记录已有值，完整提交省略字段或显式清空多选时则不恢复旧选择。多个分支共用的字段建议定义在 `when()` 外。

### 日期与时间

日期组件使用随包提供的 Flatpickr，默认不自动填入当前时间；空时间字段默认从 `00:00:00` 开始，已有值保持原样。

```php
$form->date('date');                          // YYYY-MM-DD
$form->datetime('scheduled_at');              // YYYY-MM-DD HH:mm:ss
$form->time('time');                          // HH:mm:ss
$form->dateRange('start_date', 'end_date');
$form->datetimeRange('started_at', 'ended_at');
$form->timeRange('start_time', 'end_time');
$form->DateMultiple('dates')->format('DD/MM/YYYY');
```

范围组件提交两个独立字段。手工输入会检查日期格式、配置边界及范围顺序；无效输入保留在页面中，并阻止正常表单提交。服务端业务校验仍需通过 `rules()` 配置。

- `format()` 使用兼容格式，例如 `YYYY-MM-DD`、`HH:mm:ss`，支持方括号字面量。
- 原生 Flatpickr 格式通过 `options(['dateFormat' => 'Y-m-d'])` 指定。
- 语言默认取 `app.locale`，支持 `zh-CN`、`zh_TW`、`en_US` 等别名；未知语言回退英文。
- `defaultHour`、`defaultMinute`、`defaultSeconds` 可配置初始时间。

| 兼容选项 | 行为 |
| --- | --- |
| `format`、`locale`、`date`、`defaultDate`、`inline` | 保留对应用途 |
| `minDate`、`maxDate` | 日期边界；范围字段自动联动 |
| `stepping` | 转换为 `minuteIncrement` |
| `disabledDates`、`enabledDates`、`daysOfWeekDisabled` | 日期限制 |
| `calendarWeeks` | 转换为 `weekNumbers` |
| `widgetParent` | 转换为 `appendTo` |
| `showClear`、`showTodayButton`、`showClose` | 显示操作按钮 |

旧组件的 `allowInputToggle`、`useCurrent`、`useStrict`、`sideBySide`、`icons`、`tooltips`、`widgetPositioning` 不再控制旧界面。`timeZone`、`disabledTimeIntervals`、`enabledHours`、`disabledHours`、`viewMode`、`toolbarPlacement`、`extraFormats`、`parseInputDate` 不受支持，使用时会报错，需迁移到对应配置或业务校验。

过渡接口保留 `.datetimepicker(options)`、`.data('DateTimePicker')` 的 `date()`、`minDate()`、`maxDate()`、`show()`、`hide()`、`clear()`、`destroy()`，以及 `dp.change`、`dp.show`、`dp.hide`。事件日期对象支持 `format()`、`toDate()`、`clone()`、`valueOf()`、`isValid()`，不是 Moment 对象。直接依赖 Moment 或 combodate 的自定义代码需迁移。

### Widget 与动态组件

Widget 表单的 `form()` 每个实例只构建一次，`fill([])` 清空已填数据。PATCH/DELETE 等方法通过 POST 与 `_method` 提交；渲染时检查 `authorize()`，提交时递归过滤未声明或只读字段。升级前创建的分步流程需重新开始。

内置选择器和异步展开组件会自动生成绑定当前用户、来源页面、组件类及参数的凭据。自定义代码直接生成组件接口 URL 时，应使用 `Encore\Admin\Security\ComponentSubmission::url()`，不要手工拼接 `_handle_selectable_` 或 `_handle_renderable_` 地址。

## Action 与工具权限

### 选择类型

以下类型均位于 `Encore\Admin` 命名空间：

| 类型 | 场景 | 授权范围 |
| --- | --- | --- |
| `Actions\RowAction` | 单条记录操作 | 展示、表单加载和执行时检查记录 |
| `Actions\BatchAction` | 批量操作 | 展示时检查通用条件，加载和执行时检查选中记录 |
| `Actions\Action` / `Actions\GridAction` | 通用按钮、工具栏操作 | 加载和执行通过签名动作流程授权 |
| `Grid\Tools\AbstractTool` / `Widgets\Tool` | 链接、下载、已有工具包装 | 容器渲染前授权，独立接口另行授权 |

### 权限配置

| 方法示例 | 含义 |
| --- | --- |
| `allowRoles(['editor', 'manager'])` | 至少属于一个角色，使用角色 slug |
| `denyRoles(['viewer'])` | 命中任一角色即拒绝 |
| `requirePermissions(['records.view', 'records.update'])` | 必须拥有全部权限 |
| `requireAnyPermission(['records.export', 'reports.export'])` | 至少拥有一个权限 |
| `ability('update')` | 以当前后台用户检查记录的 Gate / Policy |
| `ability('create', Record::class)` | 检查模型级别的 Policy |
| `deniedAsDisabled('无操作权限')` | 无权时显示禁用提示，默认隐藏 |

不同条件取交集，显式拒绝优先；重复调用同一配置方法会替换该项。角色、权限列表不能为空，只接受 slug，不接受 ID、闭包或对象。角色限制、显式拒绝及 Policy 不额外放行管理员。

下面的 `ExampleRowAction`、`ExampleBatchAction`、`Record` 和 `RecordPolicy` 均为宿主自定义的示例类：

```php
$grid->actions(function ($actions) {
    $actions->add(
        (new ExampleRowAction())
            ->requirePermissions(['records.update'])
            ->ability('update')
    );
});

$grid->batchActions(function ($batch) {
    $batch->add((new ExampleBatchAction())->ability('update'));
});

$grid->column('status')->action((new ExampleRowAction())->ability('update'));

\Illuminate\Support\Facades\Gate::policy(Record::class, RecordPolicy::class);
```

批量中任一记录未通过授权，会拒绝整次操作。Action 的 `authorize($user, $model)` 和模型的 `actionAuthorize($user, $actionClass)` 继续生效；批量 Action 的 `authorize()` 接收选中集合。

工具可通过 `Widgets\Tool` 包装现有 Renderable、Htmlable 或 HTML：

```php
use Encore\Admin\Widgets\Tool;

$form->tools(function ($tools) {
    $tools->append(
        (new Tool(new ExampleExportTool()))->requirePermissions(['records.export'])
    );
});
```

工具容器在授权后才执行渲染。`forModel($model)` 可绑定记录级 Policy；容器外使用 `renderAuthorized()` 或 `(string) $tool`。自定义覆盖 Action 的 `render()` 时，先调用 `passesAuthorizationForRendering()`，拒绝时返回 `renderAuthorizationDenied()`。

跳转链接、独立下载和自建接口仍需自行授权，按钮可见性不能替代接口授权。旧的 `Grid\Tools\BatchAction` 和原始 HTML 按钮不属于上述 API。

### 运行时参数

自定义 Action 中，通过 setter 设置的少量标量、数组属性会自动跨请求恢复，无需额外构造方法或属性白名单。例如在自定义类中：

```php
private $resourceId;

public function setResourceId($value)
{
    $this->resourceId = $value;
    return $this;
}
```

`form()` 和 `handle()` 可直接读取 `$this->resourceId`。自动保存支持自定义父子类中的 private/protected/public 属性；静态、readonly、未初始化属性、对象和资源不自动保存。模型应通过记录上下文绑定，并在服务端重新查询。

保存时与容器新建实例的构造完成状态比较，仅保留发生变化的值；构造时计算的权限在新请求中重新计算，setter 设置的 `null`、`false`、`0` 仍可恢复。因此构造方法只做初始化，业务写操作放在 `handle()`；自动上下文 Action 不应注册为共享单例。

已有显式声明继续兼容，也会参与加密：

```php
protected $contextProperties = ['resourceId'];
```

显式属性始终保存，适合明确要求恢复的配置；不要将需要逐次重新计算的授权结果列入其中。属性状态上限为 4 KiB，完整动作凭据上限为 64 KiB。

### 加载、提交与凭据

- 打开表单时请求 `_load_action_`，提交时请求 `_handle_action_`；普通确认操作直接提交。
- 运行时属性加密存入 `_action_token`，动作凭据签名绑定当前用户和登录会话。
- 其他参数和元数据可读取，`parameters()` 中不要放入密码、密钥或完整模型。
- 参数传递不依赖 Redis 或服务端 Action 映射；执行锁与防重复提交仍使用宿主 Laravel Cache 配置。
- 加载和执行时重新检查权限及记录版本；失败时保留输入，写请求不会自动重发。
- 退出重登、CSRF token 轮换或不兼容的旧凭据需要重新打开来源页面。

新增或收紧按钮配置时，应递增宿主 `config/admin.php` 中的 `actions.authorization_version`，使旧页面凭据失效。撤销用户角色、权限或修改 Policy 判定，会在后续请求重新检查。

```php
'actions' => [
    'authorization_version' => '2',
],
```

## 文件与图片

文件和图片字段执行服务端扩展名、实际 MIME、文件名及目标路径检查，包含头像接口；保存前再次校验。图片支持 JPEG、PNG、GIF、WebP、BMP；附件白名单由 `admin.upload.allowed_extensions` 配置。PHP、PHTML、PHAR、HTML、SVG 等类型不能通过白名单开启。

默认 `admin` 磁盘指向 `public/uploads`，宿主自定义同名磁盘优先。历史文件及自建上传接口需自行检查。

### 浏览器压缩与裁剪

```php
$form->image('photo', '图片')->compress();
$form->multipleImage('photos', '相册')->compress(0.75, 1600, 1200);
$form->image('cover', '封面')->cropper();
$form->image('avatar', '头像')->cropper(400, 400)->compress(0.85, 400, 400);
$form->image('banner', '横幅')->cropper(null, null, ['aspectRatio' => 16 / 9]);
```

默认压缩质量为 0.8，最长宽高为 1920px，保持比例且不放大；同时启用时先裁剪再压缩。此功能随包提供，不依赖服务端图片扩展，仅处理新选择的本地文件。

处理期间阻止提交，取消裁剪会清空本次选择并保留已保存图片。JPEG、PNG、WebP 支持处理，PNG 保留透明通道；单独压缩未减小文件时保留原文件。GIF 压缩时原样上传，裁剪时提示更换格式。Canvas 重编码会移除原有 EXIF 元数据，前端处理不能替代服务端验证。

需要支持 Canvas、`toBlob`、`File` 和可写 `DataTransfer` 的现代浏览器。`crop()`、`resize()`、`thumbnail()` 等服务端图片操作仍需可选依赖。

## 日志查看器

内置日志查看器，默认入口 `/admin/logs`，跟随后台路由前缀；支持文件切换、分页、异常详情、实时追加和删除。

新安装自动创建菜单与 `ext.log-viewer` 权限。已有项目执行一次：

```bash
php artisan admin:import log-viewer
```

重复执行不会重复添加菜单和权限。应用文件日志入口为 `logs`，操作日志入口为 `auth/logs`。默认读取 `storage/logs`，也可在宿主配置中声明多个目录：

```php
'log_viewer' => [
    'enabled' => true,
    'default' => 'default',
    'directories' => [
        'default' => storage_path('logs'),
        'archive' => storage_path('logs/archive'),
    ],
    'max_bytes' => 1024 * 1024,
],
```

路径由服务端配置，不接受请求传入任意路径。支持标准 Laravel/Monolog 文本日志，默认每页 20 条；单次读取默认最多 1 MiB，上限 4 MiB，超大单条日志可能只显示部分内容。实时查看每 2 秒轮询，离开页面时停止。

## 升级与迁移

### 更新资源

```bash
php artisan vendor:publish --tag=laravel-admin-assets --force
php artisan optimize:clear
```

发布会覆盖同路径文件，请先保留宿主自身定制。配置、语言和迁移使用各自发布标签；`happy-admin-assets` 是同一资源树的附加标签。

升级后重新打开已有 Action 页面和分步表单。此前按属性声明默认值生成的自动 Action 快照需刷新来源页面，旧的显式属性凭据仍兼容。收紧按钮权限配置时，同时更新 `actions.authorization_version`。

### 从旧版导航迁移

2.0 内容导航使用 AJAX，iframe 标签页保留，顶层 AdminLTE 壳不刷新：

| 原有代码 | 迁移方式 |
| --- | --- |
| `$.pjax`、`pjax:*` | `$.admin.navigate(url)`、`$.admin.reload()`、`$.admin.redirect(url)` |
| `#pjax-container` | `#admin-content` |
| `admin.pjax`、`X-PJAX`、`_pjax` | 移除相关依赖 |
| `Admin::disablePjax()`、`Widgets\Form::disablePjax()` | 删除调用 |
| 表单 `pjax-container` 属性 | 删除属性 |
| 页面清理与重新初始化 | 监听 `admin:before-navigate`、`admin:navigated` |

### 其他兼容事项

- 旧 `Admin::grid()`、`form()`、`show()`、`content()` 改为实例化对应类或使用 `Content` 注入；`registerAuthRoutes()` 改为 `Admin::routes()`。
- `Display` 字段默认转义 HTML，需要富文本时显式调用 `unescape()`。
- 菜单树排序通过向 `admin/auth/menu` POST `_order` JSON 完成。
- `admin:make`、`admin:export-seed` 使用 Laravel 原生 Schema，无需 `doctrine/dbal`。
- 从独立日志扩展迁移时，移除旧扩展、手工 Provider 及自动加载映射，避免覆盖包内实现。
- 日志读取路由名保持兼容；`log-viewer-delete`、`log-viewer-deleteall` 使用 DELETE 请求，需携带 CSRF token 和方法字段。

## 常见问题

### 浏览器提示 unload 策略限制

本包不注册 `unload`。出现 Permissions Policy 提示时，检查当前 iframe 中第三方脚本的事件注册；自定义逻辑可按场景使用 `pagehide` 或 `visibilitychange`。

后台策略由 `admin.permissions_policy` 配置，默认包含 `unload=self`；标签页 iframe 声明 `allow="unload"`。设置为 `[]` 可不发送该策略，最终行为还取决于浏览器及上层页面策略。

### 自定义 Action 的参数未恢复

确认属性定义在自定义 Action 类或其父类中，值为标量或数组，Action 可通过容器创建新实例。涉及模型、服务对象或超出状态大小限制的数据，应通过 ID 查询；已有显式属性配置也可继续使用。

## 开发与分发

```bash
composer install
composer validate --strict
composer test
npm ci --ignore-scripts
npm test
```

Node.js 仅用于开发与前端回归测试，安装包和发布现有资源无需 npm 构建。修改内置字段资源声明后，执行 `composer build-field-assets` 更新资源元数据；修改 Flatpickr 本地适配生成逻辑后，执行 `npm run build:datepicker`。

前端资源作为普通 Git 文件分发，可使用 `source-manifest.json` 中的 SHA256 校验完整性。制作 Composer 分发包：

```bash
composer archive --format=zip --dir=build --file=laravel-admin
```

本包采用 [MIT 许可证](LICENSE)，第三方代码与资源的来源和许可证见[第三方说明](THIRD_PARTY_NOTICES.md)。
