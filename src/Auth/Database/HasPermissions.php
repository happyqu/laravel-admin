<?php

namespace Encore\Admin\Auth\Database;

use Illuminate\Support\Collection;

trait HasPermissions
{
    /**
     * Get all permissions of user.
     *
     * @return mixed
     */
    public function allPermissions(): Collection
    {
        return $this->roles()->with('permissions')->get()->pluck('permissions')->flatten()->merge($this->permissions);
    }

    /**
     * Check if user has permission.
     *
     * @param $ability
     * @param array $arguments
     *
     * @return bool
     */
    public function can($ability, $arguments = []): bool
    {
        if (empty($ability)) {
            return true;
        }

        if ($this->isAdministrator()) {
            return true;
        }

        foreach ($this->permissions as $permission) {
            if ($permission->slug == $ability) {
                return true;
            }
        }

        $roles = $this->roles;
        foreach ($roles as $role) {
            if (!$role->relationLoaded('permissions')) {
                // One query for all missing role relations instead of one per role.
                $roles->loadMissing('permissions');
                break;
            }
        }
        foreach ($roles as $role) {
            foreach ($role->permissions as $permission) {
                if ($permission->slug == $ability) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if user has no permission.
     *
     * @param $permission
     *
     * @return bool
     */
    public function cannot(string $permission): bool
    {
        return !$this->can($permission);
    }

    /**
     * Check if user is administrator.
     *
     * @return mixed
     */
    public function isAdministrator(): bool
    {
        return $this->isRole('administrator');
    }

    /**
     * Check if user is $role.
     *
     * @param string $role
     *
     * @return mixed
     */
    public function isRole(string $role): bool
    {
        foreach ($this->roles as $assigned) {
            if ($assigned->slug == $role) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user in $roles.
     *
     * @param array $roles
     *
     * @return mixed
     */
    public function inRoles(array $roles = []): bool
    {
        if ($roles === []) {
            return false;
        }

        // array_intersect compares string representations; keep the same matching rules.
        $allowed = array_fill_keys(array_map('strval', $roles), true);
        foreach ($this->roles as $assigned) {
            if (isset($allowed[(string) $assigned->slug])) {
                return true;
            }
        }

        return false;
    }

    /**
     * If visible for roles.
     *
     * @param $roles
     *
     * @return bool
     */
    public function visible(array $roles = []): bool
    {
        if (empty($roles)) {
            return true;
        }

        $roles = array_column($roles, 'slug');

        return $this->inRoles($roles) || $this->isAdministrator();
    }

    /**
     * Detach models from the relationship.
     *
     * @return void
     */
    protected static function bootHasPermissions()
    {
        static::deleting(function ($model) {
            $model->roles()->detach();

            $model->permissions()->detach();
        });
    }
}
