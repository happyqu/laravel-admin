<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Form;
use Encore\Admin\Security\UploadPolicy;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\File\UploadedFile;

trait UploadField
{
    /**
     * Upload directory.
     *
     * @var string
     */
    protected $directory = '';

    /**
     * File name.
     *
     * @var null
     */
    protected $name = null;

    /**
     * Storage instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $storage = '';

    /**
     * If use unique name to store upload file.
     *
     * @var bool
     */
    protected $useUniqueName = false;

    /**
     * If use sequence name to store upload file.
     *
     * @var bool
     */
    protected $useSequenceName = false;

    /**
     * Retain file when delete record from DB.
     *
     * @var bool
     */
    protected $retainable = false;

    /**
     * @var bool
     */
    protected $downloadable = true;

    /**
     * Configuration for setting up file actions for newly selected file thumbnails in the preview window.
     *
     * @var array
     */
    protected $fileActionSettings = [
        'showRemove' => false,
        'showDrag'   => false,
    ];

    /**
     * Controls the storage permission. Could be 'private' or 'public'.
     *
     * @var string
     */
    protected $storagePermission;

    /**
     * @var array
     */
    protected $fileTypes = [
        'image'  => '/^(gif|png|jpe?g|svg|webp)$/i',
        'html'   => '/^(htm|html)$/i',
        'office' => '/^(docx?|xlsx?|pptx?|pps|potx?)$/i',
        'gdocs'  => '/^(docx?|xlsx?|pptx?|pps|potx?|rtf|ods|odt|pages|ai|dxf|ttf|tiff?|wmf|e?ps)$/i',
        'text'   => '/^(txt|md|csv|nfo|ini|json|php|js|css|ts|sql)$/i',
        'video'  => '/^(og?|mp4|webm|mp?g|mov|3gp)$/i',
        'audio'  => '/^(og?|mp3|mp?g|wav)$/i',
        'pdf'    => '/^(pdf)$/i',
        'flash'  => '/^(swf)$/i',
    ];

    /**
     * @var string
     */
    protected $pathColumn;

    /**
     * Initialize the storage instance.
     *
     * @return void.
     */
    protected function initStorage()
    {
        $this->disk(config('admin.upload.disk'));
    }

    /**
     * Set default options form image field.
     *
     * @return void
     */
    protected function setupDefaultOptions()
    {
        $defaults = [
            'overwriteInitial'     => false,
            'initialPreviewAsData' => true,
            'msgPlaceholder'       => trans('admin.choose_file'),
            'browseLabel'          => trans('admin.browse'),
            'cancelLabel'          => trans('admin.cancel'),
            'showRemove'           => false,
            'showUpload'           => false,
            'showCancel'           => false,
            'dropZoneEnabled'      => false,
            'deleteExtraData'      => [
                $this->formatName($this->column) => static::FILE_DELETE_FLAG,
                static::FILE_DELETE_FLAG         => '',
                '_token'                         => csrf_token(),
                '_method'                        => 'PUT',
            ],
        ];

        if ($this->form instanceof Form) {
            $defaults['deleteUrl'] = $this->form->resource().'/'.$this->form->model()->getKey();
        }

        $defaults = array_merge($defaults, ['fileActionSettings' => $this->fileActionSettings]);

        $this->options($defaults);
    }

    /**
     * Set preview options form image field.
     *
     * @return void
     */
    protected function setupPreviewOptions()
    {
        $initialPreviewConfig = $this->initialPreviewConfig();

        $this->options(compact('initialPreviewConfig'));
    }

    /**
     * @return array|bool
     */
    protected function guessPreviewType($file)
    {
        $filetype = 'other';
        $ext = strtok(strtolower(pathinfo($file, PATHINFO_EXTENSION)), '?');

        foreach ($this->fileTypes as $type => $pattern) {
            if (preg_match($pattern, $ext) === 1) {
                $filetype = $type;
                break;
            }
        }

        $extra = ['type' => $filetype];

        if ($filetype == 'video') {
            $extra['filetype'] = "video/{$ext}";
        }

        if ($filetype == 'audio') {
            $extra['filetype'] = "audio/{$ext}";
        }

        if ($this->downloadable) {
            $extra['downloadUrl'] = $this->objectUrl($file);
        }

        return $extra;
    }

    /**
     * Indicates if the underlying field is downloadable.
     *
     * @param bool $downloadable
     *
     * @return $this
     */
    public function downloadable($downloadable = true)
    {
        $this->downloadable = $downloadable;

        return $this;
    }

    /**
     * Allow use to remove file.
     *
     * @return $this
     */
    public function removable()
    {
        $this->fileActionSettings['showRemove'] = true;

        return $this;
    }

    /**
     * Indicates if the underlying field is retainable.
     *
     * @return $this
     */
    public function retainable($retainable = true)
    {
        $this->retainable = $retainable;

        return $this;
    }

    /**
     * Set options for file-upload plugin.
     *
     * @param array $options
     *
     * @return $this
     */
    public function options($options = [])
    {
        $this->options = array_merge($options, $this->options);

        return $this;
    }

    /**
     * Set disk for storage.
     *
     * @param string $disk Disks defined in `config/filesystems.php`.
     *
     * @throws \Exception
     *
     * @return $this
     */
    public function disk($disk)
    {
        try {
            $this->storage = Storage::disk($disk);
        } catch (\Exception $exception) {
            if (!array_key_exists($disk, config('filesystems.disks'))) {
                admin_error(
                    'Config error.',
                    "Disk [$disk] not configured, please add a disk config in `config/filesystems.php`."
                );

                return $this;
            }

            throw $exception;
        }

        return $this;
    }

    /**
     * Specify the directory and name for upload file.
     *
     * @param string      $directory
     * @param null|string $name
     *
     * @return $this
     */
    public function move($directory, $name = null)
    {
        $this->dir($directory);

        $this->name($name);

        return $this;
    }

    /**
     * Specify the directory upload file.
     *
     * @param string $dir
     *
     * @return $this
     */
    public function dir($dir)
    {
        if ($dir) {
            $this->directory = $dir;
        }

        return $this;
    }

    /**
     * Set name of store name.
     *
     * @param string|callable $name
     *
     * @return $this
     */
    public function name($name)
    {
        if ($name) {
            $this->name = $name;
        }

        return $this;
    }

    /**
     * Use unique name for store upload file.
     *
     * @return $this
     */
    public function uniqueName()
    {
        $this->useUniqueName = true;

        return $this;
    }

    /**
     * Use sequence name for store upload file.
     *
     * @return $this
     */
    public function sequenceName()
    {
        $this->useSequenceName = true;

        return $this;
    }

    /**
     * Get store name of upload file.
     *
     * @param UploadedFile $file
     *
     * @return string
     */
    protected function getStoreName(UploadedFile $file)
    {
        $this->validateUpload($file);

        if ($this->useUniqueName) {
            $name = $this->generateUniqueName($file);
        } elseif ($this->useSequenceName) {
            $name = $this->generateSequenceName($file);
        } elseif ($this->name instanceof \Closure) {
            $name = $this->name->call($this, $file);
        } elseif (is_string($this->name)) {
            $name = $this->name;
        } else {
            $name = $file->getClientOriginalName();
        }

        $this->validateUploadDestination($file, $name);

        return $name;
    }

    protected function validateUpload(UploadedFile $file)
    {
        (new UploadPolicy())->assertFile($file, $this instanceof Image || $this instanceof MultipleImage, $this->column);
    }

    protected function validateUploadDestination(UploadedFile $file, $name, $directory = null)
    {
        (new UploadPolicy())->assertDestination($file, (string) $name, (string) ($directory ?? $this->getDirectory()), $this instanceof Image || $this instanceof MultipleImage, $this->column);
    }

    protected function uploadValidationRule()
    {
        return function ($attribute, $value, $fail) {
            if (!$value instanceof UploadedFile) {
                $fail(trans('admin.upload_invalid'));
                return;
            }
            try {
                $this->validateUpload($value);
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $fail(trans('admin.upload_invalid'));
            }
        };
    }

    /**
     * Get directory for store file.
     *
     * @return mixed|string
     */
    public function getDirectory()
    {
        if ($this->directory instanceof \Closure) {
            return call_user_func($this->directory, $this->form);
        }

        return $this->directory ?: $this->defaultDirectory();
    }

    /**
     * Set path column in has-many related model.
     *
     * @param string $column
     *
     * @return $this
     */
    public function pathColumn($column = 'path')
    {
        $this->pathColumn = $column;

        return $this;
    }

    /**
     * Upload file and delete original file.
     *
     * @param UploadedFile $file
     *
     * @return mixed
     */
    protected function upload(UploadedFile $file)
    {
        $directory = (string) $this->getDirectory();
        $this->validateUploadDestination($file, $this->name, $directory);
        $this->renameIfExists($file);
        $this->validateUploadDestination($file, $this->name, $directory);

        app(\Encore\Admin\Form\UploadTransaction::class)->uploaded($this->storage, trim($directory.'/'.$this->name, '/'));

        if (!is_null($this->storagePermission)) {
            $path = $this->storage->putFileAs($directory, $file, $this->name, $this->storagePermission);
        } else {
            $path = $this->storage->putFileAs($directory, $file, $this->name);
        }
        if ($path === false) {
            throw new \RuntimeException('Unable to store uploaded file.');
        }
        return $path;
    }

    /**
     * If name already exists, rename it.
     *
     * @param $file
     *
     * @return void
     */
    public function renameIfExists(UploadedFile $file)
    {
        if ($this->storage->exists("{$this->getDirectory()}/$this->name")) {
            $this->name = $this->generateUniqueName($file);
        }
    }

    /**
     * Get file visit url.
     *
     * @param $path
     *
     * @return string
     */
    public function objectUrl($path)
    {
        if ($this->pathColumn && is_array($path)) {
            $path = Arr::get($path, $this->pathColumn);
        }

        if (URL::isValidUrl($path)) {
            return $path;
        }

        if ($this->storage) {
            return $this->storage->url($path);
        }

        return Storage::disk(config('admin.upload.disk'))->url($path);
    }

    /**
     * Generate a unique name for uploaded file.
     *
     * @param UploadedFile $file
     *
     * @return string
     */
    protected function generateUniqueName(UploadedFile $file)
    {
        return bin2hex(random_bytes(16)).'.'.strtolower($file->getClientOriginalExtension());
    }

    /**
     * Generate a sequence name for uploaded file.
     *
     * @param UploadedFile $file
     *
     * @return string
     */
    protected function generateSequenceName(UploadedFile $file)
    {
        $index = 1;
        $extension = $file->getClientOriginalExtension();
        $original = $file->getClientOriginalName();
        $new = sprintf('%s_%s.%s', $original, $index, $extension);

        while ($this->storage->exists("{$this->getDirectory()}/$new")) {
            $index++;
            $new = sprintf('%s_%s.%s', $original, $index, $extension);
        }

        return $new;
    }

    /**
     * Destroy original files.
     *
     * @return void.
     */
    public function destroy()
    {
        if ($this->retainable) {
            return;
        }

        if (method_exists($this, 'destroyThumbnail')) {
            $this->destroyThumbnail();
        }

        if (!empty($this->original) && $this->storage->exists($this->original)) {
            $this->deleteStoredFile($this->original);
        }
    }

    protected function isDeleteRequest($value): bool
    {
        return request()->has(static::FILE_DELETE_FLAG) && $value === static::FILE_DELETE_FLAG;
    }

    protected function deleteStoredFile(?string $path): void
    {
        app(\Encore\Admin\Form\UploadTransaction::class)->delete($this->storage, $path);
    }

    /**
     * Set file permission when stored into storage.
     *
     * @param string $permission
     *
     * @return $this
     */
    public function storagePermission($permission)
    {
        $this->storagePermission = $permission;

        return $this;
    }
}
