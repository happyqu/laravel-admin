<?php

namespace Encore\Admin\Support;

use Illuminate\Support\Arr;

trait HasSwitchStates
{
    protected $states = [
        'on'  => ['value' => 1, 'text' => 'ON', 'color' => 'primary'],
        'off' => ['value' => 0, 'text' => 'OFF', 'color' => 'default'],
    ];

    /**
     * Merge state options without resetting unspecified values.
     *
     * @return $this
     */
    public function states($states = [])
    {
        foreach (Arr::dot($states) as $key => $state) {
            Arr::set($this->states, $key, $state);
        }

        return $this;
    }

    /**
     * Set the labels in on/off order.
     *
     * @return $this
     */
    public function texts($on, $off)
    {
        return $this->states(['on' => ['text' => $on], 'off' => ['text' => $off]]);
    }

    /**
     * Set Bootstrap Switch colors in on/off order.
     *
     * @return $this
     */
    public function colors($on, $off)
    {
        return $this->states(['on' => ['color' => $on], 'off' => ['color' => $off]]);
    }

    /**
     * Set stored values in on/off order.
     *
     * @return $this
     */
    public function values($on, $off)
    {
        return $this->states(['on' => ['value' => $on], 'off' => ['value' => $off]]);
    }

    protected function switchState($value)
    {
        $comparable = is_bool($value) ? (int) $value : $value;
        foreach (['on', 'off'] as $state) {
            $stored = $this->states[$state]['value'];
            $stored = is_bool($stored) ? (int) $stored : $stored;
            if ($value === $stored || (is_scalar($value) && is_scalar($stored)
                && (string) $comparable === (string) $stored)
                || (is_numeric($comparable) && is_numeric($stored) && $comparable == $stored)) {
                return $state;
            }
        }

        return in_array($value, ['on', 'off'], true) ? $value : 'off';
    }
}
