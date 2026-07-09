<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RbacAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'rbac_audit_logs';

    protected $fillable = [
        'actor_id',
        'action',
        'target_type',
        'target_id',
        'previous_state',
        'new_state',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'previous_state' => 'array',
        'new_state' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Get the actor (user) who performed the action.
     */
    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Override delete to enforce audit log immutability.
     *
     * @throws \RuntimeException
     */
    public function delete()
    {
        throw new \RuntimeException('Audit log entries cannot be deleted.');
    }

    /**
     * Override update to enforce audit log immutability.
     *
     * @param array $attributes
     * @param array $options
     * @return bool
     * @throws \RuntimeException
     */
    public function update(array $attributes = [], array $options = [])
    {
        throw new \RuntimeException('Audit log entries cannot be updated.');
    }
}
