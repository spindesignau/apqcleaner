<?php

namespace App\Actions\Timer;

use App\Models\Client;

/**
 * logged → round up to the client's billing increment → apply the client's minimum.
 */
class CalculateBillableMinutes
{
    public function __invoke(Client $client, int $loggedMinutes): int
    {
        $increment = max(1, (int) $client->billing_increment_minutes);
        $rounded = (int) (ceil($loggedMinutes / $increment) * $increment);

        return max($rounded, (int) $client->minimum_billable_minutes);
    }
}
