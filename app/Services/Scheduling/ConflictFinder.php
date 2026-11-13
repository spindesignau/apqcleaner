<?php

namespace App\Services\Scheduling;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Warns (never blocks) when a cleaner is booked on two overlapping visits.
 */
class ConflictFinder
{
    /**
     * @param  int[]  $staffIds
     * @return Collection<int, array{user: User, appointment: Appointment}>
     */
    public function forSlot(array $staffIds, Carbon $start, Carbon $end, ?int $ignoreId = null): Collection
    {
        if ($staffIds === []) {
            return collect();
        }

        return Appointment::query()->active()
            ->with(['client', 'staff' => fn ($q) => $q->whereIn('users.id', $staffIds)])
            ->whereHas('staff', fn ($q) => $q->whereIn('users.id', $staffIds))
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where('scheduled_start', '<', $end->copy()->utc())
            ->where('scheduled_end', '>', $start->copy()->utc())
            ->get()
            ->flatMap(fn (Appointment $a) => $a->staff->map(fn (User $u) => ['user' => $u, 'appointment' => $a]));
    }

    /**
     * IDs of appointments in the collection that overlap another for the same cleaner.
     *
     * @param  Collection<int, Appointment>  $appointments  (with staff loaded)
     * @return array<int, true>
     */
    public function overlappingIds(Collection $appointments): array
    {
        $byStaff = [];
        foreach ($appointments as $a) {
            if ($a->status->isCancelled()) {
                continue;
            }
            foreach ($a->staff as $u) {
                $byStaff[$u->id][] = $a;
            }
        }

        $ids = [];
        foreach ($byStaff as $list) {
            usort($list, fn ($x, $y) => $x->scheduled_start <=> $y->scheduled_start);
            for ($i = 1, $n = count($list); $i < $n; $i++) {
                for ($j = $i - 1; $j >= 0; $j--) {
                    if ($list[$j]->scheduled_end->gt($list[$i]->scheduled_start)) {
                        $ids[$list[$i]->id] = $ids[$list[$j]->id] = true;
                    }
                }
            }
        }

        return $ids;
    }
}
