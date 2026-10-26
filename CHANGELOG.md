# Changelog

## 3.0.0

### Breaking changes

- 前端升级到 AdminLTE 4.9.1 / Bootstrap 5.3.8 / jQuery 3.7.1，删除 AdminLTE 2 / Bootstrap 3 资源。
- 不提供 BS3 jQuery API 桥接或 AdminLTE 2 兼容 CSS。
- 布局、视图、Grid、Form、Filter、Widget、标签页和全局导航改用 AdminLTE 4 / BS5 标记。
- `admin.skin` 改为 `admin.theme` + `admin.color_mode`；移除 12 套 `skin-*` 运行时映射。
- 移除 iCheck、X-Editable、Eonasdan datetimepicker、Moment、Bootstrap Switch、Duallistbox、Colorpicker、slimScroll、Font Awesome Icon Picker、Glyphicons。
- 日期时间改用 Flatpickr；颜色改用 Pickr；文件上传升级到 bootstrap-fileinput 5.x。
- `resources/field-assets.php` 重新生成；`source-manifest.json` 重新生成。

### Added

- `AdminUI` 内部 BS5 工具（非 jQuery 桥接）。
- `grid-inline-edit.js` 轻量行内编辑器。
- `themes.css` 主题变量。
- `tools/check-frontend-assets.php`、`tools/check-legacy-frontend.php`、`tools/build-source-manifest.php`。
- `UPGRADE-3.0.md` 迁移指南。

### Fixed

- jQuery 3 兼容：`.size()`、`.load(fn)`、`$.globalEval`。
- `admin.default_avatar` 指向新资源路径。
- 修正 Select2 的 JS 资源路径，Select/Tags/多选字段可正常初始化。
- 标签页和全局导航适配 AdminLTE 4 布局。

## 2.0.0

- 移除 PJAX，改为 AJAX 内容导航。
- 内置 iframe 标签页、全局菜单、日志查看器。
- 支持 Laravel 10–13、PHP 8.1–8.4。
- 上传安全加固。
