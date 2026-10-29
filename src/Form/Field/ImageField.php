<?php

namespace Encore\Admin\Form\Field;

use Illuminate\Support\Str;
use Intervention\Image\Constraint;
use Intervention\Image\Facades\Image as InterventionImage;
use Intervention\Image\ImageManagerStatic;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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

    /**
     * Intervention calls.
     *
     * @var array
     */
    protected $interventionCalls = [];

    /**
     * Thumbnail settings.
     *
     * @var array
     */
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
     * Execute Intervention calls.
     *
     * @param string $target
     *
     * @return mixed
     */
    public function callInterventionMethods($target)
    {
        if (!empty($this->interventionCalls)) {
            $this->ensureIntervention();

            $image = ImageManagerStatic::make($target);

            foreach ($this->interventionCalls as $call) {
                call_user_func_array(
                    [$image, $call['method']],
                    $call['arguments']
                )->save($target);
            }
        }

        return $target;
    }

    /**
     * Fail clearly when optional image processing is requested but unavailable.
     */
    protected function ensureIntervention(): void
    {
        if (!class_exists(ImageManagerStatic::class)) {
            throw new \RuntimeException('To use image handling and manipulation, install [intervention/image:^2.7].');
        }
    }

    /**
     * Call intervention methods.
     *
     * @param string $method
     * @param array  $arguments
     *
     * @throws \Exception
     *
     * @return $this
     */
    public function __call($method, $arguments)
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $arguments);
        }

        if (!class_exists(ImageManagerStatic::class)) {
            throw new \Exception('To use image handling and manipulation, please install [intervention/image] first.');
        }

        $this->interventionCalls[] = [
            'method'    => $method,
            'arguments' => $arguments,
        ];

        return $this;
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

    /**
     * @param string|array $name
     * @param int          $width
     * @param int          $height
     *
     * @return $this
     */
    public function thumbnail($name, ?int $width = null, ?int $height = null)
    {
        if (func_num_args() == 1 && is_array($name)) {
            foreach ($name as $key => $size) {
                if (count($size) >= 2) {
                    $this->thumbnails[$key] = $size;
                }
            }
        } elseif (func_num_args() == 3) {
            $this->thumbnails[$name] = [$width, $height];
        }

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

    /**
     * Upload file and delete original thumbnail files.
     *
     * @param UploadedFile $file
     *
     * @return $this
     */
    protected function uploadAndDeleteOriginalThumbnail(UploadedFile $file)
    {
        if (empty($this->thumbnails)) {
            return $this;
        }

        $this->ensureIntervention();

        $directory = (string) $this->getDirectory();
        foreach ($this->thumbnails as $name => $size) {
            // We need to get extension type ( .jpeg , .png ...)
            $ext = pathinfo($this->name, PATHINFO_EXTENSION);

            // We remove extension from file name so we can append thumbnail type
            $path = Str::replaceLast('.'.$ext, '', $this->name);

            // We merge original name + thumbnail name + extension
            $path = $path.'-'.$name.'.'.$ext;

            $this->validateUploadDestination($file, $path, $directory);
            app(\Encore\Admin\Form\UploadTransaction::class)->uploaded($this->storage, "{$directory}/{$path}");

            /** @var \Intervention\Image\Image $image */
            $image = InterventionImage::make($file);

            $action = $size[2] ?? 'resize';
            // Resize image with aspect ratio
            $image->$action($size[0], $size[1], function (Constraint $constraint) {
                $constraint->aspectRatio();
            })->resizeCanvas($size[0], $size[1], 'center', false, '#ffffff');

            if (!is_null($this->storagePermission)) {
                $stored = $this->storage->put("{$directory}/{$path}", $image->encode(), $this->storagePermission);
            } else {
                $stored = $this->storage->put("{$directory}/{$path}", $image->encode());
            }
            if ($stored === false) {
                throw new \RuntimeException('Unable to store image thumbnail.');
            }
        }

        return $this;
    }
}
