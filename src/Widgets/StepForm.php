<?php

namespace Encore\Admin\Widgets;

class StepForm extends Form
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
    protected $url;

    /**
     * @var array
     */
    protected $buttons = [];

    protected ?string $flow = null;

    public function setFlow(string $flow)
    {
        $this->flow = $flow;
        return $this;
    }

    public function submissionContext(): array
    {
        return ['flow' => $this->flow, 'current' => $this->current];
    }

    protected function flowKey(): string
    {
        abort_unless($this->flow && preg_match('/^[a-f0-9]{48}$/D', $this->flow), 419, 'Invalid step flow.');
        return 'admin.step_flows.'.$this->flow;
    }

    /**
     * @param array $data
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    protected function next($data = [])
    {
        $this->remember($data);

        return $this->redirectToNextStep();
    }

    protected function prev()
    {
        return back()->withInput();
    }

    /**
     * @param array $data
     */
    protected function remember($data)
    {
        $flow = session($this->flowKey());
        $index = array_search($this->current, $flow['steps'], true);
        abort_if($index === false || $index > $flow['allowed'], 403);
        foreach (array_slice($flow['steps'], $index) as $step) {
            unset($flow['data'][$step]);
        }
        $flow['data'][$this->current] = $data;
        $flow['allowed'] = min($index + 1, count($flow['steps']) - 1);
        session()->put($this->flowKey(), $flow);
    }

    /**
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    protected function redirectToNextStep()
    {
        $index = array_search($this->current, $this->steps, true);
        abort_if($index === false || !isset($this->steps[$index + 1]), 422, 'No next step.');
        $step = $this->steps[$index + 1];
        $nextUrl = $this->url.'?'.http_build_query(['step' => $step, '_flow' => $this->flow]);

        return redirect($nextUrl);
    }

    /**
     * Get all data from steps.
     *
     * @return array
     */
    protected function all()
    {
        $prev = session($this->flowKey().'.data', []);

        return array_replace($prev, [$this->current => request()->all()]);
    }

    /**
     * Clear all data from steps.
     */
    protected function clear()
    {
        session()->forget($this->flowKey());
    }

    /**
     * @param array $steps
     *
     * @return $this
     */
    public function setSteps($steps)
    {
        $this->steps = $steps;

        return $this;
    }

    /**
     * @param string|int $current
     *
     * @return $this
     */
    public function setCurrent($current)
    {
        $this->current = $current;

        return $this;
    }

    /**
     * @param string $url
     *
     * @return $this
     */
    public function setUrl($url)
    {
        $this->url = $url;

        return $this;
    }

    protected function prepareForm()
    {
        parent::prepareForm();
        if ($this->flow === null) {
            abort_unless($this->steps && $this->current === reset($this->steps), 403, 'Start at the first step.');
            $this->flow = bin2hex(random_bytes(24));
            session()->put($this->flowKey(), [
                'steps' => $this->steps, 'classes' => [$this->current => static::class],
                'url' => request()->url(), 'data' => [], 'allowed' => 0, 'expires' => time() + 7200,
            ]);
        }

        $this->divider();

        $this->addFooter();
    }

    protected function addFooter()
    {
        $footer = '';

        $index = array_search($this->current, $this->steps);

        $trans = [
            'prev'   => __('admin.prev'),
            'next'   => __('admin.next'),
            'submit' => __('admin.submit'),
        ];

        if ($index !== 0) {
            $step = $this->steps[$index - 1];
            $prevUrl = request()->fullUrlWithQuery(['step' => $step, '_flow' => $this->flow]);
            $footer .= "<a href=\"{$prevUrl}\" class=\"btn btn-warning pull-left\">{$trans['prev']}</a>";
        }

        if ($index !== count($this->steps) - 1) {
            $footer .= "<button class=\"btn btn-info pull-right\">{$trans['next']}</button>";
        }

        if ($index === count($this->steps) - 1) {
            $footer .= "<button class=\"btn btn-info pull-right\">{$trans['submit']}</button>";
        }

        $this->html($footer);
    }

    /** Restore verified flow state before authorization and validation. */
    public function restoreSubmissionContext(array $context): void
    {
        $this->setFlow($context['flow'] ?? '');
        $flow = session($this->flowKey());
        $current = $context['current'] ?? null;
        $index = is_array($flow) ? array_search($current, $flow['steps'], true) : false;
        abort_unless($index !== false && $flow['expires'] >= time() && $index <= $flow['allowed']
            && ($flow['classes'][$current] ?? null) === static::class, 403, 'Invalid step.');
        foreach (array_slice($flow['steps'], 0, $index) as $previous) {
            abort_unless(array_key_exists($previous, $flow['data']), 403, 'Complete previous steps first.');
        }
        $this->setUrl($flow['url'])->setCurrent($current)->setSteps($flow['steps']);
    }

    /**
     * @return mixed
     */
    public function data()
    {
        return $this->flow ? session($this->flowKey().'.data.'.$this->current, []) : [];
    }
}
