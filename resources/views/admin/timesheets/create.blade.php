<x-layouts.admin :title="__('timesheets.add_title', ['name' => $user->name, 'client' => $appointment->client->display_name])">
    <a href="{{ route('admin.timesheets.index') }}" class="text-sm font-semibold text-navy-500 hover:text-navy">← {{ __('timesheets.title') }}</a>
    <form method="POST" action="{{ route('admin.timesheets.store') }}" class="card mt-4 max-w-3xl space-y-5">
        @csrf
        <input type="hidden" name="appointment_id" value="{{ $appointment->id }}">
        <input type="hidden" name="user_id" value="{{ $user->id }}">
        <p class="text-navy-500">{{ __('timesheets.scheduled_for', ['date' => ucfirst($appointment->localStart()->translatedFormat(__('dates.day_short'))), 'time' => $appointment->timeRange()]) }}</p>
        <div class="grid gap-4 sm:grid-cols-4">
            <div>
                <label for="date" class="label">{{ __('timesheets.fields.date') }}</label>
                <input id="date" name="date" type="date" required value="{{ old('date', $appointment->localStart()->toDateString()) }}" class="input">
            </div>
            <div>
                <label for="start_time" class="label">{{ __('timesheets.fields.start_time') }}</label>
                <input id="start_time" name="start_time" type="time" required value="{{ old('start_time', $appointment->localStart()->format('H:i')) }}" class="input">
            </div>
            <div>
                <label for="end_time" class="label">{{ __('timesheets.fields.end_time') }}</label>
                <input id="end_time" name="end_time" type="time" required value="{{ old('end_time', $appointment->localEnd()->format('H:i')) }}" class="input">
            </div>
            <div>
                <label for="billable_minutes" class="label">{{ __('timesheets.fields.billable_minutes') }}</label>
                <input id="billable_minutes" name="billable_minutes" type="number" min="0" max="1440" value="{{ old('billable_minutes') }}" class="input">
            </div>
        </div>
        <div>
            <label for="reason" class="label">{{ __('timesheets.fields.reason') }}</label>
            <input id="reason" name="reason" maxlength="255" value="{{ old('reason') }}" class="input">
        </div>
        <div>
            <label for="admin_notes" class="label">{{ __('timesheets.fields.admin_notes') }}</label>
            <textarea id="admin_notes" name="admin_notes" rows="2" class="input !min-h-20 py-3">{{ old('admin_notes') }}</textarea>
        </div>
        <div class="flex flex-wrap gap-3">
            <button type="submit" class="btn-secondary sm:w-auto">{{ __('timesheets.save') }}</button>
            <button type="submit" name="approve" value="1" class="btn-primary sm:w-auto">✓ {{ __('timesheets.save_and_approve') }}</button>
        </div>
    </form>
</x-layouts.admin>
