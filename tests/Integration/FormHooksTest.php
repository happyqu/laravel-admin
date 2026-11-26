<?php

namespace Encore\Admin\Tests\Integration;

use Encore\Admin\Form;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FormHooksTest extends TestCase
{
    private $previousContainer;
    private $previousResolver;
    private $previousDispatcher;
    private Application $app;
    private Connection $connection;
    private Store $session;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for form integration tests.');
        }

        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Repository([
            'app' => ['locale' => 'en', 'fallback_locale' => 'en', 'url' => 'http://localhost'],
            'view' => ['paths' => []],
            'database' => ['default' => 'sqlite', 'connections' => [
                'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            ]],
        ]));
        $this->app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $this->session = new Store('form-hooks', new ArraySessionHandler(120));
        $this->app->instance('session', $this->session);
        $this->app->instance('session.store', $this->session);
        foreach ([\Illuminate\Events\EventServiceProvider::class, \Illuminate\Routing\RoutingServiceProvider::class,
            DatabaseServiceProvider::class, \Illuminate\Translation\TranslationServiceProvider::class,
            \Illuminate\Validation\ValidationServiceProvider::class, \Illuminate\View\ViewServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        (new DatabaseServiceProvider($this->app))->boot();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);

        $this->connection = $this->app['db']->connection();
        $this->connection->setTransactionManager(new DatabaseTransactionsManager());
        $this->connection->statement('CREATE TABLE articles (id INTEGER PRIMARY KEY, title TEXT NOT NULL, password TEXT)');
        $this->connection->statement('CREATE TABLE labels (id INTEGER PRIMARY KEY)');
        $this->connection->statement('CREATE TABLE article_label (article_id INTEGER, label_id INTEGER)');
        $this->connection->statement('CREATE TABLE notes (id INTEGER PRIMARY KEY, article_id INTEGER, title TEXT NOT NULL)');
        $this->connection->table('articles')->insert(['id' => 1, 'title' => 'Original']);
        $this->connection->table('labels')->insert([['id' => 1], ['id' => 2]]);
        $this->connection->table('article_label')->insert(['article_id' => 1, 'label_id' => 1]);
        $this->connection->table('notes')->insert(['id' => 7, 'article_id' => 1, 'title' => 'Old note']);
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            $this->app['db']->disconnect();
            if ($this->previousResolver) {
                Model::setConnectionResolver($this->previousResolver);
            } else {
                Model::unsetConnectionResolver();
            }
            if ($this->previousDispatcher) {
                Model::setEventDispatcher($this->previousDispatcher);
            } else {
                Model::unsetEventDispatcher();
            }
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->previousContainer);
            Container::setInstance($this->previousContainer);
        }
    }

    private function requestFor(string $method, array $input, string $accept = 'application/json'): void
    {
        $path = $method === 'POST' ? '/admin/articles' : '/admin/articles/1';
        $request = Request::create('http://localhost'.$path, $method, $input, [], [], [
            'HTTP_ACCEPT' => $accept,
            'HTTP_REFERER' => 'http://localhost/admin/articles/1/edit',
        ]);
        $request->setLaravelSession($this->session);
        $this->app->instance('request', $request);
    }

    private function form(?FormHookArticle $article = null): Form
    {
        $form = new Form($article ?? new FormHookArticle());
        $form->text('title')->rules('required|string');
        $form->multipleSelect('labels')->options([1 => 'Old', 2 => 'New']);
        $form->hasMany('notes', function ($note) {
            $note->text('title')->rules('required');
        });

        return $form;
    }

    public function test_store_runs_hooks_in_order_and_accepts_transformed_values(): void
    {
        $password = 'short-example';
        $this->requestFor('POST', ['title' => 'Created', 'password' => $password, 'labels' => ['2']]);
        $form = $this->form();
        $form->password('password')->rules('required|string|max:16');
        $calls = [];
        $form->submitted(function (Form $form) use (&$calls) {
            $calls[] = ['submitted', request()->input('title'), $form->input('title')];
        });
        $form->saving(function (Form $form) use (&$calls) {
            $calls[] = ['saving', $form->input('title')];
            $form->password = password_hash($form->password, PASSWORD_BCRYPT, ['cost' => 4]);
        });
        $form->saved(function (Form $form) use (&$calls) {
            $model = $form->model();
            $calls[] = ['saved', $model->getKey(), $model->labels->modelKeys(), $model->getConnection()->transactionLevel()];

            return response()->json(['id' => $model->getKey()], 201);
        });

        $response = $form->store();
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(['id' => 2], $response->getData(true));
        $this->assertSame([['submitted', 'Created', null], ['saving', 'Created'], ['saved', 2, [2], 0]], $calls);
        $this->assertTrue(password_verify($password, FormHookArticle::findOrFail(2)->password));
    }

    public function test_field_validation_failure_skips_all_hooks(): void
    {
        $this->requestFor('POST', []);
        $form = $this->form();
        foreach (['submitted', 'saving', 'saved'] as $hook) {
            $form->{$hook}(fn () => $this->fail('Invalid field input reached '.$hook));
        }

        $response = $form->store();
        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('title', $response->getData(true)['errors']);
        $this->assertSame(1, FormHookArticle::count());
    }

    public function test_saved_reads_updated_associations_on_the_same_model(): void
    {
        $this->requestFor('PUT', [
            'title' => 'Updated',
            'labels' => ['2'],
            'notes' => [
                7 => ['id' => 7, 'title' => 'Old note', '_remove_' => 1],
                'new_1' => ['id' => '', 'title' => 'New note', '_remove_' => 0],
            ],
        ]);
        $form = $this->form();
        $before = null;
        $form->saving(function (Form $form) use (&$before) {
            $before = $form->model();
            $this->assertSame([1], $before->labels->modelKeys());
            $this->assertSame(['Old note'], $before->notes->pluck('title')->all());
        });
        $form->saved(function (Form $form) use (&$before) {
            $model = $form->model();
            $this->assertSame($before, $model);
            $this->assertTrue($model->wasChanged('title'));
            $this->assertTrue($model->relationLoaded('labels'));
            $this->assertTrue($model->relationLoaded('notes'));
            $this->assertSame([2], $model->labels->modelKeys());
            $this->assertSame(['New note'], $model->notes->pluck('title')->all());

            return response()->noContent();
        });

        $this->assertSame(204, $form->update(1)->getStatusCode());
    }

    public function test_partial_update_supports_clearing_a_relation_and_skips_omitted_relations(): void
    {
        $this->requestFor('PATCH', ['title' => 'Partial']);
        $form = $this->form();
        $form->saved(function (Form $form) {
            $this->assertSame([1], $form->model()->labels->modelKeys());
            $this->assertSame([7], $form->model()->notes->modelKeys());
        });
        $this->connection->enableQueryLog();
        $this->assertTrue($form->update(1)->getData(true)['status']);
        $queries = array_column($this->connection->getQueryLog(), 'query');
        $this->assertCount(1, array_filter($queries, fn ($query) => str_contains($query, '"article_label"')));
        $this->assertCount(1, array_filter($queries, fn ($query) => str_contains($query, '"notes"')));

        $this->requestFor('PATCH', ['labels' => []]);
        $form = $this->form();
        $form->saved(function (Form $form) {
            $this->assertSame([], $form->model()->labels->modelKeys());
            $this->assertSame([7], $form->model()->notes->modelKeys());
        });
        $this->assertTrue($form->update(1)->getData(true)['status']);
        $this->assertSame('Partial', FormHookArticle::findOrFail(1)->title);
    }

    public static function rejectingHooks(): array
    {
        return [
            'submitted on create' => ['submitted', 'POST'],
            'saving on update' => ['saving', 'PUT'],
        ];
    }

    #[DataProvider('rejectingHooks')]
    public function test_callback_validation_uses_the_form_error_response(string $hook, string $method): void
    {
        foreach (['application/json', 'text/html'] as $accept) {
            $this->requestFor($method, [
                'title' => 'Rejected',
                'labels' => ['2'],
                'current_password' => 'private',
                'password' => 'private',
            ], $accept);
            $form = $this->form();
            $form->{$hook}(static function () {
                throw ValidationException::withMessages(['title' => 'Title blocked.']);
            });
            $form->{$hook}(fn () => $this->fail('A subsequent callback ran after rejection.'));
            $form->saved(fn () => $this->fail('A rejected form reached saved.'));

            $response = $method === 'POST' ? $form->store() : $form->update(1);
            if ($accept === 'application/json') {
                $this->assertSame(422, $response->getStatusCode());
                $this->assertSame([
                    'status' => false,
                    'message' => 'Title blocked.',
                    'errors' => ['title' => ['Title blocked.']],
                    'validation' => ['title' => ['Title blocked.']],
                ], $response->getData(true));
            } else {
                $this->assertSame(302, $response->getStatusCode());
                $this->assertSame('http://localhost/admin/articles/1/edit', $response->getTargetUrl());
                $this->assertSame('Title blocked.', $this->session->get('errors')->first('title'));
                $this->assertSame('Rejected', $this->session->getOldInput('title'));
                $this->assertNull($this->session->getOldInput('current_password'));
                $this->assertNull($this->session->getOldInput('password'));
            }
            $this->assertSame(1, FormHookArticle::count());
            $this->assertSame('Original', FormHookArticle::findOrFail(1)->title);
            $this->assertSame([1], FormHookArticle::findOrFail(1)->labels->modelKeys());
        }
    }

    public function test_form_leaves_customized_validation_exceptions_to_laravel(): void
    {
        $this->requestFor('POST', ['title' => 'Rejected']);
        $response = response()->json(['reason' => 'custom'], 409);
        $exceptions = [
            new ValidationException(validator([], []), $response),
            ValidationException::withMessages(['title' => 'Blocked.'])->errorBag('custom'),
            ValidationException::withMessages(['title' => 'Blocked.'])->redirectTo('/custom'),
            ValidationException::withMessages(['title' => 'Blocked.'])->status(409),
        ];

        foreach ($exceptions as $exception) {
            $form = $this->form();
            $form->saving(static function () use ($exception) {
                throw $exception;
            });
            try {
                $form->store();
                $this->fail('The customized exception should have propagated.');
            } catch (ValidationException $caught) {
                $this->assertSame($exception, $caught);
            }
        }
    }

    public function test_response_short_circuits_the_remaining_callbacks_and_persistence(): void
    {
        $this->requestFor('PUT', ['title' => 'Rejected']);
        $form = $this->form();
        $response = response()->json(['reason' => 'Blocked.'], 409);
        $form->submitted(static fn () => false);
        $form->submitted(static fn () => $response);
        $form->submitted(fn () => $this->fail('A callback ran after a response.'));
        $form->saving(fn () => $this->fail('An early response reached saving.'));
        $form->saved(fn () => $this->fail('An early response reached saved.'));

        $this->assertSame($response, $form->update(1));
        $this->assertSame('Original', FormHookArticle::findOrFail(1)->title);
    }

    public function test_relation_write_failure_rolls_back_the_main_record_and_skips_saved(): void
    {
        $this->requestFor('PUT', [
            'title' => 'Will roll back',
            'labels' => ['2'],
            'notes' => ['new_1' => ['id' => '', 'title' => 'Canceled', '_remove_' => 0]],
        ]);
        FormHookNote::saving(static fn () => false);
        $form = $this->form();
        $form->saved(fn () => $this->fail('A failed write reached saved.'));

        try {
            $form->update(1);
            $this->fail('The canceled save should have propagated.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Model save was cancelled.', $exception->getMessage());
        }

        $model = FormHookArticle::findOrFail(1);
        $this->assertSame('Original', $model->title);
        $this->assertSame([1], $model->labels->modelKeys());
        $this->assertSame([7], $model->notes->modelKeys());
    }

    public function test_relation_refresh_failure_rolls_back_the_saved_data(): void
    {
        $this->requestFor('PUT', ['title' => 'Will roll back', 'labels' => ['2']]);
        $form = $this->form(new class extends FormHookArticle {
            public function load($relations)
            {
                throw new \RuntimeException('Relation refresh failed.');
            }
        });
        $form->saved(fn () => $this->fail('A failed refresh reached saved.'));

        try {
            $form->update(1);
            $this->fail('The refresh failure should have propagated.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Relation refresh failed.', $exception->getMessage());
        }

        $model = FormHookArticle::findOrFail(1);
        $this->assertSame('Original', $model->title);
        $this->assertSame([1], $model->labels->modelKeys());
    }
}

class FormHookArticle extends Model
{
    protected $table = 'articles';
    public $timestamps = false;

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(FormHookLabel::class, 'article_label', 'article_id', 'label_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(FormHookNote::class, 'article_id');
    }
}

class FormHookLabel extends Model
{
    protected $table = 'labels';
    public $timestamps = false;
}

class FormHookNote extends Model
{
    protected $table = 'notes';
    protected $guarded = [];
    public $timestamps = false;
}
