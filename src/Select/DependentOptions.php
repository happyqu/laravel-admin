<?php

namespace Encore\Admin\Select;

use Encore\Admin\Admin;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

class DependentOptions
{
    private ?ModelOptions $source = null;
    private array $groups = [];
    private ?array $allowedParents = null;

    /**
     * @param string                $parent         Sibling select the options depend on.
     * @param array|Arrayable|string $options       Grouped local options or a model class.
     * @param string|null           $foreignKey     Child table column holding the parent key.
     * @param string                $textField      Child column used as the label.
     * @param iterable|null         $allowedParents Parent IDs the current user may query.
     */
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

        $this->groups = $this->grouped($options instanceof Arrayable ? $options->toArray() : $options);
    }

    /**
     * Normalize local options into [parentId => [[childId, label], ...]].
     *
     * @param array|iterable $options
     */
    private function grouped($options): array
    {
        if (!is_iterable($options)) {
            throw new InvalidArgumentException(
                'Dependent options must be grouped as [parentId => [childId => label]] or be a model class.'
            );
        }

        $groups = [];
        foreach ($options as $value => $group) {
            if (!is_iterable($group)) {
                throw new InvalidArgumentException(sprintf(
                    'Dependent options for parent [%s] must be an array of [childId => label]; use optionsBy(groups) instead.',
                    $value
                ));
            }

            $groups[$value] = [];
            foreach ($group as $id => $label) {
                $groups[$value][] = [(string) $id, (string) $label];
            }
        }

        return $groups;
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
        Admin::js('/vendor/laravel-admin/laravel-admin/dependent-select.js?v=3', false);
        Admin::script('$.admin.initDependentSelects();');

        $initialParent = is_string($parentValue) || is_int($parentValue) ? (string) $parentValue : '';
        $selected = self::values((array) $selected);
        $options = [];

        if ($this->source) {
            $allowedParents = $this->allowedParents ?? self::values($parentOptions());
            $queryable = $allowedParents !== [];

            // Without a queryable parent scope the child cannot follow its parent, so keep the
            // saved labels instead: an empty child would clear the column on the next save.
            if ($queryable && !in_array($initialParent, $allowedParents, true)) {
                $initialParent = '';
            }
            if ($initialParent !== '' && $selected) {
                $options = $this->source->selected($initialParent, $selected);
            }

            $config = $queryable
                ? [
                    'url'     => $this->source->url($allowedParents),
                    'loading' => trans('admin.loading'),
                    'failed'  => trans('admin.request_failed'),
                ]
                : ['static' => true, 'selected' => $selected];
        } else {
            $config = ['groups' => (object) $this->groups, 'selected' => $selected];
        }

        $config += ['parent' => $this->parent, 'initialParent' => $initialParent];

        return [json_encode($config, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), $options];
    }
}
