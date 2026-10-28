<?php

namespace Encore\Admin\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** Shared fluent authorization rules for Actions and Tools. */
trait Authorization
{
    /** Configuration is carried separately from application-defined context options. */
    protected $authorizationRules = [];

    protected $authorizationDeniedReason;

    public function allowRoles(array $roles)
    {
        $this->authorizationRules['roles'] = $this->authorizationNames($roles);
        return $this;
    }

    public function denyRoles(array $roles)
    {
        $this->authorizationRules['denied_roles'] = $this->authorizationNames($roles);
        return $this;
    }

    public function requirePermissions(array $permissions)
    {
        $this->authorizationRules['permissions'] = $this->authorizationNames($permissions);
        return $this;
    }

    public function requireAnyPermission(array $permissions)
    {
        $this->authorizationRules['any_permission'] = $this->authorizationNames($permissions);
        return $this;
    }

    /** Omit the model class to authorize the current record (each selected record for batches). */
    public function ability(string $ability, ?string $modelClass = null)
    {
        $this->authorizationNames([$ability]);
        if ($modelClass !== null && !is_subclass_of($modelClass, Model::class)) {
            throw new \InvalidArgumentException('An ability model must be an Eloquent model class.');
        }
        $this->authorizationRules['ability'] = [$ability, $modelClass];
        return $this;
    }

    public function deniedAsDisabled(string $reason)
    {
        $this->authorizationDeniedReason = $reason;
        return $this;
    }

    private function authorizationNames(array $names): array
    {
        if (!$names) {
            throw new \InvalidArgumentException('Authorization lists must not be empty.');
        }
        foreach ($names as $name) {
            if (!is_string($name) || trim($name) === '') {
                throw new \InvalidArgumentException('Roles, permissions and abilities must be non-empty strings.');
            }
        }
        return array_values(array_unique($names));
    }

    public function authorizationConfiguration(): array
    {
        return $this->authorizationRules;
    }

    /** Only call with configuration authenticated by the action ticket. */
    public function restoreAuthorizationConfiguration(array $rules): void
    {
        // Keep constructor defaults for older tickets and actions configured in their class.
        $this->authorizationRules = array_replace($this->authorizationRules, $rules);
    }

    protected function passesConfiguredAuthorization($user, $model, bool $pendingSelection = false): bool
    {
        $rules = $this->authorizationRules;
        foreach ($rules['denied_roles'] ?? [] as $role) {
            if ($user->isRole($role)) return false;
        }
        if (isset($rules['roles']) && !collect($rules['roles'])->contains(fn ($role) => $user->isRole($role))) {
            return false;
        }
        foreach ($rules['permissions'] ?? [] as $permission) {
            if (!$user->can($permission)) return false;
        }
        if (isset($rules['any_permission']) && !collect($rules['any_permission'])->contains(fn ($permission) => $user->can($permission))) {
            return false;
        }
        if (isset($rules['ability'])) {
            [$ability, $modelClass] = $rules['ability'];
            if ($pendingSelection && $modelClass === null) return true;
            $gate = Gate::forUser($user);
            foreach ($model instanceof Collection && $modelClass === null ? $model : [$modelClass ?? $model] as $subject) {
                if (!$gate->allows($ability, $subject === null ? [] : [$subject])) return false;
            }
        }
        return true;
    }

    protected function authorizationDeniedHtml(?string $label = null): string
    {
        if ($this->authorizationDeniedReason === null) return '';

        return '<span class="text-muted" aria-disabled="true" title="'.e($this->authorizationDeniedReason).'">'.e($label ?? $this->authorizationDeniedReason).'</span>';
    }
}
