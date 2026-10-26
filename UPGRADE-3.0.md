# 升级到 3.0

3.0 是前端 breaking release：后台从 AdminLTE 2 / Bootstrap 3 迁移到 AdminLTE 4.9.1 / Bootstrap 5.3.8 / jQuery 3.7.1。**不提供** BS3 jQuery API 桥接或 AdminLTE 2 兼容 CSS，请按本文调整宿主自定义视图、样式和脚本。

## 1. 安装与资源

```bash
composer require happyqu/laravel-admin:^3.0
php artisan vendor:publish --tag=laravel-admin-config --force
php artisan vendor:publish --tag=laravel-admin-assets --force
php artisan optimize:clear
```

`resources/assets/AdminLTE` 旧资源树已删除；宿主不要继续引用 `/vendor/laravel-admin/AdminLTE/...`。

## 2. 配置

| 2.x | 3.0 |
|---|---|
| `admin.skin`（12 个 `skin-*`） | `admin.theme`：`default`（AdminLTE 官方默认）、`blue`、`green`、`purple`、`red`、`yellow`、`black`（可选顶栏配色） |
| 无 | `admin.color_mode`：`light`、`dark`、`auto` |
| `layout` 支持 `fixed`、`layout-boxed`、`layout-top-nav` | 只支持 `layout-fixed`、`sidebar-mini`、`sidebar-collapse`、`sidebar-expand-lg`、`fixed-header`、`fixed-footer` |
| `default_avatar` 指向 `AdminLTE/dist/img` | 指向 `adminlte4/img` |

`skin-blue-light` 等旧值需要在宿主配置和 `.env` 中改为新的 `ADMIN_THEME` / `ADMIN_COLOR_MODE`。

默认界面在 AdminLTE 4 / Bootstrap 5 上使用包内 `laravel-admin/pro.css`：保留 2.0 的紧凑列表与表单流程，视觉参考 Ant Design Pro，统一 14px 正文字号、蓝色主操作、轻量卡片及悬停、焦点、选中和禁用状态，不引入 React 或 Ant Design 组件依赖。支持浅色、深色和自动模式。已有项目请设置 `ADMIN_THEME=default`；默认 `layout` 为 `['layout-fixed', 'sidebar-expand-lg']`，侧栏初始展开。行操作菜单采用三点入口并向左展开。标题、描述和面包屑区域默认隐藏，可通过 `admin.show_content_header=true` 开启。旧版强制隐藏页脚、菜单图标，以及覆盖 Bootstrap 栅格和下拉菜单定位的样式已移除。

`iframe_tabs.bind_selecter` 中 `.box-header .pull-right .btn-success` 已改为 `.card-header .ms-auto .btn-success`。

## 3. 类名映射

| BS3 / AdminLTE 2 | BS5 / AdminLTE 4 |
|---|---|
| `.wrapper` | `.app-wrapper` |
| `.main-header` | `.app-header` |
| `.main-sidebar` | `.app-sidebar` |
| `.content-wrapper` | `.app-main` |
| `.content-header` | `.app-content-header` |
| `.content` | `.app-content` |
| `.main-footer` | `.app-footer` |
| `.sidebar-menu` | `.sidebar-menu`（结构改为 `.nav-item` / `.nav-link` / `.nav-treeview`） |
| `.treeview` / `.treeview-menu` | `.nav-item` / `.nav-treeview` |
| `.box` / `.box-header` / `.box-body` / `.box-footer` | `.card` / `.card-header` / `.card-body` / `.card-footer` |
| `.box-title` / `.box-tools` | `.card-title` / `.card-tools` |
| `.pull-left` / `.pull-right` | `.float-start` / `.float-end` |
| `.hidden-xs` | `.d-none .d-sm-block` |
| `.visible-lg-block` | `.d-none .d-lg-block` |
| `.btn-default` | `.btn-secondary` / `.btn-outline-secondary` |
| `.input-group-addon` | `.input-group-text` |
| `.input-group-btn` | 直接放入 `.input-group` 的按钮 |
| `.control-label` | `.form-label` |
| `.form-group` | `.admin-field mb-3` |
| `.has-error` | `.is-invalid` + `.invalid-feedback` |
| `.help-block` | `.form-text` / `.invalid-feedback` |
| `.label-*` | `.badge.text-bg-*` |
| `data-toggle` / `data-target` / `data-dismiss` | `data-bs-toggle` / `data-bs-target` / `data-bs-dismiss` |
| `data-widget="collapse"` / `remove` | `data-lte-toggle="card-collapse"` / `card-remove` |
| `data-widget="pushmenu"` | `data-lte-toggle="sidebar"` |
| `data-widget="treeview"` | `data-lte-toggle="treeview"` |

## 4. JavaScript

BS3 jQuery 插件 API 不再提供，改用 Bootstrap 5：

```js
// 2.x
$('#modal').modal('show');
$('[data-toggle="popover"]').popover();
$('#tabs a').tab('show');

// 3.0
bootstrap.Modal.getOrCreateInstance(document.getElementById('modal')).show();
new bootstrap.Popover(document.querySelector('[data-bs-toggle="popover"]'));
bootstrap.Tab.getOrCreateInstance(document.querySelector('#tabs a')).show();
```

本包内部提供了 `AdminUI.modal/popover/tooltip/tab/setButtonLoading`，只供包内代码使用，不是给宿主回填 `$.fn` 的桥接层。

- `.editable()` → Grid `editable()` 新行内编辑器；
- `.iCheck()` → 原生 checkbox/radio + `.form-check`；
- `.datetimepicker()` → Flatpickr 或原生 date/time 输入；
- `.bootstrapSwitch()` → BS5 `.form-switch`；
- `.bootstrapDualListbox()` → 包内 `AdminListbox`，`$form->listbox()` 保留双栏选择；
- `.colorpicker()` → Pickr；
- `.slimScroll()` → 原生滚动。

## 5. 已移除的插件

iCheck、X-Editable、Eonasdan datetimepicker、Moment.js、Bootstrap Switch、Bootstrap Duallistbox、Bootstrap Colorpicker、Bootstrap Slider、slimScroll、Glyphicons。

`$form->icon()` 保留图标搜索与弹出选择，Font Awesome Icon Picker 已适配 Bootstrap 5。

`$form->listbox()` 保留双栏选择，不依赖旧的 Bootstrap Duallistbox。`height()` 设置列表像素高度；`settings()` 支持列表标题（`nonSelectedListLabel`、`selectedListLabel`）、搜索（`showFilterInputs`、`filterPlaceHolder`、`filterTextClear`、`filterOnValues`、`nonSelectedFilter`、`selectedFilter`）、计数文案（`infoText`、`infoTextEmpty`、`infoTextFiltered`）、`selectorMinimalHeight`、`moveOnSelect` 和 `moveOnDoubleClick`。旧插件的 jQuery 方法和其他配置不再提供。

`resources/field-assets.php` 已重新生成，不再引用 `AdminLTE/plugins`。

## 6. 宿主检查清单

1. 搜索 `data-toggle`、`data-target`、`data-dismiss`、`AdminLTE/`、`.box`、`.pull-`、`.btn-default`、`.form-group`、`iCheck`、`datetimepicker`、`.editable(`。
2. 将自定义视图改为 AdminLTE 4 结构；如果宿主覆盖过 `public/vendor/laravel-admin`，用 `vendor:publish --force` 后重新合并定制。
3. 将 jQuery 升级到 3.7.x，修复 `.size()`、`.load(fn)`、`$.globalEval` 等旧 API。
4. 清理视图/配置/路由缓存。
5. 回归登录、Grid、Form、Show、上传、标签页、全局导航和移动端。

## 7. 验证

```bash
composer test
npm test
composer check-frontend
php tools/build-source-manifest.php --check
```
