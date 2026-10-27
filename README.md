# Laravel Admin

`happyqu/laravel-admin` 是一个 Laravel 后台 Composer 包，内置 iframe 标签页、通用全局菜单和定制前端。

- 支持 Laravel 10.0–10.50、11、12、13
- PHP 8.1–8.4
- 零额外 Composer 包，只依赖 `laravel/framework` 和 PHP 扩展
- 不再使用 PJAX；表单、筛选和分页通过 AJAX 更新内容区

Iframe Tabs 已并入核心代码，由 `Encore\Admin\AdminServiceProvider` 注册，使用 `Encore\Admin\IframeTabs` 命名空间。前端、视图和配置均属于本包，无需安装额外扩展。

## 环境要求

| 项目 | 要求 |
|---|---|
| PHP | 8.1+（Laravel 13 需要 8.3+） |
| Laravel | `^10.0`、`^11.0`、`^12.0`、`^13.0` |
| 扩展 | `ext-fileinfo`、`ext-dom` |

本包没有额外的 Composer 运行时依赖。图片处理、Orderable Grid、Excel 导出和 Minify 功能分别使用 `intervention/image`、`spatie/eloquent-sortable`、`maatwebsite/excel`、`matthiasmullie/minify`，只在需要时安装。

## 安装

```bash
composer require happyqu/laravel-admin:^2.0
```

本包支持 Composer 自动发现。正常情况下无需手工注册 Provider 或 Alias。

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

## 初始化

数据库配置完成后执行：

```bash
php artisan vendor:publish --tag=laravel-admin-config
php artisan vendor:publish --tag=laravel-admin-lang
php artisan vendor:publish --tag=laravel-admin-migrations
php artisan vendor:publish --tag=laravel-admin-assets
php artisan admin:install
```

`admin:install` 执行迁移、初始化后台目录和基础数据，并自动初始化内置标签页的首页菜单与权限。默认地址 `/admin`，初始账号、密码均为 `admin`，首次登录后请修改密码。

重复执行不会重复创建标签页权限；已有用户数据时不会重新初始化业务数据。

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

## 2.0 导航变化

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

后台内容区的同源链接与表单默认使用 AJAX；文件上传使用 `FormData`，保留服务器重定向、表单校验和 CSRF 校验。导出下载、外部链接及新窗口链接保持浏览器原有行为。个别链接或表单需要整页请求时，添加 `data-admin-ajax="false"`。

2.0 同时移除了旧版废弃 API：

- `Admin::grid()`、`Admin::form()`、`Admin::show()`、`Admin::content()`、`Admin::registerAuthRoutes()`：直接使用 `new Grid(...)`、`new Form(...)`、`new Show(...)`、`Content` 注入控制器，或 `Admin::routes()`；
- `_handle_selectable_` / `_handle_renderable_` 只接受 `Grid\Selectable` 子类 / 实现 `Illuminate\Contracts\Support\Renderable` 的类，并校验页面签发的组件凭据；
- 表单 `Display` 字段默认转义 HTML，需输出富文本时显式调用 `->unescape()`；
- 菜单树的拖拽排序通过向 `admin/auth/menu` POST `_order` JSON 完成，不再依赖旧模型保存钩子；
- `admin:make`、`admin:export` 不再需要 `doctrine/dbal`，表结构读取使用 Laravel 原生 Schema。

动态选择器、异步展开和弹窗的 URL 由内置组件自动附带 `_component_token_`，凭据绑定当前会话用户、来源页面、组件类和参数；加载时重新检查来源页面权限。自定义代码若直接拼接这两个接口的 URL，需改用 `Encore\Admin\Security\ComponentSubmission::url($routeName, $routeParameters, $kind, $class, $payload)`：`$kind` 为 `selectable` 时，`$payload` 是构造参数列表；为 `renderable` 时，是包含当前记录主键的单元素数组。缺少或过期的凭据返回 419，篡改参数或失去来源权限返回 403。

内置用户编辑和个人设置页面不再回填密码哈希；编辑时密码留空保留原密码，创建用户仍要求密码及确认。布局列宽支持 1–12、比例值和响应式映射；无效列宽会抛出 `InvalidArgumentException`。

Widget 表单的 `form()` 在每个实例中只构建一次，重复渲染和校验不会重复添加字段；`fill([])` 会清空已填数据，PATCH/DELETE 等方法通过 POST 与 `_method` 提交。表单渲染也会检查 `authorize()`，提交时递归过滤嵌套编辑器中未声明或只读的字段。分步表单的流程绑定用户、后台配置和页面查询参数，升级前已创建的分步流程需重新开始。

动作凭据 `_action_token` 使用精简 JSON 参数与 HMAC 签名，绑定当前用户、登录会话和后台环境，不依赖 session 动作映射，也没有动作自身的固定过期时间。仅携带动作类、模型定位、回调参数、来源页面、允许的记录范围和必要版本信息；每次执行重新查询记录并检查权限。凭据内容可读取，不应放入密码、密钥、查询结果或完整模型。退出重登、CSRF token 轮换或升级前旧格式凭据需要刷新来源页面；动作错误为独立业务错误，不使用 CSRF 的 419。

表单动作打开时调用一次 `POST _load_action_`，提交时直接调用 `_handle_action_`；普通确认框和批量确认直接提交，不再预加载、定时过期或续签。表单加载后绑定选择范围和用户看到的记录版本，冲突与失败保留输入，写请求不会自动重发。原有锁与完成标记继续使用原缓存，不新增数据库或文件存储，也不承诺缓存清空后的永久防重。

动作固定属性从类定义重建；动态回调继续使用 `parameters()`。少量非敏感运行时配置通过 `protected $contextProperties = ['headerList'];` 显式声明，默认不收集任何对象状态，显式配置上限 4 KiB、完整参数上限 64 KiB。RowAction 的 row、工具栏通过 `contextModel()` 绑定的模型以及批量记录均由服务端重新查询，模型关联按需加载，不写进凭据。

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

从独立日志查看器扩展升级时，移除旧扩展及手工注册的 `LogViewerServiceProvider`。如果宿主曾手动配置 `Encore\\Admin\\LogViewer\\` 自动加载映射，也需移除，避免覆盖本包中的类；自定义日志目录统一通过上述配置设置。

原路由名 `log-viewer-index`、`log-viewer-file`、`log-viewer-tail` 保留；删除入口 `log-viewer-delete` 和 `log-viewer-deleteall` 改为 **DELETE** 请求，页面表单已包含 CSRF Token 和方法字段。旧的独立 `laravel-admin-ext/log-viewer` 扩展不能与本包同时安装。

## 前端资源

所有资源统一维护在 `resources/assets`，发布到 `public/vendor/laravel-admin`。内置标签页资源位于该目录的 `iframe-tabs` 子目录。JS、CSS、图片、字体均随包分发，无需 npm 构建。

AJAX 请求超过 180 毫秒时显示居中的“加载中”提示，并发请求全部结束后关闭；不再显示顶部进度条。后台轮询等静默请求可在 `$.ajax` 选项中设置 `adminLoading: false`，其他错误处理保持正常。表单类 Action 在加载完成后才弹出表单，加载期间只显示“加载中”提示。

表单提交立即显示加载提示，并将提交按钮切换为“提交中”，避免重复提交。请求失败或校验未通过时保留表单内容并恢复按钮；普通页面切换采用 AJAX，浏览器前进后退会重新加载对应 URL 的内容并恢复滚动位置。

升级时按需发布资源：

```bash
php artisan vendor:publish --tag=laravel-admin-assets --force
php artisan optimize:clear
```

资源升级会覆盖宿主同路径文件，修改前请保留宿主自身定制。`happy-admin-assets` 是同一资源树的附加标签。配置、语言和迁移仍使用各自发布标签。资源地址统一为 `/vendor/laravel-admin/...`。

### 控制台 unload 违规提示

Chrome 115+ 默认禁用已废弃的 `unload` 事件，页面（含其加载的第三方脚本、编辑器、统计组件）每注册一次 `unload` 处理器就会输出 `[Violation] Permissions policy violation: unload is not allowed in this document.`。后台响应统一附带 `Permissions-Policy: unload=self`（配置项 `admin.permissions_policy`），标签页 iframe 同时声明 `allow="unload"`，因此控制台不再出现该提示。

该日志由浏览器直接输出，脚本无法捕获；如需保留 Chrome 默认行为，将 `admin.permissions_policy` 设为 `[]`。本包自身不注册 `unload`，若提示仍然出现，在对应 frame 的控制台执行下面的片段并刷新页面，即可从调用栈定位来源（必要时改用 `pagehide` / `visibilitychange`）：

```js
EventTarget.prototype.addEventListener = (raw => function (type, listener, options) {
  if (type === 'unload') console.warn('[unload]', new Error().stack);
  return raw.apply(this, arguments);
})(EventTarget.prototype.addEventListener);
```

## 上传安全

内置文件、图片、多文件和多图片字段均执行服务器类型白名单、实际 MIME、文件名及目标路径检查，包含头像接口；保存前再次校验，防止绕过表单 validator。图片允许 JPEG、PNG、GIF、WebP、BMP，普通附件白名单位于 `admin.upload.allowed_extensions`。

PHP、PHTML、PHAR、HTML、SVG 等危险上传类型被禁止，不能通过白名单开启。历史上传文件和业务自建上传接口需要自行检查。

默认 `admin` 磁盘为 `public/uploads`；宿主自定义同名磁盘优先。图片裁切、缩略图等功能按需安装 `intervention/image:^2.7`。

## 开发和分发

图片、字体及其他前端资源均作为普通 Git 文件分发。发布前可使用 `source-manifest.json` 中的 SHA256 校验资源完整性。

```bash
composer install
composer validate --strict
composer test
npm ci --ignore-scripts
npm test
composer archive --format=zip --dir=build --file=happyqu-laravel-admin-2.0.0
```

Node.js 仅用于运行前端回归测试，安装本包和发布前端资源不需要 Node.js 或 npm 构建。

兼容矩阵建议至少覆盖：

- Laravel 10.0 + PHP 8.1
- Laravel 10.50 + PHP 8.3
- Laravel 11 + PHP 8.2
- Laravel 12 + PHP 8.3
- Laravel 13 + PHP 8.3/8.4

保留原代码与前端组件的版权和许可证，见 [第三方来源](THIRD_PARTY_NOTICES.md)。
