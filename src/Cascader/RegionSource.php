<?php

namespace Encore\Admin\Cascader;

use InvalidArgumentException;

/** Offline province/city/district names; option values and labels are identical. */
class RegionSource extends Source implements TreeSource
{
    private static ?array $regions = null;

    private function regions(): array
    {
        if (self::$regions === null) {
            self::$regions = json_decode(file_get_contents(__DIR__.'/../../resources/data/china-regions.json'), true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$regions;
    }

    private function node(string $name, bool $leaf): array
    {
        return [
            'value' => $name, 'label' => $name,
            'leaf' => $leaf, 'disabled' => false,
        ];
    }

    public function tree(): array
    {
        $tree = [];
        foreach ($this->regions() as $province => $cities) {
            $cityNodes = [];
            foreach ($cities as $city => $districts) {
                $districtNodes = array_map(fn ($name) => $this->node($name, true) + ['children' => []], $districts);
                $cityNodes[] = $this->node($city, !$districtNodes) + ['children' => $districtNodes];
            }
            $tree[] = $this->node($province, !$cityNodes) + ['children' => $cityNodes];
        }

        return $tree;
    }

    private function branch(array $path): array
    {
        $path = self::path($path);
        if (count($path) > 3) {
            throw new InvalidArgumentException('RegionSource supports province/city/district paths.');
        }
        $items = $this->regions();
        $nodes = [];
        foreach ($path as $index => $name) {
            $exists = $index < 2 ? array_key_exists($name, $items) : in_array($name, $items, true);
            if (!$exists) {
                throw new InvalidArgumentException('Unavailable region path.');
            }
            $items = $index < 2 ? $items[$name] : [];
            $nodes[] = $this->node($name, empty($items));
        }

        return [$nodes, $items];
    }

    public function children(array $path, string $search = '', ?string $cursor = null): array
    {
        [$nodes, $children] = $this->branch($path);
        if (count($nodes) === 3) {
            return ['items' => [], 'nextCursor' => null];
        }
        $names = count($nodes) < 2 ? array_keys($children) : $children;
        $items = array_values(array_filter($names, static fn ($name) => $search === '' || mb_stripos($name, $search) !== false));
        $offset = max(0, (int) $cursor);
        $page = [];
        foreach (array_slice($items, $offset, 50) as $name) {
            $page[] = $this->node($name, count($nodes) === 2 || empty($children[$name]));
        }

        return ['items' => $page, 'nextCursor' => count($items) > $offset + 50 ? (string) ($offset + 50) : null];
    }

    public function resolve($value): array
    {
        if (!is_array($value)) {
            // Names such as 朝阳区 occur under different cities; a terminal name is ambiguous.
            throw new InvalidArgumentException('RegionSource requires separate province/city/district fields.');
        }

        return $this->branch($value)[0];
    }
}
