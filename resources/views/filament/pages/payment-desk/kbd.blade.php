{{-- A key chip. On a primary button it takes the button's text colour, so it reads on the button in both themes. --}}
<kbd
    @isset($show) x-show="{{ $show }}" @endisset
    @class([
        'inline-flex items-center rounded-md border border-b-2 px-1.5 font-mono text-[11px] leading-4 font-semibold whitespace-nowrap',
        'border-gray-300 bg-white text-gray-700 dark:border-white/25 dark:bg-white/10 dark:text-gray-100' => ! ($onPrimary ?? false),
        'border-current/40 bg-current/10' => $onPrimary ?? false,
    ])
>{{ $key }}</kbd>
