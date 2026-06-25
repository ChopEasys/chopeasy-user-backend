<?php

namespace App\Http\Controllers\v1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryTierConfig;
use App\Models\User;
use App\Responser\JsonResponser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminAgentController extends Controller
{
    /**
     * Get all agents with their details
     */
    public function getAgents(Request $request)
    {
        $this->authorize('admin');

        $agents = User::where('user_type', 'agent')
            ->select(
                'id', 'fullname', 'email', 'phoneno',
                'is_delivery_agent', 'delivery_agent_application_status',
                'delivery_tier', 'tier_upgrade_status',
                'main_wallet', 'security_wallet_deposit'
            )
            ->with('agentBankDetails:user_id,bank_name,account_number')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($agent) {
                $totalEarnings = $agent->agentEarnings()->sum('amount');
                $pendingWithdrawal = $agent->agentWithdrawals()
                    ->where('status', 'pending')
                    ->sum('amount');

                return [
                    'id' => $agent->id,
                    'name' => $agent->fullname,
                    'email' => $agent->email,
                    'phone' => $agent->phoneno,
                    'is_delivery_agent' => (bool) $agent->is_delivery_agent,
                    'delivery_agent_application_status' => $agent->delivery_agent_application_status,
                    'delivery_tier' => (int) ($agent->delivery_tier ?? 1),
                    'tier_upgrade_status' => $agent->tier_upgrade_status,
                    'bank_name' => $agent->agentBankDetails?->bank_name,
                    'account_number' => $agent->agentBankDetails?->account_number,
                    'total_earnings' => (float) $totalEarnings,
                    'pending_withdrawal' => (float) $pendingWithdrawal,
                    'wallet_balance' => (float) $agent->main_wallet,
                    'security_deposit' => (float) $agent->security_wallet_deposit,
                    'created_at' => $agent->created_at->toIso8601String(),
                ];
            });

        return JsonResponser::send(false, 'Agents loaded.', $agents, 200);
    }

    /**
     * Get pending delivery agent applications
     */
    public function getDeliveryApplications(Request $request)
    {
        $this->authorize('admin');

        $applications = User::where('delivery_agent_application_status', 'pending')
            ->where('user_type', 'agent')
            ->select('id', 'fullname', 'email', 'delivery_agent_application_status', 'created_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($agent) {
                return [
                    'id' => $agent->id,
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->fullname,
                    'agent_email' => $agent->email,
                    'status' => 'pending',
                    'applied_at' => $agent->created_at->toIso8601String(),
                ];
            });

        return JsonResponser::send(false, 'Applications loaded.', $applications, 200);
    }

    /**
     * Approve or reject delivery agent application
     */
    public function approveDeliveryApplication(Request $request, $agentId)
    {
        $this->authorize('admin');

        $validator = Validator::make($request->all(), [
            'approved' => 'required|boolean',
            'notes' => 'sometimes|string|max:500',
        ]);

        if ($validator->fails()) {
            return JsonResponser::send(true, $validator->errors()->first(), null, 422);
        }

        $agent = User::findOrFail($agentId);
        if ($agent->user_type !== 'agent') {
            return JsonResponser::send(true, 'User is not an agent.', null, 400);
        }

        $approved = $request->boolean('approved');
        $status = $approved ? 'approved' : 'rejected';

        $agent->update([
            'delivery_agent_application_status' => $status,
            'is_delivery_agent' => $approved ? true : false,
        ]);

        // TODO: Send email notification to agent

        return JsonResponser::send(
            false,
            'Application ' . $status . '.',
            ['agent_id' => $agent->id, 'status' => $status],
            200
        );
    }

    /**
     * Get pending tier upgrade requests
     */
    public function getTierUpgradeRequests(Request $request)
    {
        $this->authorize('admin');

        $upgrades = User::where('tier_upgrade_status', 'pending')
            ->where('user_type', 'agent')
            ->select(
                'id', 'fullname', 'email', 'delivery_tier',
                'tier_upgrade_status', 'security_wallet_deposit',
                'tier_upgrade_completed_deliveries_snapshot', 'tier_upgrade_requested_at'
            )
            ->orderByDesc('tier_upgrade_requested_at')
            ->get()
            ->map(function ($agent) {
                $deliveriesCompleted = (int) ($agent->tier_upgrade_completed_deliveries_snapshot ?? 0);

                return [
                    'id' => $agent->id,
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->fullname,
                    'agent_email' => $agent->email,
                    'current_tier' => (int) ($agent->delivery_tier ?? 1),
                    'requested_tier' => (int) ($agent->delivery_tier ?? 1) + 1,
                    'completed_deliveries' => $deliveriesCompleted,
                    'security_deposit' => (float) $agent->security_wallet_deposit,
                    'status' => 'pending',
                    'requested_at' => $agent->tier_upgrade_requested_at->toIso8601String(),
                ];
            });

        return JsonResponser::send(false, 'Tier upgrade requests loaded.', $upgrades, 200);
    }

    /**
     * Approve or reject tier upgrade request
     */
    public function approveTierUpgrade(Request $request, $agentId)
    {
        $this->authorize('admin');

        $validator = Validator::make($request->all(), [
            'approved' => 'required|boolean',
            'rejection_reason' => 'sometimes|string|max:500',
        ]);

        if ($validator->fails()) {
            return JsonResponser::send(true, $validator->errors()->first(), null, 422);
        }

        $agent = User::findOrFail($agentId);
        if ($agent->user_type !== 'agent') {
            return JsonResponser::send(true, 'User is not an agent.', null, 400);
        }

        if ($agent->tier_upgrade_status !== 'pending') {
            return JsonResponser::send(true, 'No pending tier upgrade for this agent.', null, 400);
        }

        $approved = $request->boolean('approved');

        if ($approved) {
            $currentTier = (int) ($agent->delivery_tier ?? 1);
            $newTier = $currentTier + 1;

            // Verify the agent meets requirements for new tier
            $tierConfig = DeliveryTierConfig::where('tier', $newTier)->first();
            if (!$tierConfig) {
                return JsonResponser::send(true, 'Tier configuration not found.', null, 500);
            }

            $completedDeliveries = (int) ($agent->tier_upgrade_completed_deliveries_snapshot ?? 0);
            if ($completedDeliveries < $tierConfig->min_completed_deliveries) {
                return JsonResponser::send(
                    true,
                    'Agent does not meet delivery requirement.',
                    null,
                    400
                );
            }

            $securityDeposit = (float) $agent->security_wallet_deposit;
            if (
                $securityDeposit < $tierConfig->min_security_deposit ||
                $securityDeposit > $tierConfig->max_security_deposit
            ) {
                return JsonResponser::send(
                    true,
                    'Security deposit does not meet tier requirements.',
                    null,
                    400
                );
            }

            // Approve upgrade
            $agent->update([
                'delivery_tier' => $newTier,
                'tier_upgrade_status' => 'approved',
                'tier_upgrade_completed_deliveries_snapshot' => null,
            ]);
        } else {
            // Reject upgrade and refund security deposit
            $agent->update([
                'tier_upgrade_status' => 'rejected',
            ]);

            if ($agent->security_wallet_deposit > 0) {
                $agent->increment('main_wallet', $agent->security_wallet_deposit);
                $agent->update(['security_wallet_deposit' => 0]);
            }

            // TODO: Send rejection email with reason
        }

        // TODO: Send email notification to agent

        return JsonResponser::send(
            false,
            'Tier upgrade ' . ($approved ? 'approved' : 'rejected') . '.',
            [
                'agent_id' => $agent->id,
                'status' => $approved ? 'approved' : 'rejected',
                'new_tier' => $approved ? (int) ($agent->delivery_tier ?? 1) : null,
            ],
            200
        );
    }

    /**
     * Get all delivery tier configurations
     */
    public function getDeliveryTierConfigs(Request $request)
    {
        $this->authorize('admin');

        $configs = DeliveryTierConfig::orderBy('tier')
            ->get()
            ->map(function ($config) {
                return [
                    'id' => $config->id,
                    'tier' => $config->tier,
                    'tier_name' => $config->tier_name,
                    'max_order_amount' => (float) $config->max_order_amount,
                    'min_completed_deliveries' => (int) $config->min_completed_deliveries,
                    'min_security_deposit' => (float) $config->min_security_deposit,
                    'max_security_deposit' => (float) $config->max_security_deposit,
                    'description' => $config->description,
                    'active' => (bool) $config->active,
                    'created_at' => $config->created_at->toIso8601String(),
                    'updated_at' => $config->updated_at->toIso8601String(),
                ];
            });

        return JsonResponser::send(false, 'Tier configurations loaded.', $configs, 200);
    }

    /**
     * Create new tier configuration
     */
    public function createDeliveryTierConfig(Request $request)
    {
        $this->authorize('admin');

        $validator = Validator::make($request->all(), [
            'tier_name' => 'required|string|max:100',
            'max_order_amount' => 'required|numeric|min:0',
            'min_completed_deliveries' => 'required|integer|min:0',
            'min_security_deposit' => 'required|numeric|min:0',
            'max_security_deposit' => 'required|numeric|min:0',
            'description' => 'sometimes|string|max:500',
            'active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return JsonResponser::send(true, $validator->errors()->first(), null, 422);
        }

        $data = $validator->validated();
        $data['active'] = $data['active'] ?? true;

        // Find next tier number
        $lastTier = DeliveryTierConfig::max('tier') ?? 0;
        $data['tier'] = $lastTier + 1;

        $config = DeliveryTierConfig::create($data);

        return JsonResponser::send(false, 'Tier configuration created.', $config, 201);
    }

    /**
     * Update tier configuration
     */
    public function updateDeliveryTierConfig(Request $request, $configId)
    {
        $this->authorize('admin');

        $config = DeliveryTierConfig::findOrFail($configId);

        $validator = Validator::make($request->all(), [
            'tier_name' => 'sometimes|string|max:100',
            'max_order_amount' => 'sometimes|numeric|min:0',
            'min_completed_deliveries' => 'sometimes|integer|min:0',
            'min_security_deposit' => 'sometimes|numeric|min:0',
            'max_security_deposit' => 'sometimes|numeric|min:0',
            'description' => 'sometimes|string|max:500',
            'active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return JsonResponser::send(true, $validator->errors()->first(), null, 422);
        }

        $config->update($validator->validated());

        return JsonResponser::send(false, 'Tier configuration updated.', $config, 200);
    }

    /**
     * Delete tier configuration
     */
    public function deleteDeliveryTierConfig(Request $request, $configId)
    {
        $this->authorize('admin');

        $config = DeliveryTierConfig::findOrFail($configId);

        // Soft delete or mark as inactive
        $config->update(['active' => false]);

        return JsonResponser::send(false, 'Tier configuration deleted.', null, 200);
    }
}