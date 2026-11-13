<?php

namespace Encore\Admin\Tests\Rules;

use Encore\Admin\Rules\Password;
use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class PasswordRuleTest extends TestCase
{
    private $previousContainer;
    private Container $app;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->app = new Container();
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);

        $loader = new class extends ArrayLoader {
            public function load($locale, $group, $namespace = null)
            {
                return $group === 'admin' ? [
                    'password_rule' => 'The password must be :min to :max characters long and contain at least 1 lowercase letter, 1 uppercase letter, 1 digit and 1 special character (:symbols).',
                    'password_invalid' => 'The password does not meet the configured requirements.',
                ] : [];
            }
        };
        $translator = new Translator($loader, 'en');
        $this->app->instance('translator', $translator);
        $this->app->instance('validator', new Factory($translator));
        $this->app->instance('config', new Repository());
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousContainer);
        Container::setInstance($this->previousContainer);
    }

    public function test_valid_values_pass(): void
    {
        foreach (['Abcdefg1$', 'Abcdef1$@!', 'Abcdefgh123456$', 'Abcdefg123$'] as $value) {
            $this->assertTrue((new Password())->passesValue($value), "expected {$value} to pass");
        }
    }

    public function test_invalid_values_fail(): void
    {
        foreach (['abcdefghijkl', 'ABCDEFGH12$', 'Abcdefg12345', 'Ab1$', 'Abcdefg1 2$', 'Abcdefg1#2$', '', 12345678] as $value) {
            $this->assertFalse((new Password())->passesValue($value), "expected {$value} to fail");
        }

        $this->assertFalse((new Password())->passesValue(null));
    }

    public function test_custom_symbols_and_bounds(): void
    {
        $rule = new Password('#+', 6, 8);

        $this->assertTrue($rule->passesValue('Abcde1#'));
        $this->assertFalse($rule->passesValue('Abcde1$'));
        $this->assertFalse($rule->passesValue('Abcdefg1#'));
    }

    public function test_html_constraints_are_browser_safe(): void
    {
        $constraints = (new Password(Password::DEFAULT_SYMBOLS, 8, 20))->htmlConstraints();

        $this->assertSame(8, $constraints['minlength']);
        $this->assertSame(20, $constraints['maxlength']);
        // HTML pattern attributes are implicitly anchored; the body must not
        // carry its own anchors or the browser rejects everything.
        $this->assertStringStartsWith('(?=', $constraints['pattern']);
        $this->assertStringNotContainsString('/', $constraints['pattern']);
        $this->assertMatchesRegularExpression('/^'.$constraints['pattern'].'$/', 'Abcdefg1$');
        $this->assertStringContainsString('8 to 20', $constraints['title']);
    }

    public function test_rule_string(): void
    {
        $this->assertSame('admin_password', (new Password())->rule());
        $this->assertSame('admin_password:6,8,$@!%*?&', (new Password('$@!%*?&', 6, 8))->rule());
    }

    public function test_validator_extension(): void
    {
        Password::register();

        $factory = $this->app['validator'];

        $validator = $factory->make(['password' => 'weakpass'], ['password' => 'admin_password']);
        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('8 to 20', $validator->errors()->first('password'));

        $validator = $factory->make(['password' => 'Abcdefg1$'], ['password' => 'admin_password']);
        $this->assertFalse($validator->fails());

        $validator = $factory->make(['password' => 'Abcde1#'], ['password' => 'admin_password:6,8,#']);
        $this->assertTrue($validator->passes());
    }

    public function test_configured_regex_and_message_apply_to_validator_and_initialization(): void
    {
        $this->app['config']->set('admin.password', [
            'regex' => '/\A(?:[0-9]{6}|[a-z]{24})\z/',
            'message' => 'Use six digits or 24 lowercase letters.',
        ]);
        Password::register();
        $rule = new Password();
        $this->assertSame(['title' => 'Use six digits or 24 lowercase letters.'], $rule->htmlConstraints());
        foreach (['123456', str_repeat('a', 24)] as $password) {
            $this->assertTrue($this->app['validator']->make(['password' => $password], ['password' => ['required', $rule->rule(), 'confirmed'], 'password_confirmation' => 'required'])->fails());
            $this->assertTrue($this->app['validator']->make(['password' => $password, 'password_confirmation' => $password], ['password' => ['required', $rule->rule(), 'confirmed']])->passes());
        }
        $invalid = $this->app['validator']->make(['password' => 'Abcdefg1$'], ['password' => $rule->rule()]);
        $this->assertSame($rule->message(), $invalid->errors()->first('password'));
        $seeder = new class extends \Encore\Admin\Auth\Database\AdminTablesSeeder {
            public function password($value) { return $this->initialPassword($value); }
        };
        $this->assertSame('123456', $seeder->password('123456'));
        try {
            $seeder->password('Abcdefg1$');
            $this->fail('The old default password must fail the custom policy.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString($rule->message(), $exception->getMessage());
            $this->assertStringNotContainsString('Abcdefg1$', $exception->getMessage());
        }
        // Explicit legacy rules keep their own bounds even when config changes.
        $legacy = new Password(Password::DEFAULT_SYMBOLS, 8, 20);
        $this->assertTrue($this->app['validator']->make(['password' => 'Abcdefg1$'], ['password' => $legacy->rule()])->passes());
    }

    public function test_invalid_configuration_is_reported_without_accepting_a_password(): void
    {
        foreach (['', null, [], '/[/', 'missing-delimiters'] as $regex) {
            $this->app['config']->set('admin.password.regex', $regex);
            try {
                new Password();
                $this->fail('Invalid regex configuration was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('admin.password.regex', $exception->getMessage());
            }
        }
    }

    public function test_invalid_message_configuration_is_reported(): void
    {
        $this->app['config']->set('admin.password.message', '');
        $this->expectException(\InvalidArgumentException::class);
        new Password();
    }

    public function test_null_message_uses_translation_matching_the_current_regex(): void
    {
        $this->app['config']->set('admin.password.message', null);
        $this->assertStringContainsString('8 to 20', (new Password())->message());
        $this->app['config']->set('admin.password.regex', '/\A[0-9]{6}\z/');
        $this->assertSame('The password does not meet the configured requirements.', (new Password())->message());
        $this->app['config']->set('admin.password.regex', Password::DEFAULT_REGEX);
        $this->assertStringContainsString('8 to 20', (new Password())->message());
    }

    public function test_default_config_contains_an_editable_regex_and_message(): void
    {
        $app = new \Illuminate\Foundation\Application(dirname(__DIR__, 2));
        try {
            $config = require dirname(__DIR__, 2).'/config/admin.php';
            $this->assertSame(Password::DEFAULT_REGEX, $config['password']['regex']);
            $this->assertSame('密码须为8–20位，包含大小写字母、数字及特殊字符（$@!%*?&）。', $config['password']['message']);
        } finally {
            Container::setInstance($this->app);
        }
    }

    public function test_default_policy_rejects_trailing_newlines(): void
    {
        $this->assertFalse((new Password())->passesValue("Abcdefg1$\n"));
        $this->assertTrue((new Password())->passesValue('Abcdefg1$'));
    }

    public function test_password_commands_retry_then_hash_a_password_matching_configuration(): void
    {
        $this->app['config']->set('admin.password', ['regex' => '/\A[0-9]{6}\z/', 'message' => 'Use six digits.']);
        $this->app['config']->set('admin.database.users_model', PasswordCommandUser::class);
        $this->app['config']->set('admin.database.roles_model', PasswordCommandRole::class);
        $hasher = new \Illuminate\Hashing\BcryptHasher(['rounds' => 4]);
        $this->app->instance('hash', $hasher);
        foreach ([\Encore\Admin\Console\CreateUserCommand::class, \Encore\Admin\Console\ResetPasswordCommand::class] as $class) {
            PasswordCommandUser::$savedPassword = null;
            $command = $this->getMockBuilder($class)->onlyMethods(['ask', 'secret', 'error', 'info'])->getMock();
            $command->method('ask')->willReturn('user');
            $command->expects($this->once())->method('error')->with('Use six digits.');
            $command->method('info');
            $answers = $class === \Encore\Admin\Console\CreateUserCommand::class
                ? ['Abcdefg1$', '123456'] : ['Abcdefg1$', '123456', '123456'];
            $command->expects($this->exactly(count($answers)))->method('secret')->willReturnOnConsecutiveCalls(...$answers);
            $command->handle();
            $this->assertTrue($hasher->check('123456', PasswordCommandUser::$savedPassword));
        }
    }
}

class PasswordCommandUser
{
    public static $savedPassword;
    public $password;
    public function __construct(array $attributes = []) { $this->password = $attributes['password'] ?? null; }
    public static function query() { return new self(); }
    public function where($column, $value) { return $this; }
    public function first() { return $this; }
    public function save() { self::$savedPassword = $this->password; }
    public function roles() { return new class { public function attach($roles) {} }; }
}

class PasswordCommandRole
{
    public static function all() { return collect([(object) ['name' => 'administrator']]); }
}
