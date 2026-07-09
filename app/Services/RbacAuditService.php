<?php

namespace App\Services;

use App\Models\RbacAuditLog;
use Carbon\Carbon;

class RbacAuditService
{
    /**
     * Log an RBAC audit entry.
     *
     * @param int         $actorId       The user performing the action
     * @param string      $action        The action being performed (e.g., 'role_created', 'permission_synced')
     * @param string      $targetType    The type of entity being acted upon (e.g., 'role', 'user')
     * @param int         $targetId      The ID of the target entity
     * @param array|null  $previousState The state before the action
     * @param array|null  $newState      The state after the action
     * @param array|null  $metadata      Any additional context
     * @return RbacAuditLog
     */
    public function log(
        int $actorId,
        string $action,
        string $targetType,
        int $targetId,
        ?array $previousState = null,
        ?array $newState = null,
        ?array $metadata = null
    ): RbacAuditLog {
        return RbacAuditLog::create([
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'previous_state' => $previousState,
            'new_state' => $newState,
            'metadata' => $metadata,
            'created_at' => Carbon::now(),
        ]);
    }
}
