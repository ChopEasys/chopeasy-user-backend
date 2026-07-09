<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RbacService
{
    protected RbacAuditService $auditService;

    public function __construct(RbacAuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Create a new role and log the action.
     *
     * @param string      $name        The role name
     * @param string|null $description The role description
     * @param int         $actorId     The user performing the action
     * @return Role
     */
    public function createRole(string $name, ?string $description, int $actorId): Role
    {
        $role = Role::create([
            'name' => $name,
            'description' => $description,
        ]);

        $this->auditService->log(
            actorId: $actorId,
            action: 'role_created',
            targetType: 'role',
            targetId: $role->id,
            previousState: null,
            newState: ['name' => $name, 'description' => $description]
        );

        return $role;
    }

    /**
     * Update an existing role and log the action.
     *
     * @param int   $roleId  The role ID to update
     * @param array $data    The data to update (name, description)
     * @param int   $actorId The user performing the action
     * @return Role
     */
    public function updateRole(int $roleId, array $data, int $actorId): Role
    {
        $role = Role::findOrFail($roleId);

        $previousState = [
            'name' => $role->name,
            'description' => $role->description,
        ];

        $role->update($data);

        $newState = [
            'name' => $role->name,
            'description' => $role->description,
        ];

        $this->auditService->log(
            actorId: $actorId,
            action: 'role_updated',
            targetType: 'role',
            targetId: $role->id,
            previousState: $previousState,
            newState: $newState
        );

        return $role;
    }

    /**
     * Delete a role if no users are assigned to it.
     *
     * @param int $roleId  The role ID to delete
     * @param int $actorId The user performing the action
     * @return void
     * @throws \Exception If the role has assigned users
     */
    public function deleteRole(int $roleId, int $actorId): void
    {
        $role = Role::findOrFail($roleId);

        $assignedUsersCount = DB::table('admin_role')
            ->where('role_id', $roleId)
            ->count();

        if ($assignedUsersCount > 0) {
            throw new \Exception('Cannot delete role with assigned users');
        }

        $previousState = [
            'name' => $role->name,
            'description' => $role->description,
        ];

        $role->delete();

        $this->auditService->log(
            actorId: $actorId,
            action: 'role_deleted',
            targetType: 'role',
            targetId: $roleId,
            previousState: $previousState,
            newState: null
        );
    }

    /**
     * Sync the permissions for a role and log the action.
     *
     * @param int   $roleId        The role ID
     * @param array $permissionIds The new set of permission IDs
     * @param int   $actorId       The user performing the action
     * @return void
     */
    public function syncRolePermissions(int $roleId, array $permissionIds, int $actorId): void
    {
        $role = Role::findOrFail($roleId);

        $oldPermissionIds = $role->permissions()->pluck('permissions.id')->toArray();

        $role->permissions()->sync($permissionIds);

        $added = array_diff($permissionIds, $oldPermissionIds);
        $removed = array_diff($oldPermissionIds, $permissionIds);

        $this->auditService->log(
            actorId: $actorId,
            action: 'permissions_synced',
            targetType: 'role',
            targetId: $role->id,
            previousState: ['permission_ids' => $oldPermissionIds],
            newState: ['permission_ids' => $permissionIds],
            metadata: [
                'added_count' => count($added),
                'removed_count' => count($removed),
            ]
        );
    }

    /**
     * Create a new admin user and assign a role.
     *
     * @param array $data    User data (fullname, email, password, role_id)
     * @param int   $actorId The user performing the action
     * @return User
     */
    public function createAdminUser(array $data, int $actorId): User
    {
        $user = User::create([
            'fullname' => $data['fullname'] ?? $data['name'] ?? null,
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'user_type' => 'admin',
            'is_active' => true,
        ]);

        if (!empty($data['role_id'])) {
            $user->adminRoles()->attach($data['role_id'], [
                'assigned_by' => $actorId,
            ]);
        }

        $this->auditService->log(
            actorId: $actorId,
            action: 'admin_created',
            targetType: 'user',
            targetId: $user->id,
            previousState: null,
            newState: [
                'fullname' => $user->fullname,
                'email' => $user->email,
                'user_type' => $user->user_type,
                'role_id' => $data['role_id'] ?? null,
            ]
        );

        return $user;
    }

    /**
     * Update an existing admin user.
     *
     * @param int   $userId  The user ID to update
     * @param array $data    The data to update (fullname, email, role_id)
     * @param int   $actorId The user performing the action
     * @return User
     */
    public function updateAdminUser(int $userId, array $data, int $actorId): User
    {
        $user = User::findOrFail($userId);

        $currentRoleId = $user->adminRoles()->first()?->id;

        $previousState = [
            'fullname' => $user->fullname,
            'email' => $user->email,
            'role_id' => $currentRoleId,
        ];

        $updateData = [];
        if (isset($data['fullname'])) {
            $updateData['fullname'] = $data['fullname'];
        }
        if (isset($data['email'])) {
            $updateData['email'] = $data['email'];
        }
        if (isset($data['password'])) {
            $updateData['password'] = Hash::make($data['password']);
        }

        if (!empty($updateData)) {
            $user->update($updateData);
        }

        if (isset($data['role_id']) && $data['role_id'] !== $currentRoleId) {
            $user->adminRoles()->sync([
                $data['role_id'] => ['assigned_by' => $actorId],
            ]);
        }

        $newRoleId = $user->adminRoles()->first()?->id;

        $newState = [
            'fullname' => $user->fullname,
            'email' => $user->email,
            'role_id' => $newRoleId,
        ];

        $this->auditService->log(
            actorId: $actorId,
            action: 'admin_updated',
            targetType: 'user',
            targetId: $user->id,
            previousState: $previousState,
            newState: $newState
        );

        return $user;
    }

    /**
     * Deactivate an admin user and revoke their tokens.
     *
     * @param int $userId  The user ID to deactivate
     * @param int $actorId The user performing the action
     * @return User
     */
    public function deactivateAdmin(int $userId, int $actorId): User
    {
        $user = User::findOrFail($userId);

        $user->update(['is_active' => false]);

        // Invalidate JWT tokens by updating the user's password timestamp
        // The is_active check in middleware will block any subsequent requests
        // If the user has a tokens() relationship (e.g., Sanctum), revoke those too
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        $this->auditService->log(
            actorId: $actorId,
            action: 'admin_deactivated',
            targetType: 'user',
            targetId: $user->id,
            previousState: ['is_active' => true],
            newState: ['is_active' => false]
        );

        return $user;
    }

    /**
     * Activate an admin user.
     *
     * @param int $userId  The user ID to activate
     * @param int $actorId The user performing the action
     * @return User
     */
    public function activateAdmin(int $userId, int $actorId): User
    {
        $user = User::findOrFail($userId);

        $user->update(['is_active' => true]);

        $this->auditService->log(
            actorId: $actorId,
            action: 'admin_activated',
            targetType: 'user',
            targetId: $user->id,
            previousState: ['is_active' => false],
            newState: ['is_active' => true]
        );

        return $user;
    }

    /**
     * Get all effective permission names for a user.
     *
     * @param int $userId The user ID
     * @return array Array of permission name strings
     */
    public function getUserPermissions(int $userId): array
    {
        $user = User::findOrFail($userId);

        if ($user->isSuperAdmin()) {
            return Permission::pluck('name')->toArray();
        }

        return $user->getEffectivePermissions();
    }
}
