@php $tz = config('apq.display_timezone'); @endphp
<x-layouts.admin :title="__('sms.title')">
    @unless ($enabled)
        <p class="mb-4 max-w-6xl rounded-xl bg-amber-50 px-4 py-3 font-medium text-amber">⚠ {{ __('sms.disabled_banner') }}</p>
    @endunless

    <div class="grid max-w-6xl grid-cols-3 gap-4">
        <div class="card"><p class="label !mb-0">{{ __('sms.this_month') }}</p><p class="mt-1 font-display text-3xl font-semibold">{{ $monthCount }}</p></div>
        <a href="{{ route('admin.sms.index', ['status' => 'failed']) }}" class="card block hover:border-sage"><p class="label !mb-0">{{ __('sms.failed_week') }}</p><p @class(['mt-1 font-display text-3xl font-semibold', 'text-alert' => $failedCount])>{{ $failedCount }}</p></a>
        <div class="card"><p class="label !mb-0">{{ __('sms.opted_out') }}</p><p class="mt-1 font-display text-3xl font-semibold">{{ $optedOut }}</p></div>
    </div>

    <div class="mt-6 grid max-w-6xl gap-6 xl:grid-cols-[1fr_24rem]">
        <section>
            <div class="flex flex-wrap gap-2">
                @foreach (['all', 'sent', 'delivered', 'failed', 'skipped', 'inbound'] as $s)
                    <a href="{{ route('admin.sms.index', $s === 'all' ? [] : ['status' => $s]) }}" @class(['badge min-h-9 !px-4', 'bg-navy text-offwhite' => ($status ?? 'all') === $s, 'badge-sand' => ($status ?? 'all') !== $s])>{{ __('sms.filter.'.$s) }}</a>
                @endforeach
            </div>
            <div class="mt-4 overflow-x-auto rounded-[var(--radius-card)] border border-sand bg-surface shadow-[var(--shadow-card)]">
                <table class="w-full min-w-[44rem] text-left text-[14px]">
                    <thead class="bg-canvas-50 text-xs font-bold tracking-wider text-navy-500 uppercase">
                        <tr>@foreach (['when', 'to', 'message', 'status'] as $c)<th class="px-3 py-2.5">{{ __('sms.cols.'.$c) }}</th>@endforeach</tr>
                    </thead>
                    <tbody class="divide-y divide-sand">
                        @forelse ($messages as $m)
                            <tr class="align-top">
                                <td class="px-3 py-2.5 whitespace-nowrap">{{ $m->created_at->tz($tz)->translatedFormat(__('dates.datetime')) }}<p class="text-xs text-navy-500">{{ __('sms.triggers.'.$m->trigger) }}</p></td>
                                <td class="px-3 py-2.5">
                                    <span class="font-semibold">{{ $m->client?->display_name ?? $m->user?->name ?? '—' }}</span>
                                    <p class="text-xs text-navy-500">{{ \App\Support\AuPhone::display($m->direction === 'inbound' ? $m->from_number : $m->to_number) }}</p>
                                </td>
                                <td class="max-w-md px-3 py-2.5">{{ $m->body }}</td>
                                <td class="px-3 py-2.5">
                                    <span class="{{ $m->badgeClass() }}">{{ __('sms.statuses.'.$m->status) }}</span>
                                    @if ($m->status_detail)<p class="mt-1 max-w-48 text-xs text-navy-500">{{ $m->status_detail }}</p>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-10 text-center text-navy-500">{{ __('sms.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $messages->links() }}</div>
        </section>

        <aside class="space-y-6">
            <form method="POST" action="{{ route('admin.sms.settings') }}" class="card space-y-4">
                @csrf
                @method('PUT')
                <h2 class="text-lg">{{ __('sms.settings') }}</h2>
                <label class="flex items-start gap-3 text-[15px]"><input type="checkbox" name="reminders_enabled" value="1" @checked($settings['reminders_enabled']) class="mt-1 size-5 rounded border-sand-600 text-navy focus:ring-sage"> {{ __('sms.fields.reminders_enabled') }}</label>
                <div>
                    <label for="reminder_time" class="label">{{ __('sms.fields.reminder_time') }}</label>
                    <input id="reminder_time" name="reminder_time" type="time" required value="{{ old('reminder_time', $settings['reminder_time']) }}" class="input">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label for="quiet_end" class="label">{{ __('sms.fields.quiet_end') }}</label><input id="quiet_end" name="quiet_end" type="time" required value="{{ old('quiet_end', $settings['quiet_end']) }}" class="input"></div>
                    <div><label for="quiet_start" class="label">{{ __('sms.fields.quiet_start') }}</label><input id="quiet_start" name="quiet_start" type="time" required value="{{ old('quiet_start', $settings['quiet_start']) }}" class="input"></div>
                </div>
                <x-field-error name="quiet_start" />
                <div>
                    <label for="business_phone" class="label">{{ __('sms.fields.business_phone') }}</label>
                    <input id="business_phone" name="business_phone" value="{{ old('business_phone', $settings['business_phone']) }}" class="input">
                </div>
                <label class="flex items-start gap-3 text-[15px]"><input type="checkbox" name="notify_client_on_cancel" value="1" @checked($settings['notify_client_on_cancel']) class="mt-1 size-5 rounded border-sand-600 text-navy focus:ring-sage"> {{ __('sms.fields.notify_client_on_cancel') }}</label>
                <label class="flex items-start gap-3 text-[15px]"><input type="checkbox" name="notify_staff_on_cancel" value="1" @checked($settings['notify_staff_on_cancel']) class="mt-1 size-5 rounded border-sand-600 text-navy focus:ring-sage"> {{ __('sms.fields.notify_staff_on_cancel') }}</label>
                <button class="btn-primary btn-sm">{{ __('common.save') }}</button>
            </form>

            <section class="card space-y-4">
                <h2 class="text-lg">{{ __('sms.templates') }}</h2>
                <p class="text-xs text-navy-500">{{ __('sms.placeholders', ['list' => implode(' ', \App\Models\SmsTemplate::PLACEHOLDERS)]) }}</p>
                @foreach ($templates as $t)
                    <form method="POST" action="{{ route('admin.sms.templates.update', $t) }}" class="space-y-2 border-t border-sand pt-4" x-data="{ en: @js($t->body_en), es: @js($t->body_es) }">
                        @csrf
                        @method('PUT')
                        <p class="font-semibold">{{ __('sms.template_names.'.$t->key) }}</p>
                        @foreach (['en' => 'english', 'es' => 'spanish'] as $lang => $label)
                            <label class="label !mb-0" for="t{{ $t->id }}{{ $lang }}">{{ __('sms.'.$label) }}</label>
                            <textarea id="t{{ $t->id }}{{ $lang }}" name="body_{{ $lang }}" rows="3" x-model="{{ $lang }}" class="input !min-h-20 py-2 text-[14px]"></textarea>
                            <p class="text-xs text-navy-500" x-text="@js(__('sms.chars', ['n' => '__N__', 's' => '__S__'])).replace('__N__', {{ $lang }}.length).replace('__S__', {{ $lang }}.length <= 160 ? 1 : Math.ceil({{ $lang }}.length / 153))"></p>
                            <x-field-error :name="'body_'.$lang.'_'.$t->id" />
                        @endforeach
                        <button class="btn-secondary btn-sm">{{ __('common.save') }}</button>
                    </form>
                @endforeach
            </section>

            <section class="card space-y-2 text-sm">
                <h2 class="text-lg">{{ __('sms.webhooks') }}</h2>
                @if ($webhookBase)
                    <p class="label !mb-0">{{ __('sms.webhook_status') }}</p><code class="block rounded-lg bg-canvas-50 px-3 py-2 break-all select-all">{{ $webhookBase }}/status</code>
                    <p class="label !mb-0">{{ __('sms.webhook_inbound') }}</p><code class="block rounded-lg bg-canvas-50 px-3 py-2 break-all select-all">{{ $webhookBase }}/inbound</code>
                @else
                    <p class="text-navy-500">{{ __('sms.webhook_missing') }}</p>
                @endif
            </section>
        </aside>
    </div>
</x-layouts.admin>
