<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Render views without a Vite dev server or build manifest, so tests pass in any checkout
     * (git worktrees have neither public/hot nor public/build).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
