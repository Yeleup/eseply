<section class="receipt-a4-page">
    @foreach ($pageCopies as $pageCopy)
        <div class="receipt-a4-cell">
            @include('receipts.partials.print-copy', $pageCopy)
        </div>
    @endforeach
</section>
