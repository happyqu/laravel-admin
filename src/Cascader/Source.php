<?php

namespace Encore\Admin\Cascader;

use InvalidArgumentException;

/** All IDs are strings at the transport boundary; zero is a valid ID. */
abstract class Source
{
    protected array $context = [];

    public static function make(string $class, array $context = []): self
    {
        if (!is_subclass_of($class, self::class)) {
            throw new InvalidArgumentException('Cascader source must extend '.self::class);
        }
        $source = app()->make($class);
        $source->context = $context;

        return $source;
    }

    /** Return ['items' => nodes, 'nextCursor' => ?string]. Paths contain ancestor IDs. */
    abstract public function children(array $path, string $search = '', ?string $cursor = null): array;

    /** Resolve a complete/partial path, or a terminal ID, into its ordered ancestor nodes. */
    abstract public function resolve($value): array;

    public static function path($value): array
    {
        $values = is_array($value) ? array_values($value) : [$value];
        if (count($values) > 16) {
            throw new InvalidArgumentException('Cascader supports at most 16 levels.');
        }
        $path = [];
        $empty = false;
        foreach ($values as $id) {
            if ($id === '' || $id === null) {
                $empty = true;
                continue;
            }
            if ($empty || (!is_int($id) && !is_string($id)) || strlen((string) $id) > 255) {
                throw new InvalidArgumentException('Invalid cascader path.');
            }
            $path[] = (string) $id;
        }

        return $path;
    }

    public static function nodes(array $nodes): array
    {
        return array_map(static function ($node) {
            if (!is_array($node) || !isset($node['value'], $node['label'])
                || (!is_string($node['value']) && !is_int($node['value']))
                || (string) $node['value'] === '' || !is_scalar($node['label'])) {
                throw new InvalidArgumentException('Invalid cascader node.');
            }

            return [
                'value' => (string) $node['value'], 'label' => (string) $node['label'],
                'leaf' => (bool) ($node['leaf'] ?? false), 'disabled' => (bool) ($node['disabled'] ?? false),
            ];
        }, array_values($nodes));
    }

    /** Sources must resolve using the same authorization/data scopes as children(). */
    public function validate($value, bool $leafOnly = true, int $depth = 0): array
    {
        $ids = self::path($value);
        if (!$ids) {
            return [];
        }
        $nodes = self::nodes($this->resolve(is_array($value) ? $ids : $ids[0]));
        $resolved = array_column($nodes, 'value');
        if (!$nodes || count($nodes) > 16 || ($depth && count($nodes) > $depth)
            || (is_array($value) ? $resolved !== $ids : end($resolved) !== $ids[0])
            || array_filter($nodes, static fn ($node) => $node['disabled'])
            || ($leafOnly && !end($nodes)['leaf'])) {
            throw new InvalidArgumentException('The selected cascader path is unavailable.');
        }

        return $nodes;
    }
}
