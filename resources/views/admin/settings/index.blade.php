@php $tz = config('apq.display_timezone'); @endphp
<x-layouts.admin :title="__('admin.nav.settings')">
    <div class="grid max-w-5xl gap-4 sm:grid-cols-2">
        <a href="{{ route('admin.xero.show') }}" class="card block hover:border-sage">
            <h2 class="text-lg">{{ __('xero.title') }}</h2>
            <p class="mt-1 text-[15px] text-navy-500">{{ __('system.xero_card') }}</p>
        </a>
        <a href="{{ route('admin.sms.index') }}" class="card block hover:border-sage">
            <h2 class="text-lg">{{ __('sms.title') }}</h2>
            <p class="mt-1 text-[15px] text-navy-500">{{ __('system.sms_card') }}</p>
        </a>
    </div>

    <section class="card mt-6 max-w-5xl">
        <h2 class="text-lg">{{ __('system.title') }}</h2>
        <ul class="mt-3 divide-y divide-sand">
            @foreach ($checks as $check)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-[15px]">
                    <span class="flex items-center gap-2">
                        <span @class(['size-2.5 rounded-full', 'bg-success' => $check['ok'] === true, 'bg-alert' => $check['ok'] === false, 'bg-sand-600' => $check['ok'] === null])></span>
                        <strong>{{ __('system.checks.'.$check['key']) }}</strong>
                    </span>
                    <span @class(['text-navy-500', 'text-alert font-semibold' => $check['ok'] === false])>{{ $check['detail'] }}</span>
                </li>
            @endforeach
        </ul>
        <p class="mt-3 text-sm text-navy-500">{{ __('system.cron_help') }}</p>
    </section>

    <section class="card mt-6 max-w-5xl">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg">{{ __('system.backups') }}</h2>
                <p class="text-sm text-navy-500">{{ __('system.backups_help', ['days' => config('apq.backups.keep_days')]) }}</p>
            </div>
            <form method="POST" action="{{ route('admin.settings.backup') }}">@csrf<button class="btn-primary btn-sm">{{ __('system.backup_now') }}</button></form>
        </div>
        <ul class="mt-3 divide-y divide-sand text-[15px]">
            @forelse ($backups as $b)
                <li class="flex items-center justify-between gap-2 py-2">
                    <span>{{ $b['at']->tz($tz)->translatedFormat(__('dates.datetime')) }} <span class="text-navy-500">· {{ number_format($b['bytes'] / 1024, 1) }} KB</span></span>
                    <a href="{{ route('admin.settings.backup.download', $b['name']) }}" data-no-progress class="font-semibold underline">⇩ {{ __('system.download') }}</a>
                </li>
            @empty
                <li class="py-3 text-navy-500">{{ __('system.no_backup') }}</li>
            @endforelse
        </ul>
        <p class="mt-3 text-sm text-amber">🔒 {{ __('system.backup_privacy') }}</p>
    </section>
</x-layouts.admin>
