<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    protected RbacService $rbacService;

    public function __construct(RbacService $rbacService)
    {
        $this->rbacService = $rbacService;
    }

    /**
     * List all roles with user counts.
     */
    public function index(): JsonResponse
    {
        $roles = Role::withCount('users')->get();

        return response()->json(['data' => $roles]);
    }

    /**
     * Create a new role.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:roles,name',
            'description' => 'nullable|string',
        ]);

        $role = $this->rbacService->createRole(
            name: $validated['name'],
            description: $validated['description'] ?? null,
            actorId: auth()->id()
        );

        return response()->json(['data' => $role], 201);
    }

    /**
     * Update an existing role.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => "sometimes|string|unique:roles,name,{$id}",
            'description' => 'nullable|string',
        ]);

        $role = $this->rbacService->updateRole(
            roleId: $id,
            data: $validated,
            actorId: auth()->id()
        );

        return response()->json(['data' => $role]);
    }

    /**
     * Delete a role.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->rbacService->deleteRole(
                roleId: $id,
                actorId: auth()->id()
            );
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json(['message' => 'Role deleted successfully']);
    }

    /**
     * Sync permissions for a role.
     */
    public function syncPermissions(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'integer|exists:permissions,id',
        ]);

        $this->rbacService->syncRolePermissions(
            roleId: $id,
            permissionIds: $validated['permissions'],
            actorId: auth()->id()
        );

        $role = Role::with('permissions')->findOrFail($id);

        return response()->json(['data' => $role]);
    }
}
