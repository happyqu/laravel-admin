<?php

namespace Encore\Admin\Form\Field;

use Illuminate\Support\Str;

trait ImageField
{
    protected $clientImageOptions = [];

    /** Compress selected images in the browser before uploading. */
    public function compress(float $quality = 0.8, int $maxWidth = 1920, int $maxHeight = 1920)
    {
        if (!is_finite($quality) || $quality <= 0 || $quality > 1 || $maxWidth < 1 || $maxHeight < 1) {
            throw new \InvalidArgumentException('Image quality must be in (0, 1] and dimensions must be positive.');
        }

        $this->clientImageOptions['compress'] = compact('quality', 'maxWidth', 'maxHeight');

        return $this;
    }

    /** Free cropping, or a fixed output size when both dimensions are supplied. */
    public function cropper(?int $width = null, ?int $height = null, array $options = [])
    {
        if (($width === null) !== ($height === null) || ($width !== null && ($width < 1 || $height < 1))) {
            throw new \InvalidArgumentException('Crop dimensions must both be null or positive integers.');
        }

        $this->clientImageOptions['cropper'] = compact('width', 'height', 'options');

        return $this;
    }

    public static function getAssets()
    {
        $assets = parent::getAssets();
        $assets['css'][] = '/vendor/laravel-admin/laravel-admin/image-editor.css?v=3';
        $assets['js'][] = '/vendor/laravel-admin/cropperjs/cropper.min.js?v=2.3.1';
        $assets['js'][] = '/vendor/laravel-admin/laravel-admin/image-editor.js?v=4';

        return $assets;
    }

    protected function setupScripts($options)
    {
        parent::setupScripts($options);

        if ($this->clientImageOptions) {
            $config = $this->clientImageOptions;
            $config['labels'] = [
                'title' => trans('admin.image_editor.title'),
                'confirm' => trans('admin.confirm'),
                'cancel' => trans('admin.cancel'),
                'processing' => trans('admin.image_editor.processing'),
                'error' => trans('admin.image_editor.error'),
                'unsupported' => trans('admin.image_editor.unsupported'),
                'zoomIn' => trans('admin.image_editor.zoom_in'),
                'zoomOut' => trans('admin.image_editor.zoom_out'),
                'move' => trans('admin.image_editor.move'),
                'select' => trans('admin.image_editor.select'),
                'reset' => trans('admin.image_editor.reset'),
            ];
            $config = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
            $this->script .= "\nLAImageEditor.bind(document.querySelectorAll('input{$this->getElementClassSelector()}'), {$config});";
        }
    }

    /** Names of historical thumbnails to remove with their original image. */
    protected $thumbnails = [];

    /**
     * Default directory for file to upload.
     *
     * @return mixed
     */
    public function defaultDirectory()
    {
        return config('admin.upload.directory.image');
    }

    /**
     * Render a image form field.
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function render()
    {
        $this->options(['allowedFileTypes' => ['image'], 'msgPlaceholder' => trans('admin.choose_image')]);

        return parent::render();
    }

    /** Register historical thumbnail names for cleanup; no new thumbnails are generated. */
    public function cleanupThumbnails(array $names)
    {
        $this->thumbnails = array_fill_keys($names, true);

        return $this;
    }

    /**
     * Destroy original thumbnail files.
     *
     * @return void.
     */
    public function destroyThumbnail()
    {
        if ($this->retainable) {
            return;
        }

        foreach ($this->thumbnails as $name => $_) {
            /*  Refactoring actual remove lofic to another method destroyThumbnailFile()
            to make deleting thumbnails work with multiple as well as
            single image upload. */

            if (is_array($this->original)) {
                if (empty($this->original)) {
                    continue;
                }

                foreach ($this->original as $original) {
                    $this->destroyThumbnailFile($original, $name);
                }
            } else {
                $this->destroyThumbnailFile($this->original, $name);
            }
        }
    }

    /**
     * Remove thumbnail file from disk.
     *
     * @return void.
     */
    public function destroyThumbnailFile($original, $name)
    {
        if (!$original) {
            return;
        }
        $ext = @pathinfo($original, PATHINFO_EXTENSION);

        // We remove extension from file name so we can append thumbnail type
        $path = @Str::replaceLast('.'.$ext, '', $original);

        // We merge original name + thumbnail name + extension
        $path = $path.'-'.$name.'.'.$ext;

        if ($this->storage->exists($path)) {
            $this->deleteStoredFile($path);
        }
    }
}
