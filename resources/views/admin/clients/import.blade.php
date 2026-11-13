<x-layouts.admin :title="__('import.title')">
    <a href="{{ route('admin.clients.index') }}" class="text-sm font-semibold text-navy-500 hover:text-navy">← {{ __('clients.title') }}</a>

    <form method="POST" action="{{ route('admin.clients.import.upload') }}" enctype="multipart/form-data" class="card mt-4 max-w-2xl space-y-5">
        @csrf
        <p class="text-navy-500">{{ __('import.intro') }}</p>
        <div>
            <label for="file" class="label">{{ __('import.file') }}</label>
            <input id="file" name="file" type="file" accept=".csv,text/csv" required
                   class="block w-full rounded-xl border border-sand-600 bg-canvas-50 p-3 file:mr-4 file:rounded-lg file:border-0 file:bg-navy file:px-4 file:py-2 file:font-semibold file:text-offwhite">
            <x-field-error name="file" />
        </div>
        <fieldset>
            <legend class="label">{{ __('import.cancelled_label') }}</legend>
            <div class="space-y-2">
                @foreach ($modes as $mode)
                    <label class="flex min-h-11 cursor-pointer items-center gap-3">
                        <input type="radio" name="cancelled" value="{{ $mode }}" @checked(old('cancelled', 'active') === $mode) class="size-5 border-sand-600 text-navy focus:ring-sage">
                        {{ __('import.modes.'.$mode) }}
                    </label>
                @endforeach
            </div>
        </fieldset>
        <button type="submit" class="btn-primary sm:w-auto">{{ __('import.preview') }} ›</button>
    </form>
</x-layouts.admin>
