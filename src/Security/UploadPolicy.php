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

    /**
     * Formats that are only ever parsed as text. libmagic labels many of them as
     * application/octet-stream once they contain GBK/UTF-16 characters, which is
     * exactly what Excel exports in Chinese environments look like.
     */
    public const TEXT_EXTENSIONS = ['csv', 'txt', 'tsv', 'md'];

    private const TEXT_MIME_ALIASES = [
        'application/csv',
        'application/x-csv',
        'application/vnd.ms-excel',
        'application/octet-stream',
    ];

    private const ACTIVE_TEXT_MIME = [
        'text/html',
        'text/xml',
        'text/xsl',
        'text/javascript',
        'text/ecmascript',
        'text/x-php',
    ];

    public function assertFile(UploadedFile $file, bool $image, string $attribute): void
    {
        if (!$file->isValid()) {
            $this->reject($attribute, 'admin.upload_incomplete');
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
            $this->reject($attribute, 'admin.upload_invalid_path');
        }
        foreach (explode('/', $directory) as $segment) {
            if ($segment === '..' || $segment === '.' || ($segment !== '' && rtrim($segment, '. ') !== $segment)) {
                $this->reject($attribute, 'admin.upload_invalid_path');
            }
        }
    }

    private function extension(string $name, bool $image, string $attribute): string
    {
        if ($name === '' || str_starts_with($name, '.') || rtrim($name, '. ') !== $name
            || str_contains($name, '..') || preg_match('~[/\\\\:\x00-\x1f\x7f%?#;<>|"*]~', $name)) {
            $this->reject($attribute, 'admin.upload_invalid_name');
        }

        // Check every suffix, including executable suffixes hidden before an image suffix.
        foreach (array_slice(explode('.', strtolower($name)), 1) as $part) {
            if (rtrim($part, ' ') !== $part || preg_match('/^(?:php[a-z0-9_-]*|phtml|phtm|pht|phar|shtml|shtm|asp|aspx|asa|cer|cdx|jsp|jspx|cgi|fcgi|pl|py|pyw|rb|sh|bash|exe|dll|com|bat|cmd|ps1|html|htm|xhtml|svg|svgz|js|mjs|cjs|swf|htaccess|ini|config)$/', $part)) {
                $this->reject($attribute, 'admin.upload_invalid_type');
            }
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = $image ? self::IMAGE_EXTENSIONS : config('admin.upload.allowed_extensions', self::FILE_EXTENSIONS);
        if (!is_array($allowed) || !in_array($extension, array_map('strtolower', $allowed), true)) {
            $this->reject($attribute, 'admin.upload_invalid_type', [
                'allowed' => implode('、', array_map('strtoupper', is_array($allowed) ? $allowed : [])),
            ]);
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

            // fileinfo may report Office ZIP files as generic binary data.
            if ($mime === 'application/octet-stream'
                && file_get_contents($file->getPathname(), false, null, 0, 4) === "PK\x03\x04") {
                $mime = 'application/zip';
            }
        }
        if (!in_array($mime, $allowed, true) && !$this->isTextMime($extension, $mime)) {
            $this->reject($attribute, 'admin.upload_invalid_content');
        }

        if ($image || in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            $info = @getimagesize($file->getPathname());
            if (!$info || !isset($info['mime']) || !in_array($info['mime'], $allowed, true)) {
                $this->reject($attribute, 'admin.upload_invalid_content');
            }
        }
    }

    /**
     * Plain-text formats are parsed as text only, so a charset-dependent MIME guess
     * must not block them. Active content is still rejected by the filename check.
     */
    private function isTextMime(string $extension, ?string $mime): bool
    {
        if (!in_array($extension, self::TEXT_EXTENSIONS, true)) {
            return false;
        }

        if ($mime === null || in_array($mime, self::TEXT_MIME_ALIASES, true)) {
            return true;
        }

        return str_starts_with($mime, 'text/') && !in_array($mime, self::ACTIVE_TEXT_MIME, true);
    }

    private function reject(string $attribute, string $message = 'admin.upload_invalid', array $replace = []): void
    {
        throw ValidationException::withMessages([
            $attribute => trans($message, $replace),
        ]);
    }
}
