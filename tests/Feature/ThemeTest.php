<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_layout_has_the_no_flash_script_and_an_accessible_toggle(): void
    {
        $html = $this->actingAs(User::factory()->create())->get('/')->assertOk()->getContent();

        $this->assertStringContainsString("localStorage.getItem('theme')", $html);
        $this->assertStringContainsString("setAttribute('data-theme'", $html);
        $this->assertStringContainsString("prefers-color-scheme: dark", $html);
        // the script runs in <head>, before the stylesheet / body
        $this->assertLessThan(strpos($html, '<body'), strpos($html, "localStorage.getItem('theme')"));
        $this->assertStringContainsString(':aria-pressed="dark.toString()"', $html);
        $this->assertStringContainsString('aria-label="'.__('Dark theme').'"', $html);
        // desktop sidebar + mobile top bar + drawer
        $this->assertGreaterThanOrEqual(3, substr_count($html, ':aria-pressed="dark.toString()"'));
    }

    public function test_guest_and_login_layout_have_the_script_and_toggle(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString("localStorage.getItem('theme')", $html);
        $this->assertStringContainsString(':aria-pressed="dark.toString()"', $html);
        $this->assertStringContainsString('aria-label="'.__('Dark theme').'"', $html);
    }

    public function test_monitor_layout_stays_dark_without_a_toggle(): void
    {
        $html = $this->actingAs(User::factory()->create())->get('/monitor')->assertOk()->getContent();

        $this->assertStringContainsString('monitor-root', $html);
        $this->assertStringNotContainsString('aria-pressed="dark', $html);
        $this->assertStringNotContainsString(':aria-pressed="dark.toString()"', $html);
    }

    public function test_no_old_palette_neutral_hex_classes_remain_in_views(): void
    {
        // Neutral colours must come from the theme tokens so they flip in dark mode.
        // Allowed: the monitor (always dark), the unused stock welcome page, and literal
        // chart/series colours (not neutrals).
        $allowed = ['livewire/monitor.blade.php', 'layouts/monitor.blade.php', 'welcome.blade.php'];
        $old = '/(?:text|bg|border|fill|stroke|divide|ring)-\[#(?:52514e|0b0b0b|e1e0d9|fcfcfb|e2e8f0)\]|(?:fill|stroke)="#(?:52514e|0b0b0b|e1e0d9|fcfcfb)"|\bbg-white\b(?![\/-])/i';

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($iterator as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            foreach ($allowed as $a) {
                if (str_ends_with($path, $a)) {
                    continue 2;
                }
            }
            if (preg_match($old, file_get_contents($path), $m)) {
                $offenders[] = basename($path).': '.$m[0];
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_css_defines_light_and_dark_tokens(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('html[data-theme="dark"]', $css);
        $this->assertStringContainsString('--color-surface', $css);
        $this->assertStringContainsString('--color-accent', $css);
        $this->assertStringContainsString('html.monitor-root', $css);
    }
}
