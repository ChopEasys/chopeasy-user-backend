<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    /**
     * List all permissions grouped by group_name.
     */
    public function index(): JsonResponse
    {
        $permissions = Permission::all()->groupBy('group_name');

        return response()->json(['data' => $permissions]);
    }
}
