<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    protected RbacService $rbacService;

    public function __construct(RbacService $rbacService)
    {
        $this->rbacService = $rbacService;
    }

    /**
     * List admin users (paginated) with their roles.
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::whereIn('user_type', ['admin', 'super_admin'])
            ->with('adminRoles')
            ->paginate(15);

        return response()->json($users);
    }

    /**
     * Create a new admin user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fullname' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8',
            'role_id' => 'required|exists:roles,id',
        ]);

        $user = $this->rbacService->createAdminUser(
            data: $validated,
            actorId: auth()->id()
        );

        $user->load('adminRoles');

        return response()->json(['data' => $user], 201);
    }

    /**
     * Update an existing admin user.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'fullname' => 'sometimes|string',
            'email' => "sometimes|email|unique:users,email,{$id}",
            'password' => 'sometimes|min:8',
            'role_id' => 'sometimes|exists:roles,id',
        ]);

        $user = $this->rbacService->updateAdminUser(
            userId: $id,
            data: $validated,
            actorId: auth()->id()
        );

        $user->load('adminRoles');

        return response()->json(['data' => $user]);
    }

    /**
     * Deactivate an admin user.
     */
    public function deactivate(int $id): JsonResponse
    {
        $this->rbacService->deactivateAdmin(
            userId: $id,
            actorId: auth()->id()
        );

        return response()->json(['message' => 'Admin user deactivated successfully']);
    }

    /**
     * Activate an admin user.
     */
    public function activate(int $id): JsonResponse
    {
        $this->rbacService->activateAdmin(
            userId: $id,
            actorId: auth()->id()
        );

        return response()->json(['message' => 'Admin user activated successfully']);
    }
}
