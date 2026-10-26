# 第三方来源与许可证

本包包含来自多个开源项目的代码与前端资源。各组件的版权归原作者所有，按各自许可证分发。本文档与 `licenses/` 下的许可证文本共同构成本包的第三方声明，不改变任何组件原有许可证。

本包整体以 MIT 分发，见根 [LICENSE](LICENSE)；第三方组件仍按其各自许可证分发。如本文档与组件自带的许可证文本存在冲突，以组件自带文本为准。

## 许可证文本索引

| 文件 | 覆盖组件 |
| --- | --- |
| [LICENSE](LICENSE) | 本包整体（MIT） |
| [licenses/MIT.txt](licenses/MIT.txt) | 核心包、大部分前端库及其版权行 |
| [licenses/Apache-2.0.txt](licenses/Apache-2.0.txt) | DOMPurify（bootstrap-fileinput 内置） |
| [licenses/BSD-3-Clause.txt](licenses/BSD-3-Clause.txt) | bootstrap-fileinput |
| [licenses/OFL-1.1.txt](licenses/OFL-1.1.txt) | Font Awesome 字体、Source Sans Pro |
| [licenses/laravel-admin-LICENSE](licenses/laravel-admin-LICENSE) | 上游 Laravel Admin 的 MIT 原文 |
| [licenses/iframe-tabs-LICENSE](licenses/iframe-tabs-LICENSE) | Iframe Tabs 的 MIT 原文 |
| [licenses/log-viewer-LICENSE](licenses/log-viewer-LICENSE) | Log Viewer 随附的 MIT 原文 |

## 核心来源

| 组件 | 基准版本 | 上游仓库 | 锁定提交 | 许可证 |
| --- | --- | --- | --- | --- |
| Laravel Admin | v1.8.19 | https://github.com/z-song/laravel-admin | 6c292ebb5efa886fa4776d86e975180608505946 | MIT（`licenses/laravel-admin-LICENSE`） |
| Iframe Tabs | 1.3.6 | https://github.com/ichynul/iframe-tabs | 75345215d0ac1eed3ffd383720b711c9bf2d04b7 | MIT（`licenses/iframe-tabs-LICENSE`） |

Iframe Tabs 已并入 `src/IframeTabs` 和核心 Provider 启动逻辑，不再作为独立 Composer 依赖或扩展注册。其 Blade 位于 `resources/views/iframe-tabs`，前端位于 `resources/assets/iframe-tabs`，与核心资源一起发布到 `public/vendor/laravel-admin`。

Log Viewer 基于 `laravel-admin-ext/log-viewer` 修改，上游地址为 https://github.com/laravel-admin-extensions/log-viewer。导入的源码快照未声明版本或锁定提交，原文件哈希记录在 `source-manifest.json`。随附许可证为 MIT，版权行是 Copyright (c) 2015 Jens Segers，原文保存在 `licenses/log-viewer-LICENSE`。代码与视图位于 `src/LogViewer`、`resources/views/log-viewer`；本包调整了目录配置、路径校验、分页、输出转义、删除请求和无 PJAX 导航。

## 前端组件

| 组件 | 版本 | 许可证 | 版权 | 文本 |
| --- | --- | --- | --- | --- |
| AdminLTE | 4.9.1 | MIT | Copyright (c) Colorlib / Almsaeed Studio | `licenses/MIT.txt` |
| Bootstrap | 5.3.8 | MIT | Copyright (c) 2011-2026 The Bootstrap Authors | `licenses/MIT.txt` |
| jQuery | 3.7.1 | MIT | Copyright (c) OpenJS Foundation and jQuery contributors | `licenses/MIT.txt` |
| Font Awesome（CSS） | 4.7.0 | MIT | Copyright (c) 2016 Dave Gandy | `licenses/MIT.txt` |
| Font Awesome（字体） | 4.7.0 | SIL OFL 1.1 | Copyright (c) 2016 Dave Gandy | `licenses/OFL-1.1.txt` |
| Source Sans Pro | 随包 | SIL OFL 1.1 | Copyright 2010, 2012 Adobe Systems Incorporated | `licenses/OFL-1.1.txt` |
| Select2 | 4.1.0 | MIT | Copyright (c) Kevin Brown, Igor Vaynberg, Select2 contributors | `licenses/MIT.txt` |
| flatpickr | 4.6.13 | MIT | Copyright (c) Gregory Petrosyan | `licenses/MIT.txt` |
| Pickr | 1.10.2 | MIT | Copyright (c) Simon Reinisch | `licenses/MIT.txt` |
| bootstrap-fileinput | 5.5.4 | BSD-3-Clause | Copyright (c) 2014-2024 Kartik Visweswaran, Krajee.com | `licenses/BSD-3-Clause.txt` |
| toastr | 2.1.3 | MIT | Copyright (c) John Papa, Hans Fjällemark, Tim Ferrell | `licenses/MIT.txt` |
| SweetAlert2 | 7.26.12 | MIT | Copyright (c) Limon Monte | `licenses/MIT.txt` |
| layer / layer mobile | 3.1.1 / 2.0.0 | MIT | Copyright (c) 贤心 | `licenses/MIT.txt` |
| NProgress | 未标注 | MIT | Copyright (c) 2013-2014 Rico Sta. Cruz | `licenses/MIT.txt` |
| Nestable | 未标注 | MIT（上游为 MIT/BSD 双许可） | Copyright (c) 2012 David Bushell | `licenses/MIT.txt` |
| Inputmask | 3.2.8 | MIT | Copyright (c) 2010-2016 Robin Herbots | `licenses/MIT.txt` |
| Ion.RangeSlider | 1.8.2 | MIT | Copyright (c) Denis Ineshin | `licenses/MIT.txt` |
| piexifjs | 未标注 | MIT | Copyright (c) 2014, 2015 hMatoba | `licenses/MIT.txt` |
| JavaScript Canvas to Blob | 2.0.5 | MIT | Copyright 2012 Sebastian Tschan | `licenses/MIT.txt` |
| Sortable | 1.7.0 | MIT | Copyright (c) RubaXa and contributors | `licenses/MIT.txt` |
| DOMPurify | 1.0.7 | Apache-2.0 / MPL-2.0（本包按 Apache-2.0 使用） | Copyright (c) Mario Heiderich, Cure53 | `licenses/Apache-2.0.txt` |

## 特别说明

1. **Font Awesome**：CSS 文件为 MIT，字体文件为 SIL OFL 1.1。两者不可混用同一许可证声明。
2. **bootstrap-fileinput**：除自身的 BSD-3-Clause 外，还捆绑了 DOMPurify（Apache-2.0/MPL-2.0）、piexifjs（MIT）、JavaScript Canvas to Blob（MIT）和 Sortable（MIT），各自声明见上表。
3. **双许可组件**：Nestable 为 MIT/BSD 双许可，本包按 MIT 使用；DOMPurify 为 Apache-2.0/MPL-2.0 双许可，本包按 Apache-2.0 使用。
4. **压缩文件头部**：部分上游压缩文件在发布时已移除版权注释（如 toastr、SweetAlert2、DOMPurify、Select2），本清单及 `licenses/` 下的版权行即为这些文件的统一声明，分发时不要移除本清单。
5. **修改过的文件**：`resources/assets/iframe-tabs/bootstrap-tab.js`、`resources/assets/iframe-tabs/layer/layer.js` 及本包自有的 `admin-ui.js`、`grid-inline-edit.js`、`themes.css`、`custom.css`、`global-navigation.js`、`global-navigation.css`、`actions.js`、`iframe.css`、`laravel-admin/admin-navigation.js` 由本项目维护或修改，仍按对应上游许可证（MIT）分发。
6. **业务资源**：业务品牌资源、业务入口和业务服务不纳入包内。
7. **来源清单**：`source-manifest.json` 的 `packages` 记录当前前端资源的包内路径、字节数和 SHA256；发布前可用 `php tools/build-source-manifest.php --check` 校验。
8. **图片与字体**：作为普通 Git 文件随包分发，文件内容可通过 `source-manifest.json` 中的 SHA256 校验。
