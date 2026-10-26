# Laravel Admin

`happyqu/laravel-admin` 是一个 Laravel 后台管理包，内置 iframe 标签页、全局菜单和日志查看器。前端基于 AdminLTE 4、Bootstrap 5 和 jQuery，资源随包提供，无需 npm 构建。

## 环境要求

- PHP 8.1+，具体版本需满足所用 Laravel 的要求
- Laravel 10 / 11 / 12 / 13
- PHP 扩展：`fileinfo`、`dom`

## 安装

```bash
composer require happyqu/laravel-admin:^3.0
```

支持 Laravel 自动发现，无需手工注册服务提供者。

配置好数据库后执行：

```bash
php artisan vendor:publish --tag=laravel-admin-config
php artisan vendor:publish --tag=laravel-admin-lang
php artisan vendor:publish --tag=laravel-admin-migrations
php artisan vendor:publish --tag=laravel-admin-assets
php artisan admin:install
```

访问 `/admin`，初始账号和密码均为 `admin`，首次登录后请修改密码。

## 配置

配置文件为 `config/admin.php`。标签页默认开启，可通过 `iframe_tabs.enable` 关闭；全局菜单和主题可在 `.env` 中设置：

```dotenv
ADMIN_GLOBAL_NAVIGATION=true
ADMIN_THEME=default
ADMIN_COLOR_MODE=light
```

默认界面保留 2.0 的紧凑操作布局，结合 Ant Design Pro 的设计语言，统一表格、表单、菜单、分页和弹窗样式；支持浅色与深色模式。视觉层位于 `resources/assets/laravel-admin/pro.css`，底层仍使用 AdminLTE 4 / Bootstrap 5。

日志查看器默认位于 `/admin/logs`，读取 `storage/logs`。图片处理、Excel 导出等可选功能的依赖见 [composer.json](composer.json) 中的 `suggest`。

## 升级

3.0 包含前端不兼容变更，旧项目请先阅读 [升级指南](UPGRADE-3.0.md) 和 [更新记录](CHANGELOG.md)。

更新资源：

```bash
php artisan vendor:publish --tag=laravel-admin-assets --force
php artisan optimize:clear
```

发布资源会覆盖同路径文件，请先备份自定义修改。

## 许可证

[MIT](LICENSE)。第三方组件版权见 [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)。
