<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_on_desktop_the_sidebar_is_full_window_height_and_only_the_content_scrolls(): void
    {
        $html = $this->actingAs(User::factory()->create())->get('/')->assertOk()->getContent();

        // The shell is exactly one screen tall and clips; the sidebar fills it...
        $this->assertStringContainsString('md:h-screen md:overflow-hidden', $html);
        $this->assertMatchesRegularExpression('~<aside[^>]*class="[^"]*md:h-full[^"]*"~s', $html);
        // ...and <main> is the scroll container.
        $this->assertMatchesRegularExpression('~<main[^>]*class="[^"]*md:overflow-y-auto[^"]*"~s', $html);
    }
}
