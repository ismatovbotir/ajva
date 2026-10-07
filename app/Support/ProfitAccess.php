<?php

namespace App\Support;

use App\Models\User;

/**
 * Who may see profit, cost prices and margins: admins always, everyone else
 * only with the users.can_see_profit flag. Guests (the public monitor has its
 * own "show profit" switch) are never allowed here. Profit data is not just
 * hidden in Blade: callers skip the cost queries and keep it out of payloads
 * and shared caches, using profitKey() as part of those cache keys.
 */
class ProfitAccess
{
    public static function allowed(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && $user->canSeeProfit();
    }

    /** Cache-key fragment: payloads with and without profit never share an entry. */
    public static function profitKey(?bool $allowed = null): string
    {
        return ($allowed ?? self::allowed()) ? 'profit' : 'noprofit';
    }
}
