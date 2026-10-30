<?php

namespace Encore\Admin\Cascader;

use InvalidArgumentException;

/** Subclasses supply a small static tree with value/label/children nodes. */
abstract class ArraySource extends Source implements TreeSource
{
    abstract protected function options(): array;

    private function node(array $node): array
    {
        $node['leaf'] = empty($node['children']);

        return self::nodes([$node])[0];
    }

    public function tree(): array
    {
        $normalize = function (array $items, int $depth = 0) use (&$normalize): array {
            if ($items && $depth >= 16) {
                throw new InvalidArgumentException('Cascader supports at most 16 levels.');
            }

            return array_map(fn ($item) => $this->node($item) + ['children' => $normalize($item['children'] ?? [], $depth + 1)], array_values($items));
        };

        return $normalize($this->options());
    }

    public function children(array $path, string $search = '', ?string $cursor = null): array
    {
        $items = $this->options();
        foreach (self::path($path) as $id) {
            $match = null;
            foreach ($items as $item) {
                if ((string) $item['value'] === $id && empty($item['disabled'])) {
                    $match = $item;
                    break;
                }
            }
            if ($match === null) {
                throw new InvalidArgumentException('Unavailable parent.');
            }
            $items = $match['children'] ?? [];
        }
        $items = array_values(array_filter($items, static fn ($item) => $search === '' || mb_stripos($item['label'], $search) !== false));
        $offset = max(0, (int) $cursor);

        return [
            'items' => array_map(fn ($node) => $this->node($node), array_slice($items, $offset, 50)),
            'nextCursor' => count($items) > $offset + 50 ? (string) ($offset + 50) : null,
        ];
    }

    public function resolve($value): array
    {
        $ids = self::path($value);
        $walk = function ($items, $ancestors) use (&$walk, $ids, $value) {
            foreach ($items as $item) {
                $path = [...$ancestors, $this->node($item)];
                if (is_array($value)) {
                    if (array_column($path, 'value') === $ids) {
                        return $path;
                    }
                } elseif ((string) $item['value'] === ($ids[0] ?? null)) {
                    return $path;
                }
                if ($found = $walk($item['children'] ?? [], $path)) {
                    return $found;
                }
            }

            return [];
        };

        return $ids ? $walk($this->options(), []) : [];
    }
}
