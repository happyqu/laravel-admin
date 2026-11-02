<?php

namespace Encore\Admin\Tests\Security;

use Encore\Admin\Security\UploadPolicy;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\MimeTypeGuesserInterface;
use Symfony\Component\Mime\MimeTypes;

class UploadPolicyTest extends TestCase
{
    private $previous;
    private MimeTypes $previousMimeTypes;
    private array $paths = [];

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Container();
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app->instance('config', new Repository(['admin' => ['upload' => [
            'allowed_extensions' => UploadPolicy::FILE_EXTENSIONS,
        ]]]));
        $translator = new Translator(new ArrayLoader(), 'en');
        $app->instance('translator', $translator);
        $app->instance('validator', new Factory($translator, $app));
        $this->previousMimeTypes = MimeTypes::getDefault();
        $mimeTypes = new MimeTypes();
        $mimeTypes->registerGuesser(new class implements MimeTypeGuesserInterface {
            public function isGuesserSupported(): bool { return true; }
            public function guessMimeType(string $path): ?string { return 'application/octet-stream'; }
        });
        MimeTypes::setDefault($mimeTypes);
    }

    protected function tearDown(): void
    {
        MimeTypes::setDefault($this->previousMimeTypes);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previous);
        Container::setInstance($this->previous);
        foreach ($this->paths as $path) {
            unlink($path);
        }
    }

    public function test_binary_mime_office_files_with_zip_headers_are_accepted(): void
    {
        foreach (['docx', 'xlsx', 'pptx'] as $extension) {
            $file = $this->file('261008AJ0199-多乐士小程序2026年10月度运维.'.$extension, $this->zipBytes());
            (new UploadPolicy())->assertDestination($file, $file->getClientOriginalName(), 'upload/files/202611', false, 'attachment');
            $this->addToAssertionCount(1);
        }
    }

    public function test_binary_data_renamed_to_xlsx_is_rejected(): void
    {
        $file = $this->file('test.xlsx', 'not a ZIP archive');
        $this->expectException(ValidationException::class);
        (new UploadPolicy())->assertFile($file, false, 'attachment');
    }

    public function test_binary_mime_fallback_is_limited_to_office_extensions(): void
    {
        $file = $this->file('test.pdf', $this->zipBytes());
        $this->expectException(ValidationException::class);
        (new UploadPolicy())->assertFile($file, false, 'attachment');
    }

    public function test_office_file_does_not_bypass_extension_configuration(): void
    {
        config(['admin.upload.allowed_extensions' => ['pdf']]);
        $file = $this->file('test.xlsx', $this->zipBytes());
        $this->expectException(ValidationException::class);
        (new UploadPolicy())->assertFile($file, false, 'attachment');
    }

    public function test_office_file_does_not_bypass_destination_validation(): void
    {
        $file = $this->file('test.xlsx', $this->zipBytes());
        $this->expectException(ValidationException::class);
        (new UploadPolicy())->assertDestination($file, 'test.xlsx', '../files', false, 'attachment');
    }

    private function zipBytes(): string
    {
        // A ZIP containing test.txt; no ext-zip dependency for this fixture.
        return base64_decode('UEsDBBQAAAAAAMJbYl2GphA2BQAAAAUAAAAIAAAAdGVzdC50eHRoZWxsb1BLAQI/AxQAAAAAAMJbYl2GphA2BQAAAAUAAAAIAAAAAAAAAAAAAAC2gQAAAAB0ZXN0LnR4dFBLBQYAAAAAAQABADYAAAArAAAAAAA=');
    }

    private function file(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload-policy-');
        $this->paths[] = $path;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }
}
