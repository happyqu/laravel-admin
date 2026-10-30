<?php

namespace Encore\Admin\Cascader;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** One Model per level; override query() to apply tenant/permission scopes. */
abstract class ModelSource extends Source
{
    abstract protected function levels(): array;

    protected function level(int $index): array
    {
        $level = $this->levels()[$index] ?? null;
        if (!$level || !is_subclass_of($level['model'] ?? '', Model::class)) {
            throw new InvalidArgumentException('Invalid cascader Model level.');
        }
        if ($index > 0 && empty($level['parent'])) {
            throw new InvalidArgumentException('Missing cascader parent mapping.');
        }
        $model = new $level['model']();

        return $level + ['value' => $model->getKeyName(), 'text' => 'name'];
    }

    protected function query(int $level, array $path): Builder
    {
        $definition = $this->level($level);
        $query = $definition['model']::query();
        if ($level > 0) {
            if (empty($definition['parent']) || count($path) !== $level) {
                throw new InvalidArgumentException('Missing cascader parent mapping.');
            }
            $query->where($definition['parent'], $path[$level - 1]);
        }

        return $query;
    }

    private function node(Model $model, int $level): array
    {
        $definition = $this->level($level);

        return self::nodes([[
            'value' => $model->getAttribute($definition['value']),
            'label' => $model->getAttribute($definition['text']),
            'leaf' => $level === count($this->levels()) - 1,
        ]])[0];
    }

    public function children(array $path, string $search = '', ?string $cursor = null): array
    {
        $path = self::path($path);
        if ($path) {
            $this->validate($path, false);
        }
        $level = count($path);
        if ($level >= count($this->levels())) {
            return ['items' => [], 'nextCursor' => null];
        }
        $definition = $this->level($level);
        $query = $this->query($level, $path);
        if ($search !== '') {
            $query->where($definition['text'], 'like', '%'.$search.'%');
        }
        $offset = max(0, (int) $cursor);
        $items = $query->orderBy($definition['value'])->skip($offset)->take(51)->get();

        return [
            'items' => $items->take(50)->map(fn ($model) => $this->node($model, $level))->all(),
            'nextCursor' => $items->count() > 50 ? (string) ($offset + 50) : null,
        ];
    }

    public function resolve($value): array
    {
        $ids = self::path($value);
        if (!$ids) {
            return [];
        }
        if (!is_array($value)) {
            // Recover ancestor IDs first, then re-query the entire path through query().
            $ids = [];
            $id = $value;
            for ($index = count($this->levels()) - 1; $index >= 0; --$index) {
                $definition = $this->level($index);
                $model = $definition['model']::query()->where($definition['value'], $id)->first();
                if (!$model) {
                    return [];
                }
                array_unshift($ids, (string) $model->getAttribute($definition['value']));
                $id = $index ? $model->getAttribute($definition['parent']) : null;
            }
        }
        $nodes = $path = [];
        foreach ($ids as $index => $id) {
            if ($index >= count($this->levels())) {
                return [];
            }
            $model = $this->query($index, $path)->where($this->level($index)['value'], $id)->first();
            if (!$model) {
                return [];
            }
            $nodes[] = $this->node($model, $index);
            $path[] = (string) $id;
        }

        return $nodes;
    }
}
