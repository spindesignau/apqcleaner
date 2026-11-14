<?php

namespace App\Actions\Cancellations;

use App\Enums\PricingModel;
use App\Models\Appointment;
use App\Models\Cancellation;
use Illuminate\Support\Carbon;

/**
 * Works out whether a cancellation attracts a fee and how much (ex GST), from the client's rule:
 *   window: client.cancellation_window_hours (default 24)
 *   type:   percent_of_visit | fixed | none;  value: % or $ amount
 * Only client-caused reasons (cancelled by client, no access) are ever fee-eligible.
 */
class CancellationFee
{
    /** @return array{hours_notice: float, eligible: bool, amount: ?float, visit_value: ?float, window: int} */
    public function quote(Appointment $appointment, string $reason, Carbon $noticeGivenAt): array
    {
        $client = $appointment->client;
        $hours = round($noticeGivenAt->diffInSeconds($appointment->scheduled_start, false) / 3600, 2);
        $window = (int) ($client->cancellation_window_hours ?: 24);
        $visitValue = $this->visitValue($appointment);

        $eligible = in_array($reason, Cancellation::CLIENT_REASONS, true)
            && $hours < $window
            && $client->cancellation_fee_type !== 'none';

        $amount = null;
        if ($eligible) {
            $amount = $client->cancellation_fee_type === 'fixed'
                ? (float) $client->cancellation_fee_value
                : ($visitValue === null ? null : round($visitValue * (float) $client->cancellation_fee_value / 100, 2));
        }

        return ['hours_notice' => $hours, 'eligible' => $eligible && $amount !== null && $amount > 0, 'amount' => $amount, 'visit_value' => $visitValue, 'window' => $window];
    }

    /** The visit's value ex GST: fixed price, or scheduled hours × hourly rate. */
    public function visitValue(Appointment $appointment): ?float
    {
        $price = $appointment->price ?? $appointment->client->currentPrice();
        if ($price === null) {
            return null;
        }

        return ($appointment->pricing_model ?? $appointment->client->pricing_model) === PricingModel::Hourly
            ? round($appointment->durationMinutes() / 60 * (float) $price, 2)
            : (float) $price;
    }
}
