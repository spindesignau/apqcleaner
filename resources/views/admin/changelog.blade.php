<x-layouts.admin :title="__('admin.changelog.title')">
    <article class="card max-w-3xl
        [&_h1]:hidden [&_p]:mt-2 [&_p]:text-navy-500
        [&_h2]:mt-8 [&_h2]:border-t [&_h2]:border-sand [&_h2]:pt-6 [&_h2]:text-xl [&_h2:first-of-type]:mt-2 [&_h2:first-of-type]:border-0 [&_h2:first-of-type]:pt-0
        [&_h3]:mt-4 [&_h3]:text-xs [&_h3]:font-bold [&_h3]:tracking-wider [&_h3]:text-sage-700 [&_h3]:uppercase
        [&_ul]:mt-2 [&_ul]:list-disc [&_ul]:space-y-1.5 [&_ul]:pl-5 [&_li]:text-[15px] [&_strong]:text-navy">
        {!! $html !!}
    </article>
</x-layouts.admin>
