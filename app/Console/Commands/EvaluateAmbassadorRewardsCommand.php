<?php

namespace App\Console\Commands;

use App\Models\AmbassadorAnnualReward;
use App\Services\AmbassadorRewardService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class EvaluateAmbassadorRewardsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ambassador:evaluate-rewards';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Evaluate pending ambassador annual rewards and create new evaluation periods';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $rewardService = app(AmbassadorRewardService::class);

        $this->info('Starting ambassador reward evaluation...');

        // Find all pending rewards where evaluation_end <= today
        $pendingRewards = AmbassadorAnnualReward::where('status', 'pending')
            ->where('evaluation_end', '<=', Carbon::today())
            ->get();

        $this->info("Found {$pendingRewards->count()} pending reward(s) to evaluate.");

        $evaluated = 0;
        $earned = 0;
        $withheld = 0;

        foreach ($pendingRewards as $reward) {
            $ambassador = $reward->ambassador;

            if (!$ambassador) {
                $this->warn("Reward #{$reward->id}: Ambassador not found, skipping.");
                continue;
            }

            $this->info("Evaluating reward #{$reward->id} for ambassador {$ambassador->name} (ID: {$ambassador->id})...");

            // Evaluate the annual reward
            $rewardService->evaluateAnnualReward($reward);

            // Refresh the reward to get updated status
            $reward->refresh();

            if ($reward->status === 'earned') {
                $earned++;
                $this->info("  -> Reward EARNED. ₦{$reward->reward_amount} credited to wallet.");
            } elseif ($reward->status === 'withheld') {
                $withheld++;
                $this->info("  -> Reward WITHHELD. {$reward->notes}");
            }

            $evaluated++;

            // Create a new evaluation period for the ambassador
            $newPeriod = $rewardService->createEvaluationPeriod($ambassador);
            $this->info("  -> New evaluation period created: {$newPeriod->evaluation_start} to {$newPeriod->evaluation_end}");
        }

        $this->info("Evaluation complete. Evaluated: {$evaluated}, Earned: {$earned}, Withheld: {$withheld}.");

        return 0;
    }
}
