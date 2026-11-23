<?php

namespace Encore\Admin\Select;

use Encore\Admin\Admin;
use Closure;
use Illuminate\Contracts\Support\Arrayable;

class DependentOptions
{
    private ?ModelOptions $source = null;
    private array $groups = [];
    private ?array $allowedParents = null;

    public function __construct(
        public readonly string $parent,
        array|Arrayable|string $options,
        ?string $foreignKey,
        string $textField,
        ?iterable $allowedParents
    ) {
        if (is_string($options)) {
            $this->source = new ModelOptions($options, $foreignKey ?? $parent, $textField);
            $this->allowedParents = $allowedParents === null ? null : self::values($allowedParents);

            return;
        }

        foreach ($options instanceof Arrayable ? $options->toArray() : $options as $value => $group) {
            $this->groups[$value] = [];
            foreach ($group as $id => $label) {
                $this->groups[$value][] = [(string) $id, (string) $label];
            }
        }
    }

    private static function values(iterable $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if ((is_string($value) || is_int($value)) && $value !== '') {
                $ids[(string) $value] = (string) $value;
            }
        }

        return array_values($ids);
    }

    public function render($parentValue, $selected, Closure $parentOptions): array
    {
        Admin::js('/vendor/laravel-admin/laravel-admin/dependent-select.js?v=2', false);
        Admin::script('$.admin.initDependentSelects();');

        $initialParent = is_string($parentValue) || is_int($parentValue) ? (string) $parentValue : '';
        $selected = self::values((array) $selected);
        $options = [];
        if ($this->source) {
            $allowedParents = $this->allowedParents ?? self::values($parentOptions());
            if (!in_array($initialParent, $allowedParents, true)) {
                $initialParent = '';
            }
            if ($initialParent !== '' && $selected) {
                $options = $this->source->selected($initialParent, $selected);
            }
            $config = [
                'url' => $this->source->url($allowedParents),
                'loading' => trans('admin.loading'),
                'failed' => trans('admin.request_failed'),
            ];
        } else {
            $config = ['groups' => (object) $this->groups, 'selected' => $selected];
        }

        $config += ['parent' => $this->parent, 'initialParent' => $initialParent];

        return [json_encode($config, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), $options];
    }
}
