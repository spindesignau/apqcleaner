<x-layouts.admin :title="$client->display_name">
    <x-slot:actions>
        <a href="{{ route('admin.clients.edit', $client) }}" class="btn-secondary btn-sm">{{ __('clients.edit') }}</a>
    </x-slot:actions>
    <a href="{{ route('admin.clients.index') }}" class="text-sm font-semibold text-navy-500 hover:text-navy">← {{ __('clients.title') }}</a>

    <div class="mt-4 grid max-w-6xl gap-6 lg:grid-cols-[1fr_22rem]">
        <div class="space-y-6">
            <section class="card">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-xl">{{ $client->display_name }}</h2>
                        @if ($client->billing_name !== $client->display_name)<p class="text-navy-500">{{ $client->billing_name }}</p>@endif
                    </div>
                    <div class="flex gap-2">
                        @unless ($client->is_active)<span class="badge-sand">{{ __('clients.inactive') }}</span>@endunless
                        @unless ($client->sms_opt_in)<span class="badge-amber">{{ __('sms.opted_out_badge') }}</span>@endunless
                    </div>
                </div>
                <dl class="mt-4 grid gap-4 text-[15px] sm:grid-cols-2">
                    <div>
                        <dt class="label">{{ __('clients.sections.address') }}</dt>
                        <dd>{{ $client->fullAddress() }} · <a href="{{ $client->mapUrl() }}" target="_blank" rel="noopener" class="font-semibold underline">{{ __('clients.map') }}</a></dd>
                    </div>
                    <div>
                        <dt class="label">{{ __('clients.sections.contact') }}</dt>
                        <dd>
                            @if ($client->mobile)<a href="tel:{{ $client->mobile }}" class="font-semibold underline">{{ \App\Support\AuPhone::display($client->mobile) }}</a><br>@endif
                            {{ $client->email ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="label">{{ __('clients.sections.pricing') }}</dt>
                        <dd>
                            @if ($price = $client->currentPrice())
                                <strong>${{ number_format($price, 2) }}</strong> {{ $client->pricing_model->value === 'hourly' ? __('clients.per_hour') : __('clients.per_visit') }}
                            @else
                                <span class="text-amber">{{ __('clients.no_price') }}</span>
                            @endif
                            · {{ $client->payment_method->label() }}
                            @if ($client->serviceType)<br>{{ $client->serviceType->name() }}@endif
                        </dd>
                    </div>
                    @if ($client->referral_source)
                        <div><dt class="label">{{ __('clients.fields.referral_source') }}</dt><dd>{{ $client->referral_source }}</dd></div>
                    @endif
                    @if ($client->access_notes)
                        <div class="sm:col-span-2"><dt class="label">🔑 {{ __('clients.fields.access_notes') }}</dt><dd class="whitespace-pre-line">{{ $client->access_notes }}</dd></div>
                    @endif
                    @if ($client->default_job_notes)
                        <div class="sm:col-span-2"><dt class="label">✦ {{ __('clients.fields.default_job_notes') }}</dt><dd class="whitespace-pre-line">{{ $client->default_job_notes }}</dd></div>
                    @endif
                    @if ($client->admin_notes)
                        <div class="sm:col-span-2"><dt class="label">🔒 {{ __('clients.fields.admin_notes') }}</dt><dd class="whitespace-pre-line">{{ $client->admin_notes }}</dd></div>
                    @endif
                </dl>
            </section>

            <section class="card">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg">{{ __('schedule.regular') }}</h2>
                    @if ($client->is_active)
                        <a href="{{ route('admin.schedules.create', $client) }}" class="btn-primary btn-sm">+ {{ __('schedule.add_regular') }}</a>
                    @endif
                </div>
                <ul class="mt-3 divide-y divide-sand">
                    @forelse ($client->schedules as $schedule)
                        <li class="flex items-center justify-between gap-3 py-3">
                            <div>
                                <p class="font-semibold">{{ $schedule->summary() }}</p>
                                <p class="text-sm text-navy-500">
                                    {{ intdiv($schedule->duration_minutes, 60) }} h{{ $schedule->duration_minutes % 60 ? ' '.($schedule->duration_minutes % 60).' m' : '' }}
                                    · <x-staff-names :staff="$schedule->staff" />
                                </p>
                            </div>
                            <a href="{{ route('admin.schedules.edit', $schedule) }}" class="btn-secondary btn-sm">{{ __('common.edit') }}</a>
                        </li>
                    @empty
                        <li class="py-3 text-navy-500">{{ __('clients.no_schedule') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>

        <div class="space-y-6">
        <aside class="card h-fit">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-lg">{{ __('clients.upcoming') }}</h2>
                @if ($client->is_active)
                    <a href="{{ route('admin.appointments.create', ['client' => $client->id]) }}" class="text-sm font-semibold underline">+ {{ __('schedule.add_visit') }}</a>
                @endif
            </div>
            <ul class="mt-3 space-y-2">
                @forelse ($upcoming as $visit)
                    <li>
                        <a href="{{ route('admin.appointments.edit', $visit) }}" class="block rounded-xl border border-sand px-3 py-2 hover:border-sage">
                            <p class="flex items-center justify-between gap-2 text-[15px] font-semibold">
                                {{ ucfirst($visit->localStart()->translatedFormat(__('dates.day_short'))) }}
                                <span class="{{ $visit->status->badgeClass() }} !py-0 !text-[11px]">{{ $visit->status->label() }}</span>
                            </p>
                            <p class="text-sm text-navy-500">{{ $visit->timeRange() }} · <x-staff-names :staff="$visit->staff" /></p>
                        </a>
                    </li>
                @empty
                    <li class="text-navy-500">{{ __('clients.no_upcoming') }}</li>
                @endforelse
            </ul>
        </aside>
        @if ($client->mobile && $client->sms_opt_in)
            <form method="POST" action="{{ route('admin.clients.sms', $client) }}" class="card space-y-3" x-data="{ body: '' }">
                @csrf
                <h2 class="text-lg">{{ __('sms.send_title') }}</h2>
                <textarea name="body" rows="3" maxlength="612" required x-model="body" class="input !min-h-20 py-2 text-[15px]" aria-label="{{ __('sms.message') }}"></textarea>
                <p class="text-xs text-navy-500" x-text="@js(__('sms.chars', ['n' => '__N__', 's' => '__S__'])).replace('__N__', body.length).replace('__S__', body.length <= 160 ? 1 : Math.ceil(body.length / 153))"></p>
                <x-field-error name="body" />
                <button class="btn-secondary btn-sm">📱 {{ __('sms.send') }}</button>
            </form>
        @endif
        </div>
    </div>
</x-layouts.admin>
