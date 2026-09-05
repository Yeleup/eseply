{{--
    Camera capture for the meter reading photo field.

    The photo field appears in four places — the readings resource form, both
    readings relation managers and the «Фото и примечание» modal of the entry
    page — so the script is loaded for the whole panel instead of being pushed
    from a single page view. It attaches itself only to the fields that ask for
    it through `x-init`.

    The manifest guard mirrors `design-preview.blade.php`: the test suite runs
    without a build, and a missing manifest must not fail every panel page.
--}}
@if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
    @vite('resources/js/meter-photo-capture.js')
@endif
