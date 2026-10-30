<?php

namespace Encore\Admin\Cascader;

use Encore\Admin\Admin;
use Encore\Admin\Security\ComponentSubmission;

class Component
{
    public static function config(string $source, array $context, bool $multi, int $depth, bool $leafOnly, bool $locked, ?string $placeholder): array
    {
        $payload = json_encode(['context' => $context, 'depth' => $depth, 'leafOnly' => $leafOnly], JSON_THROW_ON_ERROR);
        $url = ComponentSubmission::url('admin.cascader', ['source' => $source, 'settings' => $payload], 'cascader', $source, [$payload]);

        return [
            'url' => $url, 'multiField' => $multi, 'depth' => $depth, 'leafOnly' => $leafOnly, 'locked' => $locked,
            'placeholder' => $placeholder ?? trans('admin::cascader.choose'),
            'messages' => trans('admin::cascader'),
        ];
    }

    public static function assets(): void
    {
        $assets = \Encore\Admin\Form\Field\Cascader::getAssets();
        Admin::css($assets['css']);
        Admin::js($assets['js']);
        Admin::script('$.admin.cascader.init(document);');
    }
}
