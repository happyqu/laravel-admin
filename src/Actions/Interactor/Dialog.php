<?php

namespace Encore\Admin\Actions\Interactor;

use Encore\Admin\Admin;

class Dialog extends Interactor
{
    /**
     * @var bool
     */
    protected $uploadFile = false;

    /**
     * @var array
     */
    protected $settings;

    /**
     * @param string $title
     * @param string $text
     * @param array  $options
     *
     * @return Dialog
     */
    public function success($title, $text = '', $options = [])
    {
        return $this->addSettings($title, __FUNCTION__, $text, $options);
    }

    /**
     * @param string $title
     * @param string $text
     * @param array  $options
     *
     * @return Dialog
     */
    public function error($title, $text = '', $options = [])
    {
        return $this->addSettings($title, __FUNCTION__, $text, $options);
    }

    /**
     * @param string $title
     * @param string $text
     * @param array  $options
     *
     * @return $this
     */
    public function warning($title, $text = '', $options = [])
    {
        return $this->addSettings($title, __FUNCTION__, $text, $options);
    }

    /**
     * @param string $title
     * @param string $text
     * @param array  $options
     *
     * @return Dialog
     */
    public function info($title, $text = '', $options = [])
    {
        return $this->addSettings($title, __FUNCTION__, $text, $options);
    }

    /**
     * @param string $title
     * @param string $text
     * @param array  $options
     *
     * @return Dialog
     */
    public function question($title, $text = '', $options = [])
    {
        return $this->addSettings($title, __FUNCTION__, $text, $options);
    }

    /**
     * @param string $title
     * @param string $text
     * @param array  $options
     *
     * @return Dialog
     */
    public function confirm($title, $text = '', $options = [])
    {
        return $this->addSettings($title, 'question', $text, $options);
    }

    /**
     * @param string $title
     * @param string $type
     * @param string $text
     * @param array  $options
     *
     * @return $this
     */
    protected function addSettings($title, $type, $text = '', $options = [])
    {
        $this->settings = array_merge(
            compact('title', 'text', 'type'),
            $options
        );

        return $this;
    }

    /**
     * @return array
     */
    protected function defaultSettings()
    {
        $trans = [
            'cancel' => trans('admin.cancel'),
            'submit' => trans('admin.submit'),
        ];

        return [
            'type'                => 'question',
            'showCancelButton'    => true,
            'showLoaderOnConfirm' => true,
            'confirmButtonText'   => $trans['submit'],
            'cancelButtonText'    => $trans['cancel'],
        ];
    }

    public function settings(): array
    {
        $this->settings = [];
        $this->action->dialog();
        return array_merge($this->defaultSettings(), $this->settings);
    }

    public function addScript()
    {
    }
}
