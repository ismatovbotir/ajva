<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\User;
use App\Services\SalesMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_layout_is_light_only_olive_without_theme_script_or_toggle(): void
    {
        $html = $this->actingAs(User::factory()->create())->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-theme', $html);
        $this->assertStringNotContainsString("localStorage.getItem('theme')", $html);
        $this->assertStringNotContainsString('monitorTheme', $html);
        $this->assertStringNotContainsString('aria-pressed', $html);
        $this->assertStringContainsString('md:bg-brand-700', $html);   // green sidebar
        $this->assertStringContainsString('bg-brand-700', $html);      // green mobile bar
    }

    public function test_guest_and_login_layout_have_no_theme_script_or_toggle(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-theme', $html);
        $this->assertStringNotContainsString("localStorage.getItem('theme')", $html);
        $this->assertStringNotContainsString('aria-pressed', $html);
        $this->assertStringContainsString('text-brand-700', $html);
    }

    public function test_public_monitor_has_the_no_flash_script_and_toggle(): void
    {
        $token = Monitor::factory()->withLink()->create()->token;
        $html = $this->get('/monitor/'.$token)->assertOk()->getContent();

        $this->assertStringContainsString("localStorage.getItem('monitorTheme')", $html);
        $this->assertStringContainsString('data-monitor-theme', $html);
        $this->assertLessThan(strpos($html, '<body'), strpos($html, "localStorage.getItem('monitorTheme')"));
        $this->assertStringContainsString(':aria-pressed="light.toString()"', $html);
        $this->assertStringContainsString('aria-label="'.__('Light theme').'"', $html);
        $this->assertStringContainsString('monitor-root', $html);
    }

    public function test_authenticated_monitor_stays_dark_without_a_toggle(): void
    {
        $monitor = Monitor::factory()->create();
        $html = $this->actingAs(User::factory()->create())->get('/monitors/'.$monitor->id)->assertOk()->getContent();

        $this->assertStringContainsString('monitor-root', $html);
        $this->assertStringNotContainsString('monitorTheme', $html);
        $this->assertStringNotContainsString('aria-pressed', $html);
    }

    public function test_admin_palette_is_the_original_olive_and_series_colours(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('--color-brand-700: #2a5039', $css);
        $this->assertStringContainsString('--color-sand-50: #faf3e3', $css);
        $this->assertStringNotContainsString('html[data-theme', $css);
        $this->assertStringContainsString('html.monitor-root[data-monitor-theme="light"]', $css);

        $this->assertSame(
            ['#2a78d6', '#1baf7a', '#eda100', '#008300', '#4a3aa7', '#c23a3a', '#a13a7a'],
            SalesMetrics::COLORS
        );
        $this->assertSame('#898781', SalesMetrics::COLOR_OTHER);
    }

    public function test_views_use_no_leftover_theme_tokens(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($iterator as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            if (preg_match('/(?<![\w-])bg-surface(?![\w-])|text-accent|bg-sidebar|data-theme|theme-toggle|theme-script/', file_get_contents($file->getPathname()), $m)) {
                $offenders[] = $file->getFilename().': '.$m[0];
            }
        }

        $this->assertSame([], $offenders);
    }
}
