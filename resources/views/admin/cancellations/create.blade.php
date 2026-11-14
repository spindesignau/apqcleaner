@php
    $client = $appointment->client;
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-layouts.admin :title="__('cancellations.form_title', ['client' => $client->display_name])">
    <a href="{{ route('admin.cancellations.index') }}" class="text-sm font-semibold text-navy-500 hover:text-navy">← {{ __('cancellations.title') }}</a>

    <div class="mt-4 grid max-w-5xl gap-6 lg:grid-cols-[1fr_20rem]">
        {{-- Step 1: reason + notice; "Recalculate" reloads the fee suggestion. --}}
        <form method="GET" class="card space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="font-display text-lg font-semibold">{{ __('cancellations.visit', ['date' => ucfirst($appointment->localStart()->translatedFormat(__('dates.day_short'))), 'time' => $appointment->timeRange()]) }}</p>
                <x-countdown-chip :appointment="$appointment" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="reason_q" class="label">{{ __('cancellations.fields.reason') }}</label>
                    <select id="reason_q" name="reason" class="input" onchange="this.form.submit()">
                        @foreach (\App\Models\Cancellation::REASONS as $r)<option value="{{ $r }}" @selected($reason === $r)>{{ __('cancellations.reasons.'.$r) }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="notice_q" class="label">{{ __('cancellations.fields.notice') }}</label>
                    <input id="notice_q" type="datetime-local" name="notice" value="{{ $notice->format('Y-m-d\TH:i') }}" max="{{ now(config('apq.display_timezone'))->format('Y-m-d\TH:i') }}" class="input min-w-0">
                    <button class="mt-2 text-sm font-semibold underline">↻ {{ __('cancellations.recalculate') }}</button>
                </div>
            </div>
            <p class="font-semibold">{{ __('cancellations.notice_hours', ['hours' => rtrim(rtrim(number_format(max(0, $quote['hours_notice']), 1), '0'), '.')]) }}</p>
            <p @class(['rounded-xl px-4 py-3 text-[15px]', 'bg-amber-50 text-amber' => $quote['eligible'], 'bg-sage-50 text-sage-700' => ! $quote['eligible']])>
                @if (! in_array($reason, \App\Models\Cancellation::CLIENT_REASONS, true)) {{ __('cancellations.not_client_reason') }}
                @elseif ($client->cancellation_fee_type === 'none') {{ __('cancellations.no_fee_rule') }}
                @elseif ($quote['eligible']) {{ __('cancellations.inside_window', ['window' => $quote['window']]) }}
                @else {{ __('cancellations.outside_window', ['window' => $quote['window']]) }}
                @endif
            </p>
        </form>

        {{-- Step 2: decide and confirm. --}}
        <form method="POST" action="{{ route('admin.cancellations.store', $appointment) }}" class="card h-fit space-y-4"
              x-data="{ decision: 'charged', mode: @js($defaultMode) }">
            @csrf
            <input type="hidden" name="reason" value="{{ $reason }}">
            <input type="hidden" name="notice_date" value="{{ $notice->toDateString() }}">
            <input type="hidden" name="notice_time" value="{{ $notice->format('H:i') }}">
            <input type="hidden" name="decision" :value="decision" value="charged">

            @if ($quote['eligible'])
                <p class="text-[15px] font-semibold">
                    {{ $client->cancellation_fee_type === 'fixed'
                        ? __('cancellations.suggested_fixed', ['amount' => $money($quote['amount'])])
                        : __('cancellations.suggested', ['amount' => $money($quote['amount']), 'rule' => rtrim(rtrim(number_format((float) $client->cancellation_fee_value, 2), '0'), '.').'%', 'value' => $money($quote['visit_value'])]) }}
                </p>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" @click="decision = 'charged'" :class="decision === 'charged' ? 'bg-navy text-offwhite border-navy' : 'border-sand-600'" class="min-h-11 rounded-xl border-2 font-semibold">{{ __('cancellations.charge') }}</button>
                    <button type="button" @click="decision = 'waived'" :class="decision === 'waived' ? 'bg-navy text-offwhite border-navy' : 'border-sand-600'" class="min-h-11 rounded-xl border-2 font-semibold">{{ __('cancellations.waive') }}</button>
                </div>
                <div x-show="decision === 'charged'" x-transition:enter="apq-enter" x-transition:enter-start="apq-enter-start" x-transition:enter-end="apq-enter-end" x-transition:leave="apq-leave" x-transition:leave-start="apq-leave-start" x-transition:leave-end="apq-leave-end" class="space-y-3">
                    <div>
                        <label for="amount" class="label">{{ __('cancellations.fields.amount') }}</label>
                        <div class="relative"><span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-navy-500">$</span>
                            <input id="amount" name="amount" type="number" step="0.01" min="0" value="{{ old('amount', $quote['amount']) }}" class="input pl-8"></div>
                        <x-field-error name="amount" />
                    </div>
                    <div>
                        <label for="mode" class="label">{{ __('cancellations.fields.mode') }}</label>
                        <select id="mode" name="mode" x-model="mode" class="input">
                            @foreach (['draft', 'authorised'] as $m)<option value="{{ $m }}">{{ __('xero.modes.'.$m) }}</option>@endforeach
                        </select>
                    </div>
                    <label class="flex items-center gap-3 text-[15px]" x-show="mode === 'authorised'" x-cloak x-transition:enter="apq-enter" x-transition:enter-start="apq-enter-start" x-transition:enter-end="apq-enter-end" x-transition:leave="apq-leave" x-transition:leave-start="apq-leave-start" x-transition:leave-end="apq-leave-end">
                        <input type="checkbox" name="email" value="1" class="size-5 rounded border-sand-600 text-navy focus:ring-sage"> {{ __('cancellations.fields.email') }}
                    </label>
                </div>
                <div x-show="decision === 'waived'" x-cloak x-transition:enter="apq-enter" x-transition:enter-start="apq-enter-start" x-transition:enter-end="apq-enter-end" x-transition:leave="apq-leave" x-transition:leave-start="apq-leave-start" x-transition:leave-end="apq-leave-end">
                    <label for="waive_reason" class="label">{{ __('cancellations.fields.waive_reason') }}</label>
                    <input id="waive_reason" name="waive_reason" maxlength="255" value="{{ old('waive_reason') }}" class="input">
                    <x-field-error name="waive_reason" />
                </div>
            @endif

            <div>
                <label for="reason_note" class="label">{{ __('cancellations.fields.reason_note') }}</label>
                <input id="reason_note" name="reason_note" maxlength="255" value="{{ old('reason_note') }}" class="input">
            </div>
            <label class="flex items-center gap-3 text-[15px]">
                <input type="checkbox" name="notify_client" value="1" @checked($notifyClient) class="size-5 rounded border-sand-600 text-navy focus:ring-sage"> {{ __('cancellations.fields.notify_client') }}
            </label>
            @if ($appointment->staff->isNotEmpty())
                <label class="flex items-center gap-3 text-[15px]">
                    <input type="checkbox" name="notify_staff" value="1" @checked($notifyStaff) class="size-5 rounded border-sand-600 text-navy focus:ring-sage"> {{ __('cancellations.fields.notify_staff') }}
                </label>
            @endif
            <x-field-error name="reason" />
            <button type="submit" class="btn-danger">{{ __('cancellations.confirm') }}</button>
        </form>
    </div>
</x-layouts.admin>
