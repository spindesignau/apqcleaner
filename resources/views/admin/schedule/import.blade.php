<x-layouts.admin :title="__('schedule_import.title')">
    <a href="{{ route('admin.schedule') }}" class="text-sm font-semibold text-navy-500 hover:text-navy">← {{ __('schedule.board_title') }}</a>

    <div class="mt-4 grid max-w-5xl gap-6 lg:grid-cols-[1fr_22rem]">
        <form method="POST" action="{{ route('admin.schedule.import.upload') }}" enctype="multipart/form-data" class="card space-y-5">
            @csrf
            <p class="text-navy-500">{{ __('schedule_import.intro') }}</p>
            <div>
                <label for="file" class="label">{{ __('schedule_import.file') }}</label>
                <input id="file" name="file" type="file" required accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                       class="block w-full rounded-xl border border-sand-600 bg-canvas-50 p-3 file:mr-4 file:rounded-lg file:border-0 file:bg-navy file:px-4 file:py-2 file:font-semibold file:text-offwhite">
                <x-field-error name="file" />
            </div>
            <div>
                <label for="cleaner" class="label">{{ __('schedule_import.cleaner') }}</label>
                <select id="cleaner" name="cleaner" class="input">
                    <option value="">{{ __('schedule_import.no_default') }}</option>
                    @foreach ($staffOptions as $person)
                        <option value="{{ $person->id }}" @selected((int) old('cleaner', $selectedCleaner) === $person->id)>{{ $person->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn-primary sm:w-auto">{{ __('schedule_import.preview') }} ›</button>
        </form>

        <aside class="card h-fit space-y-4">
            <a href="{{ route('admin.schedule.template') }}" class="btn-secondary btn-sm !w-full">⇩ {{ __('schedule_import.download') }}</a>
            <p class="text-sm text-navy-500">{{ __('schedule_import.download_help') }}</p>
            <dl class="space-y-3 text-[14px]">
                @foreach (__('schedule_import.columns') as $col => $help)
                    <div>
                        <dt class="font-mono text-[13px] font-semibold">{{ $col }}</dt>
                        <dd class="text-navy-500">{{ $help }}</dd>
                    </div>
                @endforeach
            </dl>
        </aside>
    </div>
</x-layouts.admin>
