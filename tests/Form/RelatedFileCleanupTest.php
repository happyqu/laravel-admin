<?php

namespace Encore\Admin\Tests\Form;

use Encore\Admin\Form;
use Encore\Admin\Form\Field\MultipleImage;
use Encore\Admin\Form\UploadTransaction;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

class RelatedFileCleanupTest extends TestCase
{
    private $previous;
    private UploadTransaction $uploads;
    private MemoryFileStorage $storage;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Container();
        Container::setInstance($app);
        $this->uploads = new UploadTransaction();
        $app->instance(UploadTransaction::class, $this->uploads);
        $this->storage = new MemoryFileStorage();
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
    }

    private function field(): CleanupImages
    {
        return (new CleanupImages('attachments'))->usingStorage($this->storage)->thumbnail('small', 20, 20);
    }

    private function cleanup(CleanupImages $field): void
    {
        $model = (new CleanupRecord())->setRelation('attachments', new Collection([
            (new CleanupRecord())->forceFill(['id' => 7, 'path' => 'files/a.jpg']),
            (new CleanupRecord())->forceFill(['id' => 8, 'path' => 'files/b.jpg']),
        ]));
        $form = new class($field) extends Form {
            public function __construct(private $testField) {}
            public function fields() { return collect([$this->testField]); }
            public function cleanup($record) { $this->deleteFiles($record); }
        };
        $form->cleanup($model);
    }

    public function test_related_paths_and_thumbnails_are_deleted_only_after_commit(): void
    {
        $field = $this->field()->pathColumn('path');
        $callbacks = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('transaction')->willReturnCallback(static fn ($callback) => $callback());
        $connection->method('afterCommit')->willReturnCallback(function ($callback) use (&$callbacks) { $callbacks[] = $callback; });
        $this->uploads->run($connection, fn () => $this->cleanup($field));
        $this->assertCount(4, $this->storage->paths);
        $this->assertCount(1, $callbacks);
        $callbacks[0]();
        $this->assertSame([], $this->storage->paths);
    }

    public function test_failed_parent_deletion_keeps_related_files_and_thumbnails(): void
    {
        $field = $this->field()->pathColumn('path');
        $connection = $this->createMock(Connection::class);
        $connection->method('transaction')->willReturnCallback(static fn ($callback) => $callback());
        try {
            $this->uploads->run($connection, function () use ($field) {
                $this->cleanup($field);
                throw new \RuntimeException('Parent deletion cancelled.');
            });
            $this->fail('Deletion failure was swallowed.');
        } catch (\RuntimeException $exception) {
            $this->assertCount(4, $this->storage->paths);
        }
    }

    public function test_retainable_relations_keep_their_files(): void
    {
        $this->cleanup($this->field()->pathColumn('path')->retainable());
        $this->assertCount(4, $this->storage->paths);
    }

    public function test_ordinary_multiple_files_and_single_related_removal_still_work(): void
    {
        $field = $this->field();
        $field->setOriginal(['attachments' => ['files/a.jpg', 'files/b.jpg']]);
        $field->destroyAll();
        $this->assertSame([], $this->storage->paths);

        $this->storage = new MemoryFileStorage();
        $field = $this->field()->pathColumn('path');
        $field->setOriginal(['attachments' => [['id' => 7, 'path' => 'files/a.jpg'], ['id' => 8, 'path' => 'files/b.jpg']]]);
        $remaining = $field->destroyFromHasMany(7);
        $this->assertSame(1, $remaining[7][Form::REMOVE_FLAG_NAME]);
        $this->assertSame(['files/b.jpg', 'files/b-small.jpg'], array_keys($this->storage->paths));
    }
}

class CleanupRecord extends Model {}

class CleanupImages extends MultipleImage
{
    protected function initStorage() {}
    protected function getRelatedKeyName() { return 'id'; }
    public function usingStorage($storage) { $this->storage = $storage; return $this; }
}

class MemoryFileStorage
{
    public array $paths = ['files/a.jpg' => true, 'files/a-small.jpg' => true, 'files/b.jpg' => true, 'files/b-small.jpg' => true];
    public function exists($path) { return isset($this->paths[$path]); }
    public function delete($path) { unset($this->paths[$path]); return true; }
}
