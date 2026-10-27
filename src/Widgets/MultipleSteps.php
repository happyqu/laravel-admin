<?php

namespace Encore\Admin\Widgets;

use Illuminate\Contracts\Support\Renderable;

class MultipleSteps implements Renderable
{
    /**
     * @var int|string
     */
    protected $current;

    /**
     * @var array
     */
    protected $steps = [];

    /**
     * @var string
     */
    protected $stepName = 'step';

    /**
     * MultipleSteps constructor.
     *
     * @param array $steps
     * @param null  $current
     */
    public function __construct($steps = [], $current = null)
    {
        $this->steps = $steps;

        $this->current = $this->resolveCurrentStep($steps, $current);
    }

    /**
     * @param array $steps
     * @param null  $current
     *
     * @return static
     */
    public static function make($steps, $current = null): self
    {
        return new static($steps, $current);
    }

    /**
     * @param array      $steps
     * @param string|int $current
     *
     * @return string|int
     */
    protected function resolveCurrentStep($steps, $current)
    {
        $current = $current ?? request($this->stepName, 0);

        if ((!is_string($current) && !is_int($current)) || !isset($steps[$current])) {
            $current = key($steps);
        }

        foreach (array_keys($steps) as $key) {
            if ((string) $key === (string) $current) {
                return $key;
            }
        }
        return key($steps);
    }

    /**
     * @return string|null
     */
    public function render()
    {
        if ($this->steps === []) {
            return '';
        }
        $flow = request('_flow');
        if ($flow === null) {
            $this->current = key($this->steps);
            $flow = bin2hex(random_bytes(24));
            $flows = array_filter(session('admin.step_flows', []), static fn ($item) => $item['expires'] >= time());
            $flows = array_slice($flows, -31, null, true);
            $flows[$flow] = ['steps' => array_keys($this->steps), 'classes' => $this->steps,
                'url' => request()->url(), 'query' => \Illuminate\Support\Arr::except(request()->query(), ['step', '_flow']),
                'identity' => StepForm::flowIdentity(), 'data' => [], 'allowed' => 0, 'expires' => time() + 7200];
            session()->put('admin.step_flows', $flows);
        }
        abort_unless(is_string($flow) && preg_match('/^[a-f0-9]{48}$/D', $flow), 419);
        $state = session('admin.step_flows.'.$flow);
        $index = array_search($this->current, array_keys($this->steps), true);
        abort_unless(is_array($state) && $state['expires'] >= time()
            && $state['classes'] === $this->steps && $state['url'] === request()->url()
            && ($state['query'] ?? []) == \Illuminate\Support\Arr::except(request()->query(), ['step', '_flow'])
            && ($state['identity'] ?? null) === StepForm::flowIdentity()
            && $index !== false && $index <= $state['allowed'], 403, 'Invalid step flow.');
        $class = $this->steps[$this->current];

        if (!is_subclass_of($class, StepForm::class)) {
            admin_error("Class [{$class}] must be a sub-class of [Encore\Admin\Widgets\StepForm].");

            return;
        }

        /** @var StepForm $step */
        $step = new $class();

        return $step
            ->setFlow($flow)
            ->setSteps(array_keys($this->steps))
            ->setCurrent($this->current)
            ->render();
    }
}
