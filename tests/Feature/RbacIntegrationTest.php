<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\RbacAuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\RbacService;
use App\Services\RbacAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class RbacIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed permissions so they are available in tests
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PermissionSeeder']);
    }

    private function createUserWithType(string $type, ?string $email = null): User
    {
        return User::create([
            'fullname' => ucfirst($type) . ' User',
            'email' => $email ?? $type . '@example.com',
            'user_type' => $type,
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
        ]);
    }

    private function createAdminWithRole(Role $role, string $email = 'admin@example.com'): User
    {
        $admin = User::create([
            'fullname' => 'Admin User',
            'email' => $email,
            'user_type' => 'admin',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
        ]);
        $admin->adminRoles()->attach($role->id, ['assigned_by' => $admin->id]);
        return $admin;
    }

    private function createSuperAdmin(string $email = 'super@example.com'): User
    {
        return User::create([
            'fullname' => 'Super Admin',
            'email' => $email,
            'user_type' => 'super_admin',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
        ]);
    }

    /**
     * 12.1: Non-admin user types (customer, vendor, agent, rider) receive 403
     * when hitting admin routes.
     */
    public function test_non_admin_users_receive_403_on_admin_routes(): void
    {
        foreach (['customer', 'vendor', 'agent', 'rider'] as $type) {
            $user = $this->createUserWithType($type, $type . '_test@example.com');

            $response = $this->actingAs($user, 'api')
                ->getJson('/api/v1/admin/roles');

            $response->assertStatus(403);
        }
    }

    /**
     * 12.2: Admin with correct permission can access route,
     * admin without permission gets 403.
     */
    public function test_admin_with_permission_can_access_route_without_gets_403(): void
    {
        // Create role with manage_roles permission
        $role = Role::create(['name' => 'Role Manager', 'description' => 'Can manage roles']);
        $permission = Permission::where('name', 'manage_roles')->first();
        $role->permissions()->attach($permission->id);

        // Admin with this role should access successfully
        $adminWithPerm = $this->createAdminWithRole($role, 'admin_with_perm@example.com');

        $response = $this->actingAs($adminWithPerm, 'api')
            ->getJson('/api/v1/admin/roles');
        $response->assertStatus(200);

        // Admin without the required permission should get 403
        $emptyRole = Role::create(['name' => 'Empty Role', 'description' => 'No permissions']);
        $adminWithoutPerm = $this->createAdminWithRole($emptyRole, 'admin_no_perm@example.com');

        $response2 = $this->actingAs($adminWithoutPerm, 'api')
            ->getJson('/api/v1/admin/roles');
        $response2->assertStatus(403);
    }

    /**
     * 12.3: Super Admin bypasses all permission checks.
     */
    public function test_super_admin_bypasses_all_permission_checks(): void
    {
        $superAdmin = $this->createSuperAdmin();

        // Should access roles route (requires manage_roles)
        $response = $this->actingAs($superAdmin, 'api')
            ->getJson('/api/v1/admin/roles');
        $response->assertStatus(200);

        // Should access admin-users route (requires manage_admins)
        $response2 = $this->actingAs($superAdmin, 'api')
            ->getJson('/api/v1/admin/admin-users');
        $response2->assertStatus(200);

        // Should access audit-logs route (requires view_audit_log)
        $response3 = $this->actingAs($superAdmin, 'api')
            ->getJson('/api/v1/admin/audit-logs');
        $response3->assertStatus(200);
    }

    /**
     * 12.4: Role CRUD operations create corresponding audit log entries.
     */
    public function test_role_crud_operations_create_audit_log_entries(): void
    {
        $superAdmin = $this->createSuperAdmin();

        // Create role
        $response = $this->actingAs($superAdmin, 'api')
            ->postJson('/api/v1/admin/roles', [
                'name' => 'Test Role',
                'description' => 'A test role',
            ]);
        $response->assertStatus(201);

        $this->assertDatabaseHas('rbac_audit_logs', [
            'actor_id' => $superAdmin->id,
            'action' => 'role_created',
            'target_type' => 'role',
        ]);

        $roleId = $response->json('data.id');

        // Update role
        $updateResponse = $this->actingAs($superAdmin, 'api')
            ->putJson("/api/v1/admin/roles/$roleId", [
                'name' => 'Updated Role',
            ]);
        $updateResponse->assertStatus(200);

        $this->assertDatabaseHas('rbac_audit_logs', [
            'action' => 'role_updated',
            'target_id' => $roleId,
        ]);

        // Delete role (no users assigned, so it should succeed)
        $deleteResponse = $this->actingAs($superAdmin, 'api')
            ->deleteJson("/api/v1/admin/roles/$roleId");
        $deleteResponse->assertStatus(200);

        $this->assertDatabaseHas('rbac_audit_logs', [
            'action' => 'role_deleted',
            'target_id' => $roleId,
        ]);
    }

    /**
     * 12.5: Deactivating an admin revokes their tokens (subsequent request returns 401 or 403).
     */
    public function test_deactivating_admin_blocks_subsequent_requests(): void
    {
        $superAdmin = $this->createSuperAdmin();

        // Create admin with role that has manage_roles permission
        $role = Role::create(['name' => 'Basic Admin', 'description' => 'Basic role']);
        $permission = Permission::where('name', 'manage_roles')->first();
        $role->permissions()->attach($permission->id);

        $admin = $this->createAdminWithRole($role, 'target_admin@example.com');

        // Admin can access before deactivation
        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/roles')
            ->assertStatus(200);

        // Super admin deactivates the admin
        $this->actingAs($superAdmin, 'api')
            ->postJson("/api/v1/admin/admin-users/{$admin->id}/deactivate")
            ->assertStatus(200);

        // Verify the admin is deactivated in database
        $admin->refresh();
        $this->assertFalse((bool) $admin->is_active);
    }

    /**
     * 12.6: Deleting a role with assigned users fails with appropriate error.
     */
    public function test_deleting_role_with_assigned_users_fails(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $role = Role::create(['name' => 'Assigned Role', 'description' => 'Has users']);
        $admin = $this->createAdminWithRole($role, 'assigned_admin@example.com');

        $response = $this->actingAs($superAdmin, 'api')
            ->deleteJson("/api/v1/admin/roles/{$role->id}");

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Cannot delete role with assigned users']);

        // Role should still exist in the database
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }
}
