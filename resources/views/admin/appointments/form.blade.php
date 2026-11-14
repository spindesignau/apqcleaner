@php $editing = $appointment->exists; @endphp
<x-layouts.admin :title="$editing ? __('schedule.edit_visit') : __('schedule.new_visit')">
    <a href="{{ route('admin.schedule', ['week' => \Illuminate\Support\Carbon::parse($slot['date'])->format('o-\WW')]) }}" class="text-sm font-semibold text-navy-500 hover:text-navy">← {{ __('schedule.board_title') }}</a>

    <form method="POST" action="{{ $editing ? route('admin.appointments.update', $appointment) : route('admin.appointments.store') }}" class="card mt-4 max-w-3xl space-y-5">
        @csrf
        @if ($editing) @method('PUT') @endif

        @if ($editing)
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-xl"><a href="{{ route('admin.clients.show', $appointment->client) }}" class="hover:underline">{{ $appointment->client->display_name }}</a></h2>
                    <p class="text-navy-500">{{ $appointment->client->fullAddress() }}</p>
                </div>
                <div class="flex flex-col items-end gap-1">
                    <span class="{{ $appointment->status->badgeClass() }}">{{ $appointment->status->label() }}</span>
                    @if ($appointment->status === \App\Enums\AppointmentStatus::Scheduled)<x-countdown-chip :appointment="$appointment" />@endif
                </div>
            </div>
            @if ($appointment->recurring_schedule_id)
                <p class="rounded-xl bg-sage-50 px-4 py-3 text-sm text-sage-700">{{ __('schedule.recurring_note') }}</p>
            @endif
        @else
            <div>
                <label for="client_id" class="label">{{ __('schedule.fields.client_id') }}</label>
                <select id="client_id" name="client_id" required class="input">
                    <option value="">{{ __('schedule.select_client') }}</option>
                    @foreach ($clients as $c)
                        <option value="{{ $c->id }}" @selected((int) old('client_id', $appointment->client_id) === $c->id)>{{ $c->display_name }}{{ $c->suburb ? ' — '.$c->suburb : '' }}</option>
                    @endforeach
                </select>
                <x-field-error name="client_id" />
            </div>
        @endif

        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <label for="date" class="label">{{ __('schedule.fields.date') }}</label>
                <input id="date" name="date" type="date" required value="{{ old('date', $slot['date']) }}" class="input">
                <x-field-error name="date" />
            </div>
            <div>
                <label for="start_time" class="label">{{ __('schedule.fields.start_time') }}</label>
                <input id="start_time" name="start_time" type="time" step="900" required value="{{ old('start_time', $slot['start_time']) }}" class="input">
                <x-field-error name="start_time" />
            </div>
            <div>
                <label for="duration_minutes" class="label">{{ __('schedule.fields.duration_minutes') }}</label>
                <x-duration-select :value="old('duration_minutes', $slot['duration_minutes'])" />
            </div>
            <div>
                <label for="service_type_id" class="label">{{ __('schedule.fields.service_type_id') }}</label>
                <select id="service_type_id" name="service_type_id" class="input">
                    <option value="">—</option>
                    @foreach ($serviceTypes as $type)
                        <option value="{{ $type->id }}" @selected((int) old('service_type_id', $appointment->service_type_id) === $type->id)>{{ $type->name() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-2">
                <label for="price" class="label">{{ __('schedule.fields.price') }}</label>
                <div class="relative"><span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-navy-500">$</span>
                    <input id="price" name="price" type="number" step="0.01" min="0" inputmode="decimal" value="{{ old('price', $appointment->price) }}" class="input pl-8"></div>
                <p class="mt-1 text-sm text-navy-500">{{ __('schedule.help.price') }}</p>
            </div>
        </div>

        <x-staff-picker :options="$staffOptions" :selected="$selectedStaff" />

        <div>
            <label for="job_notes" class="label">{{ __('schedule.fields.job_notes') }}</label>
            <textarea id="job_notes" name="job_notes" rows="3" class="input !min-h-24 py-3">{{ old('job_notes', $appointment->job_notes) }}</textarea>
        </div>

        @if (! $editing || $appointment->status === \App\Enums\AppointmentStatus::Scheduled)
            <button type="submit" class="btn-primary sm:w-auto">{{ __('schedule.save_visit') }}</button>
        @else
            <p class="text-navy-500">{{ __('schedule.not_editable') }}</p>
        @endif
    </form>

    @if ($editing && $appointment->status === \App\Enums\AppointmentStatus::Scheduled)
        <div class="mt-4 flex max-w-3xl flex-wrap gap-3">
            <a href="{{ route('admin.cancellations.create', $appointment) }}" class="btn-secondary btn-sm !border-alert !text-alert">{{ __('cancellations.cancel') }}</a>
            <form method="POST" action="{{ route('admin.appointments.remind', $appointment) }}">@csrf<button class="btn-secondary btn-sm">📱 {{ __('cancellations.remind') }}</button></form>
        </div>
        <form method="POST" action="{{ route('admin.appointments.destroy', $appointment) }}" class="mt-4 max-w-3xl"
              x-data @submit="if (! confirm(@js(__('schedule.delete_confirm')))) $event.preventDefault()">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-secondary btn-sm !border-alert !text-alert">{{ __('schedule.delete_visit') }}</button>
        </form>
    @endif
</x-layouts.admin>
