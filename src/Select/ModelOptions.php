<?php

namespace Encore\Admin\Select;

use Encore\Admin\Security\ComponentSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ModelOptions
{
    public const KIND = 'select-options';

    public function __construct(private string $model, private string $foreignKey, private string $textField)
    {
        if (!is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException("[$model] must be a valid model class");
        }
    }

    public function url(array $allowedParents): string
    {
        $settings = [$this->model, $this->foreignKey, $this->textField];

        return ComponentSubmission::url('admin.select-options', [], self::KIND, self::class, [$settings, $allowedParents]);
    }

    public function options(string $parent): array
    {
        $query = $this->query($parent);
        $model = $query->getModel();
        $text = $model->qualifyColumn($this->textField);

        return $query->orderBy($text)->get([$model->getQualifiedKeyName(), $text])->map(fn (Model $row) => [
            'id' => (string) $row->getKey(),
            'text' => (string) $row->getAttribute($this->textField),
        ])->all();
    }

    public function selected(string $parent, array $ids): array
    {
        $query = $this->query($parent);
        $model = $query->getModel();
        $key = $model->getQualifiedKeyName();
        $text = $model->qualifyColumn($this->textField);

        return $query->whereIn($key, $ids)->get([$key, $text])
            ->mapWithKeys(fn (Model $row) => [(string) $row->getKey() => (string) $row->getAttribute($this->textField)])->all();
    }

    private function query(string $parent): Builder
    {
        $query = $this->model::query();

        return $query->where($query->getModel()->qualifyColumn($this->foreignKey), $parent);
    }
}
