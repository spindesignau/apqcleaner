@php $tz = config('apq.display_timezone'); @endphp
<x-layouts.admin :title="__('cancellations.title')">
    <section class="card max-w-6xl !p-0 overflow-hidden">
        <div class="p-5 pb-3">
            <h2 class="text-lg">{{ __('cancellations.monitor') }}</h2>
            <p class="text-sm text-navy-500">{{ __('cancellations.monitor_help') }}</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[56rem] text-left text-[14px]">
                <thead class="bg-canvas-50 text-xs font-bold tracking-wider text-navy-500 uppercase">
                    <tr>@foreach (['when', 'client', 'cleaners', 'reminder'] as $c)<th class="px-4 py-2.5">{{ __('cancellations.cols.'.$c) }}</th>@endforeach<th></th></tr>
                </thead>
                <tbody class="divide-y divide-sand">
                    @forelse ($upcoming as $visit)
                        @php $reminder = $visit->smsMessages->first(); @endphp
                        <tr class="align-top">
                            <td class="px-4 py-3">
                                <p class="font-semibold">{{ ucfirst($visit->localStart()->translatedFormat(__('dates.day_short'))) }} · {{ $visit->timeRange() }}</p>
                                <x-countdown-chip :appointment="$visit" class="mt-1" />
                            </td>
                            <td class="px-4 py-3"><a href="{{ route('admin.clients.show', $visit->client) }}" class="font-semibold hover:underline">{{ $visit->client->display_name }}</a></td>
                            <td class="px-4 py-3"><x-staff-names :staff="$visit->staff" /></td>
                            <td class="px-4 py-3">
                                @php $rs = $reminder?->status ?? 'none'; @endphp
                                <span @class(['badge !py-0.5 !text-[12px]', 'badge-sage' => in_array($rs, ['sent', 'delivered']), 'badge-alert' => $rs === 'failed', 'badge-sand' => in_array($rs, ['none', 'skipped', 'queued'])])>{{ __('cancellations.reminder_status.'.$rs) }}</span>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <form method="POST" action="{{ route('admin.appointments.remind', $visit) }}" class="inline">@csrf<button class="mr-3 font-semibold underline">📱 {{ __('cancellations.remind') }}</button></form>
                                <a href="{{ route('admin.cancellations.create', $visit) }}" class="btn-secondary btn-sm !border-alert !text-alert">{{ __('cancellations.cancel') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-navy-500">{{ __('cancellations.none_upcoming') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card mt-6 max-w-6xl !p-0 overflow-hidden">
        <h2 class="p-5 pb-3 text-lg">{{ __('cancellations.recent') }}</h2>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[56rem] text-left text-[14px]">
                <thead class="bg-canvas-50 text-xs font-bold tracking-wider text-navy-500 uppercase">
                    <tr>@foreach (['when', 'client', 'reason', 'notice', 'fee'] as $c)<th class="px-4 py-2.5">{{ __('cancellations.cols.'.$c) }}</th>@endforeach</tr>
                </thead>
                <tbody class="divide-y divide-sand">
                    @forelse ($recent as $c)
                        <tr class="align-top">
                            <td class="px-4 py-3">{{ ucfirst($c->appointment->localStart()->translatedFormat(__('dates.day_short'))) }} · {{ $c->appointment->timeRange() }}</td>
                            <td class="px-4 py-3 font-semibold">{{ $c->appointment->client->display_name }}</td>
                            <td class="px-4 py-3">{{ __('cancellations.reasons.'.$c->reason) }}@if ($c->reason_note)<p class="text-xs text-navy-500">{{ $c->reason_note }}</p>@endif</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ __('cancellations.notice_hours', ['hours' => rtrim(rtrim(number_format(max(0, (float) $c->hours_notice), 1), '0'), '.')]) }}</td>
                            <td class="px-4 py-3">
                                <span @class(['badge !py-0.5', 'badge-amber' => $c->fee_decision === 'charged', 'badge-sand' => $c->fee_decision !== 'charged'])>{{ __('cancellations.decisions.'.$c->fee_decision) }}</span>
                                @if ($c->fee_amount_ex_gst) ${{ number_format((float) $c->fee_amount_ex_gst, 2) }} @endif
                                @if ($c->invoice)<a href="{{ route('admin.invoices.show', $c->invoice) }}" class="ml-1 text-xs font-semibold underline">{{ $c->invoice->xero_invoice_number ?? $c->invoice->reference }}</a>@endif
                                @if ($c->waive_reason)<p class="text-xs text-navy-500">“{{ $c->waive_reason }}”</p>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-navy-500">{{ __('cancellations.none_recent') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.admin>
