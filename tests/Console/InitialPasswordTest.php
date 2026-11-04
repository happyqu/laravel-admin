<?php

namespace Encore\Admin\Tests\Console;

use Encore\Admin\Auth\Database\AdminTablesSeeder;
use Illuminate\Console\Command;
use PHPUnit\Framework\TestCase;

class InitialPasswordTest extends TestCase
{
    private $previousPassword;

    protected function setUp(): void
    {
        $this->previousPassword = getenv('ADMIN_INITIAL_PASSWORD');
        putenv('ADMIN_INITIAL_PASSWORD');
    }

    protected function tearDown(): void
    {
        putenv($this->previousPassword === false ? 'ADMIN_INITIAL_PASSWORD' : 'ADMIN_INITIAL_PASSWORD='.$this->previousPassword);
    }

    private function seeder(): AdminTablesSeeder
    {
        return new class extends AdminTablesSeeder {
            public function password($value = null) { return $this->initialPassword($value); }
        };
    }

    public function test_explicit_password_and_process_environment_support_unattended_installation(): void
    {
        putenv('ADMIN_INITIAL_PASSWORD=Environment123!');
        $this->assertSame('Environment123!', $this->seeder()->password());
        $this->assertSame('Explicit123!', $this->seeder()->password('Explicit123!'));
    }

    public function test_interactive_password_is_hidden_and_confirmed(): void
    {
        $command = $this->createMock(Command::class);
        $command->expects($this->exactly(2))->method('secret')->with($this->anything(), false)
            ->willReturnOnConsecutiveCalls('Interactive123!', 'Interactive123!');
        $seeder = $this->seeder()->setCommand($command);
        $this->assertSame('Interactive123!', $seeder->password());
    }

    public function test_missing_and_weak_passwords_are_rejected_without_disclosing_them(): void
    {
        foreach ([null, '', 'admin', 'lowercase123!', 'NoSymbols123', 'Contains#123'] as $password) {
            try {
                $this->seeder()->password($password);
                $this->fail('An unsafe initial password was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('ADMIN_INITIAL_PASSWORD', $exception->getMessage());
                if ($password) $this->assertStringNotContainsString('['.$password.']', $exception->getMessage());
            }
        }
    }

    public function test_mismatched_confirmation_is_rejected(): void
    {
        $command = $this->createMock(Command::class);
        $command->method('secret')->willReturnOnConsecutiveCalls('Interactive123!', 'Different123!');
        $this->expectException(\InvalidArgumentException::class);
        $this->seeder()->setCommand($command)->password();
    }
}
