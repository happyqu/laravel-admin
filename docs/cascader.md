# Cascader 级联选择器

Cascader 是独立的级联组件，拥有自己的面板、数据源和请求流程，不依赖 Select 或 Select2。支持 form 和 filter、静态树、跨 Model 查询和第三方数据。

## 快速使用

省市区选择可直接使用内置数据源，无需地区 Model、数据库表或第三方扩展：

```php
use Encore\Admin\Cascader\RegionSource;

$form->cascader(['province', 'city', 'district'], '所属地区')
    ->source(RegionSource::class);
```

选中“浙江省 / 杭州市 / 西湖区”后，提交及保存的数据为：

```php
[
    'province' => '浙江省',
    'city' => '杭州市',
    'district' => '西湖区',
]
```

选项的 `value` 和 `label` 都是名称，不会提交行政区划编码。字段名可按实际数据库调整，例如 `['province_name', 'city_name', 'district_name']`；字段应使用字符串类型。

filter 使用同一个内置数据源，查询条件同样使用名称：

```php
$grid->filter(function ($filter) {
    $filter->cascader(['province', 'city', 'district'], '所属地区')
        ->source(RegionSource::class);
});
```

编辑时直接读取已存的三个名称回显，保存时验证真实的省市区归属。filter 可以选择省或市：点击该节点右侧的勾选按钮即可，下级字段留空。

内置省市区在页面初始化时一次加载整份数据，同页相同数据源的多个组件共享结果；之后切换层级、搜索、重新展开和编辑回显都在本地完成，不会逐级请求或显示“加载中”。只有首次获取数据或失败后重试会访问接口，保存时仍在服务端验证路径。

内置地区名称包含 34/384/3619 个省/市/区县节点，按“省名 → 市名 → 区县名称数组”保存在 `resources/data/china-regions.json`，不包含行政区划编码或扩展项目元数据，运行时不会请求外部服务：

```json
{
  "浙江省": {
    "杭州市": ["上城区", "西湖区", "滨江区"]
  }
}
```

上面仅展示结构示例。数据按本地文件维护，不自动同步行政区划变化；名称与层级按本次提供的数据整理，例如“北京市 / 市辖区 / 朝阳区”。东莞、中山、儋州、嘉峪关及部分其他节点未提供区县下级，选择到该市级节点即可完成选择，区县字段留空，不补造重复名称。若添加 `rules('required')`，该规则会要求所有配置字段非空，因而会排除这些没有区县层级的节点；需要支持这些地区时不要将三个字段全部设为必填。

更新数据时可运行 `node tools/import-regions.cjs /path/to/0.json` 转换父代码分组的 `{c, n}` 数据，或直接编辑对应省、市下的名称，再运行 `php vendor/phpunit/phpunit/phpunit tests/Cascader/RegionSourceTest.php` 检查路径。同一城市下区县名称不得重复，跨城市同名区县允许存在；增删节点时同步调整测试中的节点数量。

内置 `RegionSource` 请使用多字段模式。不同城市可能有同名区县（例如“朝阳区”），因此不支持仅保存一个区县名称后反查路径。已有编码数据也不会自动转换为名称，需要先完成业务数据迁移。

## 自定义 Model 数据源

先定义可复用的数据源，例如 `app/Admin/Sources/ModelRegionSource.php`：

仓库已提供 [ModelRegionSource.php 示例文件](../examples/cascader/app/Admin/Sources/ModelRegionSource.php)，复制到业务项目的同名路径即可。当前仓库是组件包，不包含业务项目的 `Province`、`City`、`District` Model；请按实际命名空间和外键调整下面的映射。默认读取 Model 主键和 `name` 字段。

```php
namespace App\Admin\Sources;

use App\Models\Province;
use App\Models\City;
use App\Models\District;
use Encore\Admin\Cascader\ModelSource;

class ModelRegionSource extends ModelSource
{
    protected function levels(): array
    {
        return [
            ['model' => Province::class],
            ['model' => City::class, 'parent' => 'province_id'],
            ['model' => District::class, 'parent' => 'city_id'],
        ];
    }
}
```

页面中只需要配置一个组件：

```php
use App\Admin\Sources\ModelRegionSource;

$form->cascader(['province_id', 'city_id', 'district_id'], '所属地区')
    ->source(ModelRegionSource::class)
    ->rules('required');
```

选中路径后，分别提交 `province_id`、`city_id`、`district_id`，不增加数据库字段。字段数组顺序必须与数据源层级顺序一致。默认选到叶子节点才完成选择；`rules('required')` 表示每个配置字段都必须填写。

可选组件不要添加 `required`。清空后提交空值，保存时转为 `null`，数据库字段需要允许空值。

## 只保存末级 ID

```php
$form->cascader('district_id', '所属地区')->source(ModelRegionSource::class);
```

界面仍显示完整路径，提交时只保存区县 ID。ModelSource 会从最末级 Model 反查祖先，并按查询范围重新校验整条路径。末级值必须能唯一定位一条路径。

## Filter

```php
$grid->filter(function ($filter) {
    $filter->cascader(['province_id', 'city_id', 'district_id'], '所属地区')
        ->source(ModelRegionSource::class);
});
```

多字段 filter 默认允许只选择省或市：点击节点右侧的勾选按钮选中当前级，点击名称或箭头继续展开。查询时对已选各级字段添加等值条件，未选下级不加条件。

地区字段位于关联 Model 时，使用与其他 filter 一致的点号写法：

```php
$filter->cascader(['address.province', 'address.city', 'address.district'], '收货地区')
    ->source(\Encore\Admin\Cascader\RegionSource::class);
```

`address` 是当前 Model 的 Eloquent 关联方法，查询通过 `whereHas` 应用条件；同一关联中的各级条件必须由同一条关联记录满足。支持 `address.region.province` 这样的多层关联，也可混用主表字段和关联字段。命名 Grid 的回显及重置会保留正确的字段层级。

只按末级字段筛选也可以：

```php
$filter->cascader('district_id', '所属地区')->source(ModelRegionSource::class);
```

单字段 filter 只支持选择叶子节点。它不会把省份 ID 当成区县 ID，也不会自动生成后代 ID 查询。命名 Grid 会自动为请求字段加前缀；重置筛选会移除所有相关字段。

## 可选配置

```php
$form->cascader(['province_id', 'city_id', 'district_id'], '所属地区')
    ->source(ModelRegionSource::class)
    ->placeholder('请选择配送区域')
    ->checkStrictly();
```

| 方法 | 作用 |
| --- | --- |
| `source(SourceClass::class, $context = [])` | 指定数据源及服务端上下文 |
| `placeholder('请选择')` | 未选择时的提示 |
| `checkStrictly()` | 允许选择中间节点 |
| `checkStrictly(false)` | 仅允许选择叶子节点 |
| `rules('required')` | form 的必填规则 |
| `readonly()` | form 只读，保留已选路径显示 |
| `disable()` | form 禁用，不提交字段 |

多字段模式选择中间节点后，下级字段为空。此时不要对所有层级设置 `required`；可在业务表单中单独配置所需的校验。单字段 ModelSource 定位末级记录，不适合保存中间层节点。

## Model 字段映射和数据权限

每一级支持 `model`、`value`、`text`、`parent`：

```php
protected function levels(): array
{
    return [
        ['model' => Province::class, 'value' => 'code', 'text' => 'title'],
        ['model' => City::class, 'value' => 'code', 'text' => 'title', 'parent' => 'province_code'],
    ];
}
```

`value` 默认是 Model 主键，`text` 默认是 `name`，`parent` 表示当前 Model 中存储上级值的字段。ModelSource 按 `value` 排序，每页 50 项，展开时加载当前层，支持当前层名称搜索。

租户、可见范围等约束放在数据源中，搜索、回显和保存校验都会应用：

```php
use Illuminate\Database\Eloquent\Builder;

protected function query(int $level, array $path): Builder
{
    return parent::query($level, $path)
        ->where('tenant_id', $this->context['tenant_id']);
}
```

页面传入从服务端获得的上下文：

```php
$form->cascader(['province_id', 'city_id', 'district_id'], '所属地区')
    ->source(ModelRegionSource::class, ['tenant_id' => Admin::user()->tenant_id]);
```

上下文应是可 JSON 编码的普通数据；不要放 API 密钥、对象或闭包。第三方凭据从服务端配置读取。上下文和数据源绑定当前用户、来源页面与组件凭据；每次请求仍会重新检查来源页面权限。数据源本身应根据当前登录用户执行数据范围校验。

## 静态树

```php
use Encore\Admin\Cascader\ArraySource;

class CategorySource extends ArraySource
{
    protected function options(): array
    {
        return [
            ['value' => 'hardware', 'label' => '硬件', 'children' => [
                ['value' => 'server', 'label' => '服务器'],
                ['value' => 'network', 'label' => '网络设备', 'disabled' => true],
            ]],
        ];
    }
}
```

ArraySource 根据 `children` 自动识别叶子节点。相同 ID 可以出现在不同父路径下；单字段保存时 ID 必须全树唯一。

ArraySource 同样采用整树一次加载。自定义静态数据源可以实现 `Encore\Admin\Cascader\TreeSource` 接口，并通过 `tree(): array` 返回完整的节点树；节点使用 `value`、`label`、`leaf`、`disabled`、`children` 字段。Model 和第三方远程数据源默认继续按需加载。

## 第三方数据源

继承 `Encore\Admin\Cascader\Source`，实现子节点查询与路径解析。以下示例假设业务服务 `RegionGateway` 已完成第三方鉴权、请求超时与返回格式转换：

```php
use App\Services\RegionGateway;
use Encore\Admin\Cascader\Source;

class RemoteRegionSource extends Source
{
    public function __construct(private RegionGateway $gateway) {}

    public function children(array $path, string $search = '', ?string $cursor = null): array
    {
        return $this->gateway->children($path, $search, $cursor, $this->context);
    }

    public function resolve($value): array
    {
        return $this->gateway->resolvePath($value, $this->context);
    }
}
```

`children()` 接收完整祖先 ID 路径，根节点为 `[]`，返回：

```php
[
    'items' => [
        ['value' => '330100', 'label' => '杭州市', 'leaf' => false, 'disabled' => false],
    ],
    'nextCursor' => null, // 有下一页时返回非空字符串
]
```

每页最多返回 100 个节点，同一父节点下 `value` 必须唯一。`value` 使用字符串或整数，`0` 是有效值，空字符串不作为 ID。标签以纯文本显示。

`resolve($value)` 接收路径数组或末级 ID，返回从根到当前节点的节点数组。不存在或无权限时返回 `[]`；它必须验证真实父子关系与当前数据范围，不能直接把传入路径包装成节点。单字段模式需要服务端反查祖先；第三方缺少反查能力时，请采用多字段模式。

基类 `validate()` 会通过 `resolve()` 检查路径一致性、禁用祖先、最大深度和叶子约束。无需另外实现保存校验。第三方异常应抛出，让组件显示失败重试；不要把网络失败伪装为不存在或空列表。保存时第三方校验失败会返回表单错误，保留原输入。

## 回显、交互与生命周期

- 编辑、校验失败返回：通过 `resolve` 恢复标签；已选项不可用时保留原值、显示错误，要求重新选择或清空。
- 已有值正在解析或解析失败时，前端阻止提交；服务端仍独立执行路径校验。
- 展开过程中只更新浏览路径，选中有效节点才一起更新提交字段；点外部或 Esc 关闭会保留原值。
- 搜索仅过滤当前父路径下这一层，不执行跨层级全文搜索。静态树在本地立即过滤；远程数据源请求防抖 250ms，过期响应不会覆盖新结果。
- 支持方向键、回车、Esc；窄屏逐级进入并提供返回入口。
- hasMany/table 中每行独立初始化；移除行或切换页面时清理面板和请求。
- 第一版为单路径选择，不支持多路径多选。

## 更新资源

包升级后按项目常规流程重新发布资源：

```bash
php artisan vendor:publish --tag=laravel-admin-assets --force
```

确保应用仍通过 `Admin::routes()` 注册内置路由。组件使用独立的 `_cascader_` 接口，无需为每个数据源编写路由。长期打开的页面遇到组件凭据过期时，重新打开或刷新页面后再操作。
