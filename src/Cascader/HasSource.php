<?php

namespace Encore\Admin\Cascader;

trait HasSource
{
    protected string $sourceClass = '';
    protected array $sourceContext = [];
    protected bool $selectBranches = false;
    protected ?string $cascaderPlaceholder = null;

    public function source(string $class, array $context = []): self
    {
        if (!is_subclass_of($class, Source::class)) {
            throw new \InvalidArgumentException('Cascader source must extend '.Source::class);
        }
        $this->sourceClass = $class;
        // Ticket context is JSON data, never a serialized PHP object or executable closure.
        $this->sourceContext = json_decode(json_encode($context, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);

        return $this;
    }

    public function checkStrictly(bool $enabled = true): self
    {
        $this->selectBranches = $enabled;

        return $this;
    }

    public function placeholder($text = ''): self
    {
        $this->cascaderPlaceholder = $text;

        return $this;
    }

    protected function cascaderSource(): Source
    {
        return Source::make($this->sourceClass, $this->sourceContext);
    }

    protected function cascaderConfig(bool $multi, int $depth, bool $locked = false): array
    {
        if (!$this->sourceClass) {
            throw new \LogicException('Configure a source() for the cascader.');
        }

        return Component::config($this->sourceClass, $this->sourceContext, $multi, $depth,
            !$this->selectBranches, $locked, $this->cascaderPlaceholder);
    }
}
