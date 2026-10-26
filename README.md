# Laravel Admin

`happyqu/laravel-admin` 是一个 Laravel 后台 Composer 包，内置 iframe 标签页、通用全局菜单和定制前端。

- 支持 Laravel 10.0–10.50、11、12、13
- PHP 8.1–8.4
- 零额外 Composer 包，只依赖 `laravel/framework` 和 PHP 扩展
- 不再使用 PJAX；iframe 内部使用整页导航

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
- 表单、筛选、分页和删除使用普通整页导航；
- iframe 标签页保留，顶层 AdminLTE 壳不刷新。

宿主升级时：

1. 删除自定义 `$.pjax` / `pjax:*` 代码；
2. 将 `#pjax-container` 选择器改为 `#admin-content`；
3. 删除 `Admin::disablePjax()`、`Widgets\Form::disablePjax()` 调用；
4. 表单不再需要 `pjax-container` 属性；
5. iframe 保存后的刷新使用 `postMessage` 通知父级，不要直接操作父级 DOM。

2.0 同时移除了旧版废弃 API：

- `Admin::grid()`、`Admin::form()`、`Admin::show()`、`Admin::content()`、`Admin::registerAuthRoutes()`：直接使用 `new Grid(...)`、`new Form(...)`、`new Show(...)`、`Content` 注入控制器，或 `Admin::routes()`；
- `_handle_selectable_` / `_handle_renderable_` 只接受实现 `Grid\Selectable` / `Grid\Renderable` 的类，其余返回 404；
- 表单 `Display` 字段默认转义 HTML，需输出富文本时显式调用 `->unescape()`；
- 菜单树的拖拽排序通过向 `admin/auth/menu` POST `_order` JSON 完成，不再依赖旧模型保存钩子；
- `admin:make`、`admin:export` 不再需要 `doctrine/dbal`，表结构读取使用 Laravel 原生 Schema。

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

AJAX 请求自动显示进度条，超过 180 毫秒时显示加载提示，并发请求全部结束后关闭。后台轮询等静默请求可在 `$.ajax` 选项中设置 `adminLoading: false`，其他错误处理保持正常。

升级时按需发布资源：

```bash
php artisan vendor:publish --tag=laravel-admin-assets --force
php artisan optimize:clear
```

资源升级会覆盖宿主同路径文件，修改前请保留宿主自身定制。`happy-admin-assets` 是同一资源树的附加标签。配置、语言和迁移仍使用各自发布标签。资源地址统一为 `/vendor/laravel-admin/...`。

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
composer archive --format=zip --dir=build --file=happyqu-laravel-admin-2.0.0
```

兼容矩阵建议至少覆盖：

- Laravel 10.0 + PHP 8.1
- Laravel 10.50 + PHP 8.3
- Laravel 11 + PHP 8.2
- Laravel 12 + PHP 8.3
- Laravel 13 + PHP 8.3/8.4

保留原代码与前端组件的版权和许可证，见 [第三方来源](THIRD_PARTY_NOTICES.md)。
