@php
    use App\Enums\TimeEntryStatus;
    $client = $job->client;
    $fmt = app()->getLocale() === 'es' ? 'H:i' : 'g:ia';
@endphp
<x-layouts.staff :title="$client->display_name">
    <a href="{{ route('jobs.today') }}" class="inline-flex min-h-11 items-center font-semibold text-navy-500">← {{ __('staff.job.back') }}</a>

    @error('timer')<p class="mb-3 rounded-xl bg-alert-50 px-4 py-3 font-medium text-alert" role="alert">{{ $message }}</p>@enderror

    {{-- ===== Timer / timesheet panel ===== --}}
    @if (! $entry)
        @if ($canStart->allowed())
            <form method="POST" action="{{ route('jobs.start', $job) }}" data-gps data-haptic class="mb-4">
                @csrf
                <x-gps-fields />
                <button type="submit" class="btn-primary !min-h-16 text-xl">▶ {{ __('timer.start_job') }}</button>
            </form>
        @elseif ($canStart->message())
            <p class="mb-4 rounded-xl border border-dashed border-sand-600 px-4 py-3 text-center text-[15px] text-navy-500">⏱ {{ $canStart->message() }}</p>
        @endif
    @elseif ($entry->status === TimeEntryStatus::Running)
        <section class="card mb-4 border-l-[6px] !border-l-sage text-center animate-[apq-pop_420ms_cubic-bezier(0.22,1,0.36,1)]">
            <p class="badge-sage"><span class="size-2.5 animate-pulse rounded-full bg-sage"></span> {{ __('timer.in_progress') }}</p>
            <p class="mt-4"><x-job-timer :entry="$entry" /></p>
            <p class="mt-2 text-navy-500">{{ __('timer.started_at', ['time' => $entry->localStart()->format($fmt)]) }}</p>
            <form method="POST" action="{{ route('jobs.stop', $job) }}" class="mt-5" data-haptic
                  data-confirm="{{ __('timer.confirm_stop', ['client' => $client->display_name]) }}" data-confirm-ok="{{ __('timer.stop_job') }}">
                @csrf
                <button type="submit" class="btn-stop !min-h-16 text-xl">■ {{ __('timer.stop_job') }}</button>
            </form>
        </section>
    @elseif (in_array($entry->status, [TimeEntryStatus::Stopped, TimeEntryStatus::Rejected], true))
        <section class="card mb-4" x-data="{ adjusting: {{ $errors->has('start_time') || $errors->has('end_time') ? 'true' : 'false' }} }">
            <h2 class="text-xl">{{ __('timer.review_title') }}</h2>
            @if ($entry->status === TimeEntryStatus::Rejected)
                <p class="mt-2 rounded-xl bg-alert-50 px-4 py-3 text-[15px] text-alert" role="alert">↩ {{ __('timer.sent_back', ['reason' => $entry->rejected_reason]) }}</p>
            @endif
            @if ($entry->auto_stopped)
                <p class="mt-2 rounded-xl bg-amber-50 px-4 py-3 text-[15px] text-amber">{{ __('timer.auto_stopped_note') }}</p>
            @endif
            <div class="mt-4 grid grid-cols-2 gap-3">
                <div class="rounded-xl border border-sand bg-canvas-50 p-3">
                    <p class="label !mb-0">{{ __('timer.start') }}</p>
                    <p class="font-display text-2xl font-semibold">{{ $entry->localStart()->format($fmt) }}</p>
                </div>
                <div class="rounded-xl border border-sand bg-canvas-50 p-3">
                    <p class="label !mb-0">{{ __('timer.end') }}</p>
                    <p class="font-display text-2xl font-semibold">{{ $entry->localEnd()->format($fmt) }}</p>
                </div>
            </div>
            <p class="mt-5 text-center font-display text-5xl font-semibold leading-none">{{ __('timer.minutes', ['n' => $entry->duration_minutes]) }}</p>
            <p class="mt-2 text-center text-navy-500">{{ \App\Models\TimeEntry::formatMinutes($entry->duration_minutes) }}</p>

            <p class="mt-4 text-center">
                <button type="button" class="font-semibold underline underline-offset-4" @click="adjusting = !adjusting" :aria-expanded="adjusting">{{ __('timer.adjust_times') }} ›</button>
            </p>
            <form method="POST" action="{{ route('jobs.adjust', $job) }}" x-show="adjusting" x-cloak x-transition:enter="apq-enter" x-transition:enter-start="apq-enter-start" x-transition:enter-end="apq-enter-end" x-transition:leave="apq-leave" x-transition:leave-start="apq-leave-start" x-transition:leave-end="apq-leave-end" class="mt-4 space-y-3 rounded-xl bg-canvas-50 p-4">
                @csrf
                @method('PATCH')
                <p class="text-sm text-navy-500">{{ __('timer.adjust_help') }}</p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="start_time" class="label">{{ __('timer.start') }}</label>
                        <input id="start_time" name="start_time" type="time" required value="{{ old('start_time', $entry->localStart()->format('H:i')) }}" class="input">
                    </div>
                    <div>
                        <label for="end_time" class="label">{{ __('timer.end') }}</label>
                        <input id="end_time" name="end_time" type="time" required value="{{ old('end_time', $entry->localEnd()->format('H:i')) }}" class="input">
                    </div>
                </div>
                <x-field-error name="start_time" />
                <x-field-error name="end_time" />
                <button type="submit" class="btn-secondary">{{ __('timer.save_times') }}</button>
            </form>

            <form method="POST" action="{{ route('jobs.submit', $job) }}" class="mt-5 space-y-4" data-haptic>
                @csrf
                <div>
                    <label for="staff_notes" class="label">{{ __('timer.notes') }}</label>
                    <textarea id="staff_notes" name="staff_notes" rows="4" maxlength="2000" placeholder="{{ __('timer.notes_placeholder') }}"
                              class="input !min-h-28 py-3">{{ old('staff_notes', $entry->staff_notes) }}</textarea>
                    <x-field-error name="staff_notes" />
                </div>
                <button type="submit" class="btn-primary !min-h-16 text-xl">✓ {{ __('timer.submit_timesheet') }}</button>
            </form>
        </section>
    @else
        <section class="card mb-4 text-center">
            <span class="{{ $entry->status->badgeClass() }}">{{ $entry->status->label() }}</span>
            <p class="mt-3 font-display text-4xl font-semibold">{{ \App\Models\TimeEntry::formatMinutes($entry->duration_minutes) }}</p>
            <p class="mt-1 text-navy-500">{{ $entry->localStart()->format($fmt) }} – {{ $entry->localEnd()?->format($fmt) }}</p>
            @if ($entry->staff_notes)<p class="mt-3 whitespace-pre-line text-left text-[15px]">“{{ $entry->staff_notes }}”</p>@endif
            <p class="mt-3 text-sm text-navy-500">🔒 {{ __('timer.locked_note') }}</p>
        </section>
    @endif

    {{-- ===== Job details ===== --}}
    <div class="card">
        <p class="font-display text-[17px] font-semibold">
            {{ ucfirst($job->localStart()->translatedFormat(__('dates.day_short'))) }} · {{ $job->timeRange() }}
        </p>
        <h1 class="mt-1 text-[26px] leading-tight">{{ $client->display_name }}</h1>

        <dl class="mt-5 space-y-4 text-[16px]">
            <div>
                <dt class="label">{{ __('staff.job.address') }}</dt>
                <dd>{{ $client->fullAddress() }}</dd>
                <dd class="mt-2"><a href="{{ $client->mapUrl() }}" target="_blank" rel="noopener" class="btn-secondary">🗺 {{ __('staff.job.open_map') }}</a></dd>
            </div>
            @if ($client->access_notes)
                <div class="rounded-xl bg-sage-50 p-4">
                    <dt class="label !text-sage-700">🔑 {{ __('staff.job.access') }}</dt>
                    <dd class="whitespace-pre-line font-medium">{{ $client->access_notes }}</dd>
                </div>
            @endif
            @if ($client->default_job_notes)
                <div>
                    <dt class="label">✦ {{ __('staff.job.focus') }}</dt>
                    <dd class="whitespace-pre-line">{{ $client->default_job_notes }}</dd>
                </div>
            @endif
            @if ($job->job_notes && $job->job_notes !== $client->default_job_notes)
                <div>
                    <dt class="label">{{ __('staff.job.notes') }}</dt>
                    <dd class="whitespace-pre-line">{{ $job->job_notes }}</dd>
                </div>
            @endif
            @if ($job->serviceType)
                <div>
                    <dt class="label">{{ __('staff.job.service') }}</dt>
                    <dd>{{ $job->serviceType->name() }}</dd>
                </div>
            @endif
            <div>
                <dt class="label">{{ __('staff.job.team') }}</dt>
                <dd class="mt-1 flex flex-wrap gap-3">
                    @foreach ($job->staff as $person)
                        <span class="inline-flex items-center gap-2"><x-avatar :user="$person" size="sm" /> {{ $person->name }}</span>
                    @endforeach
                </dd>
            </div>
            @if ($client->show_phone_to_staff && $client->mobile)
                <div>
                    <a href="tel:{{ $client->mobile }}" class="btn-secondary">📞 {{ __('staff.job.call_client') }}</a>
                </div>
            @endif
        </dl>
    </div>
</x-layouts.staff>
