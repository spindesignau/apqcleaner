<x-layouts.admin :title="__('xero.title')">
    @error('xero')<p class="mb-4 max-w-3xl rounded-xl bg-alert-50 px-4 py-3 font-medium text-alert" role="alert">{{ $message }}</p>@enderror

    <div class="grid max-w-5xl gap-6 lg:grid-cols-2">
        <section class="card space-y-4">
            <h2 class="text-lg">{{ __('xero.connection') }}</h2>
            @if ($connection)
                <p class="flex items-center gap-2 font-semibold"><span class="size-2.5 rounded-full bg-success"></span> {{ __('xero.connected_to', ['name' => $connection->tenant_name]) }}</p>
                <p class="text-sm text-navy-500">
                    {{ __('xero.connected_by', ['name' => $connection->connectedBy?->name ?? '—', 'date' => $connection->created_at->tz(config('apq.display_timezone'))->translatedFormat(__('dates.datetime'))]) }}
                    @if ($connection->last_used_at)<br>{{ __('xero.token_expires', ['when' => $connection->last_used_at->diffForHumans()]) }}@endif
                </p>
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.xero.test') }}">@csrf<button class="btn-primary btn-sm">{{ __('xero.test') }}</button></form>
                    <form method="POST" action="{{ route('admin.xero.connect') }}">@csrf<button class="btn-secondary btn-sm">{{ __('xero.reconnect') }}</button></form>
                    <form method="POST" action="{{ route('admin.xero.disconnect') }}" data-confirm="{{ __('xero.disconnect_confirm') }}" data-confirm-danger data-confirm-ok="{{ __('xero.disconnect') }}">
                        @csrf<button class="btn-secondary btn-sm !border-alert !text-alert">{{ __('xero.disconnect') }}</button>
                    </form>
                </div>
            @else
                <p class="flex items-center gap-2 font-semibold text-amber"><span class="size-2.5 rounded-full bg-amber"></span> {{ __('xero.not_connected') }}</p>
                @if ($configured)
                    <form method="POST" action="{{ route('admin.xero.connect') }}">@csrf<button class="btn-primary sm:w-auto">{{ __('xero.connect') }}</button></form>
                @else
                    <p class="rounded-xl bg-amber-50 px-4 py-3 text-[15px] text-amber">{{ __('xero.not_configured') }}</p>
                @endif
            @endif

            <div class="border-t border-sand pt-4 text-sm">
                <p class="text-navy-500">{{ __('xero.setup_steps') }}</p>
                <p class="label mt-3">{{ __('xero.redirect_uri') }}</p>
                <code class="block rounded-lg bg-canvas-50 px-3 py-2 break-all select-all">{{ $redirectUri }}</code>
                <p class="label mt-3">{{ __('xero.webhook_url') }}</p>
                <code class="block rounded-lg bg-canvas-50 px-3 py-2 break-all select-all">{{ $webhookUrl }}</code>
            </div>
            @if ($failed)
                <p class="rounded-xl bg-alert-50 px-4 py-3 text-[15px] text-alert">
                    {{ trans_choice('xero.failed_banner', $failed, ['count' => $failed]) }}
                    <a class="font-semibold underline" href="{{ route('admin.invoices.index', ['state' => 'failed']) }}">{{ __('invoices.title') }} ›</a>
                </p>
            @endif
        </section>

        <form method="POST" action="{{ route('admin.xero.settings') }}" class="card space-y-4">
            @csrf
            @method('PUT')
            <h2 class="text-lg">{{ __('xero.settings') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach (['default_account_code', 'cancellation_account_code'] as $field)
                    <div>
                        <label for="{{ $field }}" class="label">{{ __('xero.fields.'.$field) }}</label>
                        <input id="{{ $field }}" name="{{ $field }}" required maxlength="10" value="{{ old($field, $settings[$field]) }}" class="input">
                        <x-field-error :name="$field" />
                    </div>
                @endforeach
                <div>
                    <label for="payment_terms_days" class="label">{{ __('xero.fields.payment_terms_days') }}</label>
                    <input id="payment_terms_days" name="payment_terms_days" type="number" min="0" max="90" required value="{{ old('payment_terms_days', $settings['payment_terms_days']) }}" class="input">
                </div>
                <div>
                    <label for="default_mode" class="label">{{ __('xero.fields.default_mode') }}</label>
                    <select id="default_mode" name="default_mode" class="input">
                        @foreach (['draft', 'authorised'] as $m)<option value="{{ $m }}" @selected(old('default_mode', $settings['default_mode']) === $m)>{{ __('xero.modes.'.$m) }}</option>@endforeach
                    </select>
                </div>
            </div>
            <label class="flex items-center gap-3">
                <input type="checkbox" name="email_by_default" value="1" @checked(old('email_by_default', $settings['email_by_default'])) class="size-6 rounded-md border-sand-600 text-navy focus:ring-sage">
                {{ __('xero.fields.email_by_default') }}
            </label>
            <button type="submit" class="btn-primary sm:w-auto">{{ __('common.save') }}</button>
        </form>
    </div>
</x-layouts.admin>
