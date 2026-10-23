<?php

namespace Encore\Admin\Security;

use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\MimeTypes;

/** Server-side checks shared by all built-in upload fields. */
class UploadPolicy
{
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    public const FILE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf', 'txt', 'csv',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', '7z',
        'mp3', 'wav', 'ogg', 'mp4', 'webm', 'mov',
    ];

    public function assertFile(UploadedFile $file, bool $image, string $attribute): void
    {
        if (!$file->isValid()) {
            $this->reject($attribute);
        }

        $extension = $this->extension($file->getClientOriginalName(), $image, $attribute);
        $this->assertMime($file, $extension, $image, $attribute);
    }

    public function assertDestination(UploadedFile $file, string $name, string $directory, bool $image, string $attribute): void
    {
        $this->assertFile($file, $image, $attribute);
        $extension = $this->extension($name, $image, $attribute);
        $this->assertMime($file, $extension, $image, $attribute);

        // Disks, including remote disks, must receive a relative directory.
        if (preg_match('~[\\\\:\x00-\x1f\x7f%?#;<>|"*]~', $directory)
            || str_starts_with($directory, '/')) {
            $this->reject($attribute);
        }
        foreach (explode('/', $directory) as $segment) {
            if ($segment === '..' || $segment === '.' || ($segment !== '' && rtrim($segment, '. ') !== $segment)) {
                $this->reject($attribute);
            }
        }
    }

    private function extension(string $name, bool $image, string $attribute): string
    {
        if ($name === '' || str_starts_with($name, '.') || rtrim($name, '. ') !== $name
            || str_contains($name, '..') || preg_match('~[/\\\\:\x00-\x1f\x7f%?#;<>|"*]~', $name)) {
            $this->reject($attribute);
        }

        // Check every suffix, including executable suffixes hidden before an image suffix.
        foreach (array_slice(explode('.', strtolower($name)), 1) as $part) {
            if (rtrim($part, ' ') !== $part || preg_match('/^(?:php[a-z0-9_-]*|phtml|phtm|pht|phar|shtml|shtm|asp|aspx|asa|cer|cdx|jsp|jspx|cgi|fcgi|pl|py|pyw|rb|sh|bash|exe|dll|com|bat|cmd|ps1|html|htm|xhtml|svg|svgz|js|mjs|cjs|swf|htaccess|ini|config)$/', $part)) {
                $this->reject($attribute);
            }
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = $image ? self::IMAGE_EXTENSIONS : config('admin.upload.allowed_extensions', self::FILE_EXTENSIONS);
        if (!is_array($allowed) || !in_array($extension, array_map('strtolower', $allowed), true)) {
            $this->reject($attribute);
        }

        return $extension;
    }

    private function assertMime(UploadedFile $file, string $extension, bool $image, string $attribute): void
    {
        // Never trust the multipart Content-Type or the client's guessed extension.
        $mime = MimeTypes::getDefault()->guessMimeType($file->getPathname());
        $allowed = MimeTypes::getDefault()->getMimeTypes($extension);
        if ($extension === 'csv') {
            $allowed[] = 'text/plain';
        }
        if (in_array($extension, ['docx', 'xlsx', 'pptx'], true)) {
            $allowed[] = 'application/zip';
        }
        if (!$mime || !in_array($mime, $allowed, true)) {
            $this->reject($attribute);
        }

        if ($image || in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            $info = @getimagesize($file->getPathname());
            if (!$info || !isset($info['mime']) || !in_array($info['mime'], $allowed, true)) {
                $this->reject($attribute);
            }
        }
    }

    private function reject(string $attribute): void
    {
        throw ValidationException::withMessages([
            $attribute => trans('admin.upload_invalid'),
        ]);
    }
}
