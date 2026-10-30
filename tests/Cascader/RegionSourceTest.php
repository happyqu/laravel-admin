<?php

namespace Encore\Admin\Tests\Cascader;

use Encore\Admin\Cascader\RegionSource;
use PHPUnit\Framework\TestCase;

class RegionSourceTest extends TestCase
{
    public function test_bundled_name_tree_resolves_every_path(): void
    {
        $data = json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/data/china-regions.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('浙江省', $data);
        $this->assertContains('西湖区', $data['浙江省']['杭州市']);
        $this->assertArrayNotHasKey('source', $data);
        $this->assertArrayNotHasKey('districts', $data);
        foreach ($data as $cities) {
            foreach ($cities as $districts) {
                $this->assertSame(array_values(array_unique($districts)), $districts);
            }
        }
        $source = new RegionSource();
        $provinces = $source->children([])['items'];
        $this->assertCount(34, $provinces);
        $cities = $districts = 0;
        foreach ($provinces as $province) {
            foreach ($source->children([$province['value']])['items'] as $city) {
                ++$cities;
                foreach ($source->children([$province['value'], $city['value']])['items'] as $district) {
                    ++$districts;
                    $path = [$province['value'], $city['value'], $district['value']];
                    $resolved = $source->validate($path, true, 3);
                    $this->assertSame($path, array_column($resolved, 'value'));
                    $this->assertSame($path, array_column($resolved, 'label'));
                    $this->assertTrue($resolved[2]['leaf']);
                }
            }
        }
        $this->assertSame(384, $cities);
        $this->assertSame(3619, $districts);
    }

    public function test_search_and_duplicate_names_are_path_scoped(): void
    {
        $source = new RegionSource();
        $this->assertSame(['杭州市'], array_column($source->children(['浙江省'], '杭州')['items'], 'value'));
        foreach ([['北京市', '市辖区', '朝阳区'], ['吉林省', '长春市', '朝阳区'], ['广东省', '东莞市'], ['香港特别行政区', '中西区']] as $path) {
            $this->assertSame($path, array_column($source->validate($path), 'value'));
            $this->assertSame([], $source->children($path)['items']);
        }
        $this->assertSame(['浙江省'], array_column($source->validate(['浙江省', '', ''], false), 'value'));
        $this->assertTrue($source->validate(['广东省', '东莞市', null])[1]['leaf']);
        $hangzhou = array_column($source->children(['浙江省', '杭州市'])['items'], 'value');
        $this->assertContains('临平区', $hangzhou);
        $this->assertContains('钱塘区', $hangzhou);
        $this->assertNotContains('下城区', $hangzhou);
        $this->assertNotContains('江干区', $hangzhou);
        foreach ([['浙江省', '杭州市', '朝阳区'], ['浙江省', '杭州市', '下城区'], ['北京市', '杭州市', '西湖区'], ['浙江省', '', '西湖区'], ['广东省', '东莞市', '东莞市']] as $path) {
            try {
                $source->validate($path);
                $this->fail('Accepted an unavailable region path.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $source->validate('朝阳区');
    }
}
