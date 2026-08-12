<?php

namespace App\Services;

use App\Models\AmbassadorTerritory;
use App\Models\AmbassadorTerritoryAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class AmbassadorTerritoryService
{
    /**
     * Tier-to-territory scope mapping.
     * Tier 4 = community, Tier 5 = lga, Tier 6 = state, Tier 7 = national.
     */
    const TIER_SCOPE_MAP = [
        4 => 'community',
        5 => 'lga',
        6 => 'state',
        7 => 'national',
    ];

    /**
     * Validate that a territory scope matches the expected scope for a given tier.
     *
     * Returns true if the scope matches the tier's expected territory scope.
     * For tiers below 4, any scope is invalid (non-ambassador tiers have no territory).
     *
     * @param int $tier The ambassador's badge tier
     * @param string $scope The territory scope to validate
     * @return bool
     */
    public function validateScopeForTier(int $tier, string $scope): bool
    {
        if ($tier < 4) {
            return false;
        }

        if (!isset(self::TIER_SCOPE_MAP[$tier])) {
            return false;
        }

        return self::TIER_SCOPE_MAP[$tier] === $scope;
    }

    /**
     * Assign a territory to an ambassador.
     *
     * Validates that the territory scope matches the ambassador's badge tier,
     * unassigns any current primary territory, creates a new assignment,
     * and updates the user's ambassador_territory_id foreign key.
     *
     * @param User $ambassador The ambassador user
     * @param AmbassadorTerritory $territory The territory to assign
     * @param string|null $notes Optional notes for the assignment
     * @return AmbassadorTerritoryAssignment The new assignment record
     *
     * @throws InvalidArgumentException If territory scope does not match ambassador's tier
     */
    public function assignTerritory(User $ambassador, AmbassadorTerritory $territory, ?string $notes = null): AmbassadorTerritoryAssignment
    {
        $badgeTier = $ambassador->ambassador_badge_tier ?? $ambassador->delivery_tier;

        // Validate that the territory scope matches the ambassador's tier
        if (!$this->validateScopeForTier($badgeTier, $territory->scope)) {
            $expectedScope = self::TIER_SCOPE_MAP[$badgeTier] ?? 'none';
            throw new InvalidArgumentException(
                "Territory scope '{$territory->scope}' does not match the expected scope '{$expectedScope}' for tier {$badgeTier}."
            );
        }

        // Unassign current primary territory if one exists
        $this->unassignTerritory($ambassador);

        // Create new territory assignment
        $assignment = AmbassadorTerritoryAssignment::create([
            'ambassador_id' => $ambassador->id,
            'territory_id' => $territory->id,
            'assigned_at' => Carbon::now(),
            'is_primary' => true,
            'notes' => $notes,
        ]);

        // Update user's ambassador_territory_id foreign key
        $ambassador->ambassador_territory_id = $territory->id;
        $ambassador->save();

        return $assignment;
    }

    /**
     * Unassign the current primary territory from an ambassador.
     *
     * Sets unassigned_at on the active primary assignment and clears
     * the user's ambassador_territory_id.
     *
     * @param User $ambassador The ambassador user
     * @return void
     */
    public function unassignTerritory(User $ambassador): void
    {
        // Find the current active primary assignment
        $activeAssignment = AmbassadorTerritoryAssignment::where('ambassador_id', $ambassador->id)
            ->where('is_primary', true)
            ->whereNull('unassigned_at')
            ->first();

        if ($activeAssignment) {
            $activeAssignment->unassigned_at = Carbon::now();
            $activeAssignment->save();
        }

        // Clear the user's territory FK
        $ambassador->ambassador_territory_id = null;
        $ambassador->save();
    }

    /**
     * Get the full territory assignment history for an ambassador.
     *
     * Returns all assignments ordered by assigned_at descending (most recent first).
     *
     * @param User $ambassador The ambassador user
     * @return Collection
     */
    public function getAssignmentHistory(User $ambassador): Collection
    {
        return AmbassadorTerritoryAssignment::where('ambassador_id', $ambassador->id)
            ->orderBy('assigned_at', 'desc')
            ->get();
    }
}
