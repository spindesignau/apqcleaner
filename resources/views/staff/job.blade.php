@php $client = $job->client; @endphp
<x-layouts.staff :title="$client->display_name">
    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('jobs.today') }}" class="inline-flex min-h-11 items-center font-semibold text-navy-500">← {{ __('staff.job.back') }}</a>

    <div class="card mt-1">
        <p class="font-display text-[17px] font-semibold">
            {{ ucfirst($job->localStart()->translatedFormat(__('dates.day_short'))) }} · {{ $job->timeRange() }}
        </p>
        <h1 class="mt-1 text-[26px] leading-tight">{{ $client->display_name }}</h1>
        @if ($job->status !== \App\Enums\AppointmentStatus::Scheduled)
            <span class="{{ $job->status->badgeClass() }} mt-2">{{ $job->status->label() }}</span>
        @endif

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

    <p class="mt-4 rounded-xl border border-dashed border-sand-600 px-4 py-3 text-center text-[15px] text-navy-500">⏱ {{ __('staff.job.timer_soon') }}</p>
</x-layouts.staff>
