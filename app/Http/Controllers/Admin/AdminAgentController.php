<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentEarning;
use App\Models\AgentWithdrawal;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\DeliveryTierSetting;
use App\Models\Order;
use App\Responser\JsonResponser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminAgentController extends Controller
{
    /**
     * List all agents for admin with earnings
     */
    public function index(Request $request): JsonResponse
    {
        // $perPage = $request->query('per_page', 15);
        // $search = $request->query('search');

        // $query = User::where('user_type', 'agent')
        //     ->with('agentBankDetails')
        //     ->when($search, fn($q) => $q->where(function ($q2) use ($search) {
        //         $q2->where('fullname', 'like', "%{$search}%")
        //             ->orWhere('email', 'like', "%{$search}%");
        //     }))
        //     ->orderByDesc('created_at');

        // $agents = $query->paginate($perPage);

        // $agentIds = $agents->pluck('id');

        // $totalEarnings = AgentEarning::whereIn('agent_id', $agentIds)
        //     ->selectRaw('agent_id, SUM(amount) as total')
        //     ->groupBy('agent_id')
        //     ->pluck('total', 'agent_id');

        // $pendingWithdrawals = AgentWithdrawal::whereIn('agent_id', $agentIds)
        //     ->where('status', 'pending')
        //     ->selectRaw('agent_id, SUM(amount) as total')
        //     ->groupBy('agent_id')
        //     ->pluck('total', 'agent_id');

        // $formatted = $agents->map(function ($agent) use ($totalEarnings, $pendingWithdrawals) {
        //     $bank = $agent->agentBankDetails;
        //     $accountNumber = $bank && $bank->account_number
        //         ? substr($bank->account_number, 0, 3) . '****' . substr($bank->account_number, -3)
        //         : null;

        //     return [
        //         'id' => (string) $agent->id,
        //         'name' => $agent->fullname,
        //         'email' => $agent->email,
        //         'bank_name' => $bank->bank_name ?? null,
        //         'account_number' => $accountNumber,
        //         'total_earnings' => (float) ($totalEarnings[$agent->id] ?? 0),
        //         'pending_withdrawal' => (float) ($pendingWithdrawals[$agent->id] ?? 0),
        //         'status' => $agent->is_active ? 'active' : 'blocked',
        //     ];
        // });

        // return response()->json([
        //     'data' => $formatted,
        //     'pagination' => [
        //         'currentPage' => $agents->currentPage(),
        //         'lastPage' => $agents->lastPage(),
        //         'perPage' => $agents->perPage(),
        //         'total' => $agents->total(),
        //     ],
        //     'summary' => [
        //         'total_agents' => User::where('user_type', 'agent')->count(),
        //         'total_earnings' => (float) AgentEarning::sum('amount'),
        //         'pending_withdrawals' => (float) AgentWithdrawal::where('status', 'pending')->sum('amount'),
        //     ],
        // ]);
          $agents = User::where('user_type', 'agent')
            ->select(
                'id', 'fullname', 'email', 'phoneno',
                'is_delivery_agent', 'delivery_agent_application_status',
                'delivery_agent_tier', 'tier_upgrade_status',
                'main_wallet', 'security_wallet_deposit', 'created_at'
            )
            ->with(['agentBankDetails' => function ($query) {
    $query->select('agent_bank_details.user_id', 'agent_bank_details.bank_name', 'agent_bank_details.account_number');
}])
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($agent) {
                $totalEarnings = $agent->agentEarnings()->sum('amount');
                $pendingWithdrawal = $agent->agentWithdrawals()
                    ->where('status', 'pending')
                    ->sum('amount');
 
                $tierNumber = 1;
                if ($agent->delivery_agent_tier) {
                    preg_match('/tier_(\d+)/', $agent->delivery_agent_tier, $matches);
                    $tierNumber = $matches[1] ?? 1;
                }
 
                return [
                    'id' => $agent->id,
                    'name' => $agent->fullname,
                    'email' => $agent->email,
                    'phone' => $agent->phoneno,
                    'is_delivery_agent' => (bool) $agent->is_delivery_agent,
                    'delivery_agent_application_status' => $agent->delivery_agent_application_status,
                    'delivery_tier' => (int) $tierNumber,
                    'tier_upgrade_status' => $agent->tier_upgrade_status,
                    'bank_name' => $agent->agentBankDetails?->bank_name,
                    'account_number' => $agent->agentBankDetails?->account_number,
                    'total_earnings' => (float) $totalEarnings,
                    'pending_withdrawal' => (float) $pendingWithdrawal,
                    'wallet_balance' => (float) $agent->main_wallet,
                    'security_deposit' => (float) $agent->security_wallet_deposit,
                 'created_at' => $agent->created_at?->toIso8601String() ?? null,
                ];
            });
 
        return JsonResponser::send(false, 'Agents loaded.', $agents, 200);
    
    }

    /**
     * Get single agent details
     */
    public function show(int $id): JsonResponse
    {
        $agent = User::where('user_type', 'agent')->with('agentBankDetails')->find($id);

        if (!$agent) {
            return response()->json(['error' => 'Agent not found'], 404);
        }

        $totalEarnings = AgentEarning::where('agent_id', $id)->sum('amount');
        $pendingWithdrawal = AgentWithdrawal::where('agent_id', $id)->where('status', 'pending')->sum('amount');
        $bank = $agent->agentBankDetails;

        return response()->json([
            'data' => [
                'id' => (string) $agent->id,
                'name' => $agent->fullname,
                'email' => $agent->email,
                'bank_name' => $bank->bank_name ?? null,
                'account_number' => $bank->account_number ?? null,
                'account_name' => $bank->account_name ?? null,
                'total_earnings' => (float) $totalEarnings,
                'pending_withdrawal' => (float) $pendingWithdrawal,
                'status' => $agent->is_active ? 'active' : 'blocked',
            ],
        ]);
    }

    /**
     * Approve delivery agent application
     */
    public function approveDeliveryAgent(int $id): JsonResponse
    {
        $agent = User::where('user_type', 'agent')->find($id);

        if (!$agent) {
            return response()->json(['error' => 'Agent not found'], 404);
        }

        if ($agent->delivery_agent_application_status !== 'pending') {
            return response()->json(['error' => 'No pending application found'], 400);
        }

        $agent->update([
            'is_delivery_agent' => true,
            'delivery_agent_application_status' => 'approved',
        ]);

        return response()->json([
            'message' => 'Delivery agent application approved successfully',
            'data' => [
                'id' => (string) $agent->id,
                'name' => $agent->fullname,
                'is_delivery_agent' => true,
                'security_wallet_deposit' => (float) $agent->security_wallet_deposit ?? 0
            ],
        ]);
    }

    /**
     * Reject delivery agent application
     */
    public function rejectDeliveryAgent(int $id): JsonResponse
    {
        $agent = User::where('user_type', 'agent')->find($id);

        if (!$agent) {
            return response()->json(['error' => 'Agent not found'], 404);
        }

        if ($agent->delivery_agent_application_status !== 'pending') {
            return response()->json(['error' => 'No pending application found'], 400);
        }

        // Refund security deposit to main wallet
        $securityDeposit = $agent->security_wallet_deposit;

        $agent->update([
            'is_delivery_agent' => false,
            'delivery_agent_application_status' => 'rejected',
            'security_wallet_deposit' => 0,
        ]);

        $agent->increment('main_wallet', $securityDeposit);

        return response()->json([
            'message' => 'Delivery agent application rejected. Security deposit refunded.',
            'data' => [
                'id' => (string) $agent->id,
                'name' => $agent->fullname,
                'refunded_amount' => (float) $securityDeposit,
                'wallet_balance' => (float) $agent->fresh()->main_wallet,
            ],
        ]);
    }


     /**
     * Get pending delivery agent applications
     */
    public function getDeliveryApplications(Request $request)
    {
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
 
        // Set to Tier 1 when approved
        $tierConfig = DeliveryTierSetting::getForTier(1);
        if (!$tierConfig) {
            return JsonResponser::send(true, 'Tier 1 configuration not found.', null, 500);
        }
 
        $agent->update([
            'delivery_agent_application_status' => $status,
            'is_delivery_agent' => $approved ? true : false,
            'delivery_agent_tier' => $approved ? 'tier_1' : null,
        ]);
 
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
        $upgrades = User::where('tier_upgrade_status', 'pending')
            ->where('user_type', 'agent')
            ->select(
                'id', 'fullname', 'email', 'delivery_agent_tier',
                'tier_upgrade_status', 'security_wallet_deposit',
                'tier_upgrade_completed_deliveries_snapshot', 'tier_upgrade_requested_at'
            )
            ->orderByDesc('tier_upgrade_requested_at')
            ->get()
            ->map(function ($agent) {
                $currentTierNumber = 1;
                if ($agent->delivery_agent_tier) {
                    preg_match('/tier_(\d+)/', $agent->delivery_agent_tier, $matches);
                    $currentTierNumber = $matches[1] ?? 1;
                }
 
                $deliveriesCompleted = (int) ($agent->tier_upgrade_completed_deliveries_snapshot ?? 0);
 
                return [
                    'id' => $agent->id,
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->fullname,
                    'agent_email' => $agent->email,
                    'current_tier' => $currentTierNumber,
                    'requested_tier' => $currentTierNumber + 1,
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
            // Extract current tier from delivery_agent_tier (format: "tier_1", "tier_2")
            $currentTierNumber = 1;
            if ($agent->delivery_agent_tier) {
                preg_match('/tier_(\d+)/', $agent->delivery_agent_tier, $matches);
                $currentTierNumber = $matches[1] ?? 1;
            }
 
            $newTierNumber = $currentTierNumber + 1;
 
            // Verify the agent meets requirements for new tier
            $tierConfig = DeliveryTierSetting::getForTier($newTierNumber);
            if (!$tierConfig) {
                return JsonResponser::send(true, 'Tier ' . $newTierNumber . ' configuration not found.', null, 500);
            }
 
            $completedDeliveries = (int) ($agent->tier_upgrade_completed_deliveries_snapshot ?? 0);
            if ($completedDeliveries < $tierConfig->min_completed_deliveries) {
                return JsonResponser::send(
                    true,
                    sprintf(
                        'Agent does not meet delivery requirement. Needs %d, has %d.',
                        $tierConfig->min_completed_deliveries,
                        $completedDeliveries
                    ),
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
                    sprintf(
                        'Security deposit does not meet tier requirements. Must be between ₦%s and ₦%s.',
                        number_format($tierConfig->min_security_deposit),
                        number_format($tierConfig->max_security_deposit)
                    ),
                    null,
                    400
                );
            }
 
            // Approve upgrade
            $agent->update([
                'delivery_agent_tier' => 'tier_' . $newTierNumber,
                'tier_upgrade_status' => 'approved',
                'tier_upgrade_completed_deliveries_snapshot' => null,
                'tier_upgrade_requested_at' => null,
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
        }
 
        return JsonResponser::send(
            false,
            'Tier upgrade ' . ($approved ? 'approved' : 'rejected') . '.',
            [
                'agent_id' => $agent->id,
                'status' => $approved ? 'approved' : 'rejected',
            ],
            200
        );
    }
 
    /**
     * Get all delivery tier configurations
     */
    public function getDeliveryTierConfigs(Request $request)
    {
        $configs = DeliveryTierSetting::orderBy('tier')->get();
 
        return JsonResponser::send(false, 'Tier configurations loaded.', $configs, 200);
    }
 
    /**
     * Create new tier configuration
     */
    public function createDeliveryTierConfig(Request $request)
    {
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
        $lastTier = DeliveryTierSetting::max('tier') ?? 0;
        $data['tier'] = $lastTier + 1;
 
        $config = DeliveryTierSetting::create($data);
 
        return JsonResponser::send(false, 'Tier configuration created.', $config, 201);
    }
 
    /**
     * Update tier configuration
     */
    public function updateDeliveryTierConfig(Request $request, $configId)
    {
        $config = DeliveryTierSetting::findOrFail($configId);
 
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
     * Delete/deactivate tier configuration
     */
    public function deleteDeliveryTierConfig(Request $request, $configId)
    {
        $config = DeliveryTierSetting::findOrFail($configId);
 
        // Soft delete by marking inactive
        $config->update(['active' => false]);
 
        return JsonResponser::send(false, 'Tier configuration deactivated.', null, 200);
    }

    /**
     * Manually update agent tier (admin override)
     */
    public function updateAgentTier(Request $request, $agentId)
    {
        $validator = Validator::make($request->all(), [
            'tier' => 'required|integer|min:1|max:5',
            'notes' => 'sometimes|max:500',
        ]);

        if ($validator->fails()) {
            return JsonResponser::send(true, $validator->errors()->first(), null, 422);
        }

        $agent = User::findOrFail($agentId);
        if ($agent->user_type !== 'agent') {
            return JsonResponser::send(true, 'User is not an agent.', null, 400);
        }

        $tierNumber = $request->integer('tier');
        $tierConfig = DeliveryTierSetting::getForTier($tierNumber);
        if (!$tierConfig) {
            return JsonResponser::send(true, 'Tier ' . $tierNumber . ' configuration not found.', null, 500);
        }

        // Clear any pending tier upgrade status
        $agent->update([
            'delivery_agent_tier' => 'tier_' . $tierNumber,
            'tier_upgrade_status' => null,
            'tier_upgrade_completed_deliveries_snapshot' => null,
            'tier_upgrade_requested_at' => null,
        ]);

        return JsonResponser::send(
            false,
            'Agent tier updated to Tier ' . $tierNumber . '.',
            [
                'agent_id' => $agent->id,
                'new_tier' => $tierNumber,
            ],
            200
        );
    }

    /**
     * Update delivery agent application status (admin override)
     */
    public function updateDeliveryAgentStatus(Request $request, $agentId)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,approved,rejected',
            'notes' => 'sometimes|max:500',
        ]);

        if ($validator->fails()) {
            return JsonResponser::send(true, $validator->errors()->first(), null, 422);
        }

        $agent = User::findOrFail($agentId);
        if ($agent->user_type !== 'agent') {
            return JsonResponser::send(true, 'User is not an agent.', null, 400);
        }

        $status = $request->input('status');

        // If approving, set to Tier 1 and mark as delivery agent
        if ($status === 'approved') {
            $agent->update([
                'delivery_agent_application_status' => 'approved',
                'is_delivery_agent' => true,
                'delivery_agent_tier' => 'tier_1',
            ]);
        } elseif ($status === 'rejected') {
            // If rejecting, remove delivery agent status and tier
            $agent->update([
                'delivery_agent_application_status' => 'rejected',
                'is_delivery_agent' => false,
                'delivery_agent_tier' => null,
            ]);
        } else {
            // If setting back to pending
            $agent->update([
                'delivery_agent_application_status' => 'pending',
                'is_delivery_agent' => false,
                'delivery_agent_tier' => null,
            ]);
        }

        return JsonResponser::send(
            false,
            'Delivery agent status updated to ' . $status . '.',
            [
                'agent_id' => $agent->id,
                'status' => $status,
            ],
            200
        );
    }
}
