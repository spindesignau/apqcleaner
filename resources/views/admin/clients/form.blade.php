@php $editing = $client->exists; @endphp
<x-layouts.admin :title="$editing ? __('clients.edit').': '.$client->display_name : __('clients.new')">
    <a href="{{ $editing ? route('admin.clients.show', $client) : route('admin.clients.index') }}" class="text-sm font-semibold text-navy-500 hover:text-navy">← {{ $editing ? $client->display_name : __('clients.title') }}</a>

    <form method="POST" action="{{ $editing ? route('admin.clients.update', $client) : route('admin.clients.store') }}"
          class="mt-4 max-w-4xl space-y-6" x-data="{ pricing: @js(old('pricing_model', $client->pricing_model?->value ?? 'fixed')) }">
        @csrf
        @if ($editing) @method('PUT') @endif

        <section class="card space-y-5">
            <h2 class="text-lg">{{ __('clients.sections.contact') }}</h2>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="display_name" class="label">{{ __('clients.fields.display_name') }}</label>
                    <input id="display_name" name="display_name" required value="{{ old('display_name', $client->display_name) }}" class="input">
                    <p class="mt-1 text-sm text-navy-500">{{ __('clients.help.display_name') }}</p>
                    <x-field-error name="display_name" />
                </div>
                <div>
                    <label for="billing_name" class="label">{{ __('clients.fields.billing_name') }}</label>
                    <input id="billing_name" name="billing_name" value="{{ old('billing_name', $client->billing_name) }}" class="input">
                    <p class="mt-1 text-sm text-navy-500">{{ __('clients.help.billing_name') }}</p>
                    <x-field-error name="billing_name" />
                </div>
                <div>
                    <label for="mobile" class="label">{{ __('clients.fields.mobile') }}</label>
                    <input id="mobile" name="mobile" type="tel" inputmode="tel" value="{{ old('mobile', \App\Support\AuPhone::display($client->mobile)) }}" placeholder="0412 345 678" class="input">
                    <x-field-error name="mobile" />
                </div>
                <div>
                    <label for="email" class="label">{{ __('clients.fields.email') }}</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $client->email) }}" class="input">
                    <x-field-error name="email" />
                </div>
                <div>
                    <label for="phone_alt" class="label">{{ __('clients.fields.phone_alt') }}</label>
                    <input id="phone_alt" name="phone_alt" value="{{ old('phone_alt', $client->phone_alt) }}" class="input">
                </div>
                <div>
                    <label for="referral_source" class="label">{{ __('clients.fields.referral_source') }}</label>
                    <input id="referral_source" name="referral_source" value="{{ old('referral_source', $client->referral_source) }}" class="input">
                </div>
            </div>
        </section>

        <section class="card space-y-5">
            <h2 class="text-lg">{{ __('clients.sections.address') }}</h2>
            <div class="grid gap-5 sm:grid-cols-6">
                <div class="sm:col-span-4">
                    <label for="address_line1" class="label">{{ __('clients.fields.address_line1') }}</label>
                    <input id="address_line1" name="address_line1" required autocomplete="off" value="{{ old('address_line1', $client->address_line1) }}" class="input">
                    <x-field-error name="address_line1" />
                </div>
                <div class="sm:col-span-2">
                    <label for="address_line2" class="label">{{ __('clients.fields.address_line2') }}</label>
                    <input id="address_line2" name="address_line2" value="{{ old('address_line2', $client->address_line2) }}" class="input">
                </div>
                <div class="sm:col-span-3">
                    <label for="suburb" class="label">{{ __('clients.fields.suburb') }}</label>
                    <input id="suburb" name="suburb" value="{{ old('suburb', $client->suburb) }}" class="input">
                </div>
                <div class="sm:col-span-1">
                    <label for="state" class="label">{{ __('clients.fields.state') }}</label>
                    <select id="state" name="state" class="input">
                        @foreach (['NSW', 'ACT', 'VIC', 'QLD', 'SA', 'WA', 'TAS', 'NT'] as $st)
                            <option @selected(old('state', $client->state) === $st)>{{ $st }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label for="postcode" class="label">{{ __('clients.fields.postcode') }}</label>
                    <input id="postcode" name="postcode" inputmode="numeric" maxlength="4" value="{{ old('postcode', $client->postcode) }}" class="input">
                    <x-field-error name="postcode" />
                </div>
                <div class="sm:col-span-6">
                    <label for="billing_address" class="label">{{ __('clients.fields.billing_address') }}</label>
                    <input id="billing_address" name="billing_address" value="{{ old('billing_address', $client->billing_address) }}" class="input">
                </div>
            </div>
        </section>

        <section class="card space-y-5">
            <div>
                <h2 class="text-lg">{{ __('clients.sections.notes') }}</h2>
                <p class="text-sm text-navy-500">👁 {{ __('clients.help.staff_visible') }}</p>
            </div>
            <div>
                <label for="access_notes" class="label">{{ __('clients.fields.access_notes') }}</label>
                <textarea id="access_notes" name="access_notes" rows="2" class="input !min-h-20 py-3">{{ old('access_notes', $client->access_notes) }}</textarea>
            </div>
            <div>
                <label for="default_job_notes" class="label">{{ __('clients.fields.default_job_notes') }}</label>
                <textarea id="default_job_notes" name="default_job_notes" rows="3" class="input !min-h-24 py-3">{{ old('default_job_notes', $client->default_job_notes) }}</textarea>
            </div>
        </section>

        <section class="card space-y-5">
            <div>
                <h2 class="text-lg">{{ __('clients.sections.pricing') }}</h2>
                <p class="text-sm text-navy-500">{{ __('clients.help.invoiced') }}</p>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="service_type_id" class="label">{{ __('clients.fields.service_type_id') }}</label>
                    <select id="service_type_id" name="service_type_id" class="input">
                        <option value="">—</option>
                        @foreach ($serviceTypes as $type)
                            <option value="{{ $type->id }}" @selected((int) old('service_type_id', $client->service_type_id) === $type->id)>{{ $type->name() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="payment_method" class="label">{{ __('clients.fields.payment_method') }}</label>
                    <select id="payment_method" name="payment_method" class="input">
                        @foreach (\App\Enums\PaymentMethod::cases() as $pm)
                            <option value="{{ $pm->value }}" @selected(old('payment_method', $client->payment_method?->value ?? 'cash') === $pm->value)>{{ $pm->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label for="invoice_frequency" class="label">{{ __('clients.fields.invoice_frequency') }}</label>
                    <select id="invoice_frequency" name="invoice_frequency" class="input sm:max-w-xs">
                        @foreach (['per_visit', 'weekly', 'fortnightly', 'monthly'] as $f)
                            <option value="{{ $f }}" @selected(old('invoice_frequency', $client->invoice_frequency ?? 'monthly') === $f)>{{ __('invoices.frequencies.'.$f) }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-sm text-navy-500">{{ __('clients.help.invoice_frequency') }}</p>
                </div>
                <fieldset class="sm:col-span-2">
                    <legend class="label">{{ __('clients.fields.pricing_model') }}</legend>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (\App\Enums\PricingModel::cases() as $pm)
                            <label>
                                <input type="radio" name="pricing_model" value="{{ $pm->value }}" x-model="pricing" class="peer sr-only">
                                <span class="flex min-h-12 cursor-pointer items-center justify-center rounded-xl border-2 border-sand-600 px-3 text-center font-semibold peer-checked:border-navy peer-checked:bg-navy peer-checked:text-offwhite peer-focus-visible:ring-2 peer-focus-visible:ring-sage">{{ $pm->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <div x-show="pricing === 'fixed'">
                    <label for="fixed_price" class="label">{{ __('clients.fields.fixed_price') }}</label>
                    <div class="relative"><span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-navy-500">$</span>
                        <input id="fixed_price" name="fixed_price" type="number" step="0.01" min="0" inputmode="decimal" value="{{ old('fixed_price', $client->fixed_price) }}" class="input pl-8"></div>
                    <x-field-error name="fixed_price" />
                </div>
                <div x-show="pricing === 'hourly'" x-cloak>
                    <label for="hourly_rate" class="label">{{ __('clients.fields.hourly_rate') }}</label>
                    <div class="relative"><span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-navy-500">$</span>
                        <input id="hourly_rate" name="hourly_rate" type="number" step="0.01" min="0" inputmode="decimal" value="{{ old('hourly_rate', $client->hourly_rate) }}" class="input pl-8"></div>
                    <x-field-error name="hourly_rate" />
                </div>
            </div>
        </section>

        <section class="card space-y-5" x-data="{ feeType: @js(old('cancellation_fee_type', $client->cancellation_fee_type ?? 'percent_of_visit')) }">
            <div>
                <h2 class="text-lg">{{ __('cancellations.client_fee_settings') }}</h2>
                <p class="text-sm text-navy-500">{{ __('cancellations.client_fee_help') }}</p>
            </div>
            <div class="grid gap-5 sm:grid-cols-3">
                <div>
                    <label for="cancellation_fee_type" class="label">{{ __('cancellations.client_fee_settings') }}</label>
                    <select id="cancellation_fee_type" name="cancellation_fee_type" x-model="feeType" class="input">
                        @foreach (['percent_of_visit', 'fixed', 'none'] as $t)<option value="{{ $t }}">{{ __('cancellations.fee_types.'.$t) }}</option>@endforeach
                    </select>
                </div>
                <div x-show="feeType !== 'none'">
                    <label for="cancellation_fee_value" class="label">{{ __('cancellations.fee_value') }}</label>
                    <input id="cancellation_fee_value" name="cancellation_fee_value" type="number" step="0.01" min="0" value="{{ old('cancellation_fee_value', $client->cancellation_fee_value ?? 50) }}" class="input">
                    <x-field-error name="cancellation_fee_value" />
                </div>
                <div x-show="feeType !== 'none'">
                    <label for="cancellation_window_hours" class="label">{{ __('cancellations.window_hours') }}</label>
                    <input id="cancellation_window_hours" name="cancellation_window_hours" type="number" min="1" max="168" value="{{ old('cancellation_window_hours', $client->cancellation_window_hours ?? 24) }}" class="input">
                </div>
            </div>
        </section>

        <section class="card space-y-5">
            <h2 class="text-lg">{{ __('clients.sections.preferences') }}</h2>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="preferred_language" class="label">{{ __('clients.fields.preferred_language') }}</label>
                    <select id="preferred_language" name="preferred_language" class="input">
                        @foreach (config('apq.locales') as $code => $label)
                            <option value="{{ $code }}" @selected(old('preferred_language', $client->preferred_language) === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="space-y-3 pt-6">
                    @foreach (['sms_opt_in', 'show_phone_to_staff', 'is_active'] as $flag)
                        <label class="flex items-center gap-3">
                            <input type="hidden" name="{{ $flag }}" value="0">
                            <input type="checkbox" name="{{ $flag }}" value="1" @checked(old($flag, $client->{$flag})) class="size-6 rounded-md border-sand-600 text-navy focus:ring-sage">
                            {{ __('clients.fields.'.$flag) }}
                        </label>
                    @endforeach
                    <p class="text-sm text-navy-500">{{ __('clients.help.inactive') }}</p>
                </div>
                @if ($client->usual_frequency)
                    <p class="text-sm text-navy-500 sm:col-span-2">{{ __('clients.fields.usual_frequency') }}: <strong>{{ $client->usual_frequency }}</strong></p>
                @endif
            </div>
        </section>

        <section class="card space-y-3">
            <h2 class="text-lg">🔒 {{ __('clients.sections.admin') }}</h2>
            <label for="admin_notes" class="label">{{ __('clients.fields.admin_notes') }}</label>
            <textarea id="admin_notes" name="admin_notes" rows="3" class="input !min-h-24 py-3">{{ old('admin_notes', $client->admin_notes) }}</textarea>
        </section>

        <button type="submit" class="btn-primary sm:w-auto">{{ __('clients.save') }}</button>
    </form>
</x-layouts.admin>
