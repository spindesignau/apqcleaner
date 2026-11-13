@php
    use App\Enums\TimeEntryStatus;
    $fmt = app()->getLocale() === 'es' ? 'H:i' : 'g:ia';
    $locked = $entry->status === TimeEntryStatus::Invoiced || $entry->invoice_line_id;
@endphp
<x-layouts.admin :title="__('timesheets.edit_title', ['name' => $entry->user->name, 'client' => $entry->client->display_name])">
    <a href="{{ route('admin.timesheets.index', request()->only(['from', 'to', 'staff', 'client', 'status'])) }}" class="text-sm font-semibold text-navy-500 hover:text-navy">← {{ __('timesheets.title') }}</a>

    <div class="mt-4 grid max-w-6xl gap-6 lg:grid-cols-[1fr_22rem]">
        <div class="space-y-6">
            <section class="card">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="text-navy-500">{{ __('timesheets.scheduled_for', ['date' => ucfirst($entry->appointment->localStart()->translatedFormat(__('dates.day_short'))), 'time' => $entry->appointment->timeRange()]) }}</p>
                        @if ($entry->original_started_at)
                            <p class="text-sm text-amber">{{ __('timesheets.original_times', ['start' => $entry->original_started_at->tz(config('apq.display_timezone'))->format($fmt), 'end' => $entry->original_ended_at?->tz(config('apq.display_timezone'))->format($fmt) ?? '…']) }}</p>
                        @endif
                    </div>
                    <span class="{{ $entry->status->badgeClass() }}">{{ $entry->status->label() }}</span>
                </div>

                @if ($entry->status === TimeEntryStatus::Running)
                    <div class="mt-4 rounded-xl bg-sage-50 p-4 text-center">
                        <p class="text-sage-700">{{ __('timesheets.running_now') }}</p>
                        <p class="mt-2"><x-job-timer :entry="$entry" /></p>
                        <form method="POST" action="{{ route('admin.timesheets.stop', $entry) }}" class="mt-3">
                            @csrf
                            <button class="btn-stop btn-sm">■ {{ __('timesheets.stop_now') }}</button>
                        </form>
                    </div>
                @else
                    <form method="POST" action="{{ route('admin.timesheets.update', $entry) }}" class="mt-5 space-y-5">
                        @csrf
                        @method('PUT')
                        @foreach (request()->only(['from', 'to', 'staff', 'client', 'status']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                        <fieldset @disabled($locked) class="space-y-5">
                            <div class="grid gap-4 sm:grid-cols-4">
                                <div>
                                    <label for="date" class="label">{{ __('timesheets.fields.date') }}</label>
                                    <input id="date" name="date" type="date" required value="{{ old('date', $entry->localStart()->toDateString()) }}" class="input">
                                </div>
                                <div>
                                    <label for="start_time" class="label">{{ __('timesheets.fields.start_time') }}</label>
                                    <input id="start_time" name="start_time" type="time" required value="{{ old('start_time', $entry->localStart()->format('H:i')) }}" class="input">
                                </div>
                                <div>
                                    <label for="end_time" class="label">{{ __('timesheets.fields.end_time') }}</label>
                                    <input id="end_time" name="end_time" type="time" required value="{{ old('end_time', $entry->localEnd()?->format('H:i')) }}" class="input">
                                    <x-field-error name="end_time" />
                                </div>
                                <div>
                                    <label for="billable_minutes" class="label">{{ __('timesheets.fields.billable_minutes') }}</label>
                                    <input id="billable_minutes" name="billable_minutes" type="number" min="0" max="1440" value="{{ old('billable_minutes', $entry->billable_minutes) }}" class="input">
                                </div>
                            </div>
                            <p class="-mt-2 text-sm text-navy-500">{{ __('timer.minutes', ['n' => $entry->duration_minutes]) }} ({{ \App\Models\TimeEntry::formatMinutes($entry->duration_minutes) }}) · {{ __('timesheets.billable_help') }}</p>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="staff_notes" class="label">{{ __('timesheets.fields.staff_notes') }}</label>
                                    <textarea id="staff_notes" name="staff_notes" rows="3" class="input !min-h-24 py-3">{{ old('staff_notes', $entry->staff_notes) }}</textarea>
                                </div>
                                <div>
                                    <label for="admin_notes" class="label">{{ __('timesheets.fields.admin_notes') }}</label>
                                    <textarea id="admin_notes" name="admin_notes" rows="3" class="input !min-h-24 py-3">{{ old('admin_notes', $entry->admin_notes) }}</textarea>
                                </div>
                            </div>
                            <div>
                                <label for="reason" class="label">{{ __('timesheets.fields.reason') }}</label>
                                <input id="reason" name="reason" maxlength="255" value="{{ old('reason') }}" class="input">
                            </div>
                            <div class="flex flex-wrap gap-3">
                                <button type="submit" class="btn-secondary sm:w-auto">{{ __('timesheets.save') }}</button>
                                @if (in_array($entry->status, [TimeEntryStatus::Stopped, TimeEntryStatus::Submitted], true))
                                    <button type="submit" name="approve" value="1" class="btn-primary sm:w-auto">✓ {{ __('timesheets.save_and_approve') }}</button>
                                @endif
                            </div>
                        </fieldset>
                    </form>
                @endif
                @if ($entry->status === TimeEntryStatus::Approved && $entry->pay_rate !== null)
                    <p class="mt-4 text-sm text-navy-500">🔒 {{ __('timesheets.rates', ['charge' => '$'.number_format((float) $entry->charge_rate, 2), 'pay' => '$'.number_format((float) $entry->pay_rate, 2)]) }}</p>
                @endif
            </section>

            @if (in_array($entry->status, [TimeEntryStatus::Submitted, TimeEntryStatus::Approved], true) && ! $locked)
                <form method="POST" action="{{ route('admin.timesheets.reject', $entry) }}" class="card space-y-3">
                    @csrf
                    <h2 class="text-lg">{{ __('timesheets.send_back') }}</h2>
                    <p class="text-sm text-navy-500">{{ __('timesheets.send_back_help') }}</p>
                    <input name="rejected_reason" required maxlength="255" placeholder="{{ __('timesheets.fields.rejected_reason') }}" class="input">
                    <x-field-error name="rejected_reason" />
                    <button class="btn-secondary btn-sm !border-alert !text-alert">↩ {{ __('timesheets.send_back') }}</button>
                </form>
            @endif
        </div>

        <aside class="card h-fit">
            <h2 class="text-lg">{{ __('timesheets.history') }}</h2>
            <ul class="mt-3 space-y-3 text-[14px]">
                @foreach ($audits as $log)
                    <li>
                        <p>{{ __('admin.audit.'.$log->action, ['actor' => $log->user?->name ?? __('admin.audit.system'), 'subject' => '']) }}</p>
                        @if ($log->reason)<p class="text-navy-500">“{{ $log->reason }}”</p>@endif
                        <p class="text-xs text-navy-500">{{ $log->created_at->tz(config('apq.display_timezone'))->translatedFormat(__('dates.datetime')) }}</p>
                    </li>
                @endforeach
            </ul>
            @if ($entry->start_lat)
                <p class="mt-4 text-sm"><a class="underline" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query={{ $entry->start_lat }},{{ $entry->start_lng }}">📍 {{ __('timesheets.flags.gps') }}</a></p>
            @endif
        </aside>
    </div>
</x-layouts.admin>
