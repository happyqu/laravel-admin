<?php

namespace Encore\Admin\Tests\Rules;

use Encore\Admin\Rules\Password;
use Illuminate\Container\Container;
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
                return $group === 'admin' ? ['password_rule' => 'The password must be :min to :max characters long and contain at least 1 lowercase letter, 1 uppercase letter, 1 digit and 1 special character (:symbols).'] : [];
            }
        };
        $translator = new Translator($loader, 'en');
        $this->app->instance('translator', $translator);
        $this->app->instance('validator', new Factory($translator));
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
        $constraints = (new Password())->htmlConstraints();

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
}
