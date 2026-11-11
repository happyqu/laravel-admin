<?php

namespace Encore\Admin\Auth\Database;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Encore\Admin\Rules\Password;

class AdminTablesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(?string $password = null)
    {
        // Only an empty installation may be seeded. Truncating lookup tables next to an
        // existing administrator would erase host-defined roles, permissions and menus.
        if ($this->isInitialized()) {
            return;
        }
        $password = $this->initialPassword($password);

        // create a user.
        Administrator::truncate();
        Administrator::create([
            'username' => 'admin',
            'password' => Hash::make($password),
            'name'     => 'Administrator',
        ]);

        // create a role.
        Role::truncate();
        Role::create([
            'name' => 'Administrator',
            'slug' => 'administrator',
        ]);

        // add role to user.
        Administrator::first()->roles()->save(Role::first());

        //create a permission
        Permission::truncate();
        Permission::insert([
            [
                'name'        => 'All permission',
                'slug'        => '*',
                'http_method' => '',
                'http_path'   => '*',
            ],
            [
                'name'        => 'Dashboard',
                'slug'        => 'dashboard',
                'http_method' => 'GET',
                'http_path'   => '/',
            ],
            [
                'name'        => 'Login',
                'slug'        => 'auth.login',
                'http_method' => '',
                'http_path'   => "/auth/login\r\n/auth/logout",
            ],
            [
                'name'        => 'User setting',
                'slug'        => 'auth.setting',
                'http_method' => 'GET,PUT',
                'http_path'   => '/auth/setting',
            ],
            [
                'name'        => 'Auth management',
                'slug'        => 'auth.management',
                'http_method' => '',
                'http_path'   => "/auth/users\n/auth/users/*\n/auth/roles\n/auth/roles/*\n/auth/permissions\n/auth/permissions/*\n/auth/menu\n/auth/menu/*\n/auth/logs\n/auth/logs/*",
            ],
        ]);

        Role::first()->permissions()->save(Permission::first());

        // add default menus.
        Menu::truncate();
        Menu::insert([
            [
                'parent_id' => 0,
                'order'     => 1,
                'title'     => 'Dashboard',
                'icon'      => 'fa-bar-chart',
                'uri'       => '/',
            ],
            [
                'parent_id' => 0,
                'order'     => 2,
                'title'     => 'Admin',
                'icon'      => 'fa-tasks',
                'uri'       => '',
            ],
            [
                'parent_id' => 2,
                'order'     => 3,
                'title'     => 'Users',
                'icon'      => 'fa-users',
                'uri'       => 'auth/users',
            ],
            [
                'parent_id' => 2,
                'order'     => 4,
                'title'     => 'Roles',
                'icon'      => 'fa-user',
                'uri'       => 'auth/roles',
            ],
            [
                'parent_id' => 2,
                'order'     => 5,
                'title'     => 'Permission',
                'icon'      => 'fa-ban',
                'uri'       => 'auth/permissions',
            ],
            [
                'parent_id' => 2,
                'order'     => 6,
                'title'     => 'Menu',
                'icon'      => 'fa-bars',
                'uri'       => 'auth/menu',
            ],
            [
                'parent_id' => 2,
                'order'     => 7,
                'title'     => 'Operation log',
                'icon'      => 'fa-history',
                'uri'       => 'auth/logs',
            ],
        ]);

        // add role to menu.
        Menu::find(2)->roles()->save(Role::first());
    }

    /**
     * Whether any admin table already holds data that seeding would truncate.
     */
    protected function isInitialized(): bool
    {
        foreach ([Administrator::class, Role::class, Permission::class, Menu::class] as $model) {
            if ($model::query()->exists()) {
                return true;
            }
        }

        return false;
    }

    protected function initialPassword(?string $password): string
    {
        if ($password === null) {
            // env() also sees values that were loaded without the putenv adapter.
            $environment = env('ADMIN_INITIAL_PASSWORD');
            $password = is_string($environment) && $environment !== '' ? $environment : null;
        }
        if ($password === null && $this->command) {
            $password = $this->command->secret('Set the initial administrator password', false);
            $confirmation = $this->command->secret('Confirm the initial administrator password', false);
            if (!is_string($password) || $password !== $confirmation) {
                throw new \InvalidArgumentException('The initial administrator passwords do not match.');
            }
        }
        if (!is_string($password) || !(new Password())->passesValue($password)) {
            throw new \InvalidArgumentException('Set a strong initial administrator password (8-20 characters, including lowercase, uppercase, a digit and a symbol from $@!%*?&). For unattended installation, inject ADMIN_INITIAL_PASSWORD into the process environment.');
        }

        return $password;
    }
}
