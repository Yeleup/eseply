<?php

namespace App\Support;

final readonly class BillingClosureIssue
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $context = [],
    ) {}
}
