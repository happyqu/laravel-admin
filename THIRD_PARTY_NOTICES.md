# 第三方来源与许可证

本包包含来自多个开源项目的代码与前端资源。各组件的版权归原作者所有，按各自许可证分发。本文档与 `licenses/` 下的许可证文本共同构成本包的第三方声明，不改变任何组件原有许可证。

本包整体以 MIT 分发，见根 [LICENSE](LICENSE)；第三方组件仍按其各自许可证分发。如本文档与组件自带的许可证文本存在冲突，以组件自带文本为准。

## 许可证文本索引

| 文件 | 覆盖组件 |
| --- | --- |
| [LICENSE](LICENSE) | 本包整体（MIT） |
| [licenses/MIT.txt](licenses/MIT.txt) | 核心包、大部分前端库及其版权行 |
| [licenses/Apache-2.0.txt](licenses/Apache-2.0.txt) | Duallistbox、Colorpicker、Bootstrap Slider、bootstrap-spin、DOMPurify |
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
| AdminLTE | 2.3.2 | MIT | Copyright (c) Almsaeed Studio | `licenses/MIT.txt` |
| Bootstrap | 3.3.5 | MIT | Copyright (c) 2011-2015 Twitter, Inc. | `licenses/MIT.txt` |
| jQuery | 2.1.4 | MIT | Copyright (c) 2005, 2015 jQuery Foundation, Inc. | `licenses/MIT.txt` |
| Font Awesome（CSS） | 4.7.0 | MIT | Copyright (c) 2016 Dave Gandy | `licenses/MIT.txt` |
| Font Awesome（字体） | 4.7.0 | SIL OFL 1.1 | Copyright (c) 2016 Dave Gandy | `licenses/OFL-1.1.txt` |
| Source Sans Pro | 随包 | SIL OFL 1.1 | Copyright 2010, 2012 Adobe Systems Incorporated | `licenses/OFL-1.1.txt` |
| toastr | 2.1.3 | MIT | Copyright (c) John Papa, Hans Fjällemark, Tim Ferrell | `licenses/MIT.txt` |
| SweetAlert2 | 7.26.12 | MIT | Copyright (c) Limon Monte | `licenses/MIT.txt` |
| Select2 | 4.0.3 | MIT | Copyright (c) Kevin Brown, Igor Vaynberg, Select2 contributors | `licenses/MIT.txt` |
| flatpickr | 4.6.13 | MIT | Copyright (c) Gregory Petrosyan | `licenses/MIT.txt` |
| Cropper.js | 2.3.1 | MIT | Copyright (c) 2015-present Chen Fengyuan | `resources/assets/cropperjs/LICENSE` |
| layer / layer mobile | 3.1.1 / 2.0.0 | MIT | Copyright (c) 贤心 | `licenses/MIT.txt` |
| iCheck | 1.0.1 | MIT | Copyright (c) Damir Sultanov | `licenses/MIT.txt` |
| NProgress | 未标注 | MIT | Copyright (c) 2013-2014 Rico Sta. Cruz | `licenses/MIT.txt` |
| Nestable | 未标注 | MIT（上游为 MIT/BSD 双许可） | Copyright (c) 2012 David Bushell | `licenses/MIT.txt` |
| X-Editable | 1.5.1 | MIT | Copyright (c) 2013 Vitaliy Potapov | `licenses/MIT.txt` |
| Bootstrap Switch | 3.3.2 | MIT | Copyright 2012-2013 Mattia Larentis | `licenses/MIT.txt` |
| Inputmask | 3.2.8 | MIT | Copyright (c) 2010-2016 Robin Herbots | `licenses/MIT.txt` |
| Ion.RangeSlider | 1.8.2 | MIT | Copyright (c) Denis Ineshin | `licenses/MIT.txt` |
| slimScroll | 1.3.8 | MIT（上游为 MIT/GPL 双许可） | Copyright (c) 2011 Piotr Rochala | `licenses/MIT.txt` |
| Font Awesome Icon Picker | 未标注 | MIT | Copyright (c) 2016 Javi Aguilar | `licenses/MIT.txt` |
| normalize.css | 3.0.3 | MIT | Copyright (c) Nicolas Gallagher and Jonathan Neal | `licenses/MIT.txt` |
| piexifjs | 未标注 | MIT | Copyright (c) 2014, 2015 hMatoba | `licenses/MIT.txt` |
| JavaScript Canvas to Blob | 2.0.5 | MIT | Copyright 2012 Sebastian Tschan | `licenses/MIT.txt` |
| Sortable | 1.7.0 | MIT | Copyright (c) RubaXa and contributors | `licenses/MIT.txt` |
| Bootstrap Duallistbox | 3.0.6 | Apache-2.0 | Copyright (c) 2013-2016 István Ujj-Mészáros | `licenses/Apache-2.0.txt` |
| Bootstrap Colorpicker | 未标注 | Apache-2.0 | Copyright (c) 2012 Stefan Petre | `licenses/Apache-2.0.txt` |
| Bootstrap Slider | 未标注 | Apache-2.0 | Copyright (c) Kyle Kemp, Rohit Kalkur | `licenses/Apache-2.0.txt` |
| bootstrap-spin（number-input） | 1.0 | Apache-2.0 | Copyright 2014 WPIC, Hamed Abdollahpour | `licenses/Apache-2.0.txt` |
| DOMPurify | 1.0.7 | Apache-2.0 / MPL-2.0（本包按 Apache-2.0 使用） | Copyright (c) Mario Heiderich, Cure53 | `licenses/Apache-2.0.txt` |
| bootstrap-fileinput | 4.5.2 | BSD-3-Clause | Copyright (c) 2014-2018 Kartik Visweswaran, Krajee.com | `licenses/BSD-3-Clause.txt` |
| Glyphicons Halflings | 随 Bootstrap 3.3.5 | 随 Bootstrap 分发 | 版权归原作者 | 见下方说明 |

## 特别说明

1. **Font Awesome**：CSS 文件为 MIT，字体文件为 SIL OFL 1.1。两者不可混用同一许可证声明。
2. **bootstrap-fileinput**：除自身的 BSD-3-Clause 外，还捆绑了 DOMPurify（Apache-2.0/MPL-2.0）、piexifjs（MIT）、JavaScript Canvas to Blob（MIT）和 Sortable（MIT），各自声明见上表。
3. **双许可组件**：slimScroll 为 MIT/GPL 双许可，Nestable 为 MIT/BSD 双许可，本包均按 MIT 使用。DOMPurify 为 Apache-2.0/MPL-2.0 双许可，本包按 Apache-2.0 使用。
4. **Glyphicons Halflings**：随 Bootstrap 3.3.5 分发。Bootstrap 文档请求在使用时尽量保留指向 glyphicons.com 的来源说明；本包未修改该字体，仅按原样分发。
5. **压缩文件头部**：部分上游压缩文件在发布时已移除版权注释（如 toastr、SweetAlert2、DOMPurify、Select2），本清单及 `licenses/` 下的版权行即为这些文件的统一声明，分发时不要移除本清单。
6. **修改过的文件**：`resources/assets/iframe-tabs/bootstrap-tab.js`、`resources/assets/iframe-tabs/layer/layer.js` 及本包自有的 `custom.css`、`global-navigation.js`、`global-navigation.css`、`actions.js`、`iframe.css`、`laravel-admin/admin-navigation.js` 由本项目维护或修改，仍按对应上游许可证（MIT）分发。
7. **业务资源**：业务品牌资源、业务入口和业务服务不纳入包内。
8. **来源清单**：`source-manifest.json` 中 `packages` 记录导入时的原始库文件与哈希，`assets` 记录每个前端文件的原始来源、当前包内路径及 SHA256。源码和 Blade 因内置化、安全修复与发布前清理而调整，`assets` 中的哈希对应当前包内基线。
9. **图片与字体**：作为普通 Git 文件随包分发，文件内容可通过 `source-manifest.json` 中的 SHA256 校验。

## 日期组件迁移

日期、时间、范围、多日期、Grid 筛选和日期行内编辑统一使用 flatpickr 4.6.13，Moment.js 和 eonasdan-bootstrap-datetimepicker 已移除。原始 flatpickr `dist` 目录保持原样。后台加载由 `node tools/build-flatpickr.cjs` 生成的 `resources/assets/laravel-admin/flatpickr-local.js`：它在独立作用域中为上游代码提供日期构造兼容处理，通过时间戳和本地 setter 创建日历日期，避免时区改写对多参数 Date 构造施加额外偏移，不替换全局 Date。构建包含完整的上游代码并保留 MIT 声明；更新上游后须重新生成，可用 `node tools/build-flatpickr.cjs --check` 检查产物。

`resources/assets/laravel-admin/datepicker.js` 与 `datepicker.css` 为本包维护的适配代码。`resources/assets/laravel-admin/flatpickr-locales.js` 原样取自 npm 官方 `flatpickr@4.6.13` 的 `dist/l10n/index.js`，沿用 MIT 许可证，并保留文件中附带的版权及许可声明。X-Editable 内置的旧 combodate 代码保留在上游资源中，但本包日期输入通过 Flatpickr 适配器注册，不再调用其 Moment 实现。
