<?php

namespace Encore\Admin\Tests\Form;

use Encore\Admin\Form\Field\Image;
use Encore\Admin\Form\Field\MultipleImage;
use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

class ClientImageOptionsTest extends TestCase
{
    private $previous;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Container();
        Container::setInstance($app);
        $app->instance('config', new Repository());
        $app->instance('translator', new Translator(new ArrayLoader(), 'en'));
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
    }

    private function fields(): array
    {
        return [
            new class('photo') extends Image {
                protected function initStorage() {}
                public function script() { $this->setupScripts('{}'); return $this->script; }
            },
            new class('photos') extends MultipleImage {
                protected function initStorage() {}
                public function script() { $this->setupScripts('{}'); return $this->script; }
            },
        ];
    }

    public function test_single_and_multiple_images_emit_client_options_without_intervention(): void
    {
        foreach ($this->fields() as $field) {
            $this->assertStringNotContainsString('LAImageEditor', $field->script());
            $this->assertSame($field, $field->compress(0.75, 1600, 1200)->cropper(400, 300));
            $script = $field->script();
            $this->assertStringContainsString('LAImageEditor.bind', $script);
            $this->assertStringContainsString('"quality":0.75,"maxWidth":1600,"maxHeight":1200', $script);
            $this->assertStringContainsString('"width":400,"height":300', $script);
            $this->assertContains('/vendor/laravel-admin/cropperjs/cropper.min.js?v=2.3.1', $field::getAssets()['js']);
        }
    }

    public function test_invalid_processing_options_fail_early(): void
    {
        $field = $this->fields()[0];
        foreach ([
            fn () => $field->compress(0),
            fn () => $field->compress(NAN),
            fn () => $field->compress(1.1),
            fn () => $field->compress(0.8, 0),
            fn () => $field->cropper(100),
            fn () => $field->cropper(0, 100),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('Invalid options were accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }
}
