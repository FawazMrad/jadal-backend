<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Android App Links / share-link fallback.
 *
 * These guard an artifact whose failure mode is SILENT: if
 * public/.well-known/assetlinks.json is malformed, missing, or loses the
 * signing fingerprint, Android verification fails with no error in any of our
 * logs and every share link quietly opens a browser instead of the app.
 * Nothing else in the suite would catch that.
 */
class AppLinksTest extends TestCase
{
    private const ASSETLINKS = 'public/.well-known/assetlinks.json';

    private function assetlinksPath(): string
    {
        return base_path(self::ASSETLINKS);
    }

    public function test_assetlinks_file_exists_and_is_valid_json(): void
    {
        $this->assertFileExists(
            $this->assetlinksPath(),
            self::ASSETLINKS . ' is missing — Android App Links verification will fail silently.'
        );

        $decoded = json_decode(file_get_contents($this->assetlinksPath()), true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'assetlinks.json is not valid JSON');
        // Google requires a JSON ARRAY of statements at the top level.
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey(0, $decoded, 'assetlinks.json must be a JSON array of statements');
    }

    public function test_assetlinks_declares_the_app_with_a_wellformed_fingerprint(): void
    {
        $statement = json_decode(file_get_contents($this->assetlinksPath()), true)[0];

        $this->assertSame(
            ['delegate_permission/common.handle_all_urls'],
            $statement['relation'],
            'relation must be exactly delegate_permission/common.handle_all_urls'
        );
        $this->assertSame('android_app', $statement['target']['namespace']);

        // The package here and config('app.android_package') drive different
        // things (verification vs. the intent:// URI). If they drift apart the
        // button silently stops opening the app.
        $this->assertSame(
            config('app.android_package'),
            $statement['target']['package_name'],
            'assetlinks package_name must match config(app.android_package)'
        );

        $fingerprints = $statement['target']['sha256_cert_fingerprints'];

        $this->assertIsArray($fingerprints);
        $this->assertNotEmpty($fingerprints, 'at least one signing fingerprint is required');

        foreach ($fingerprints as $fingerprint) {
            // 32 colon-separated uppercase hex octets. A lowercase or
            // truncated value is accepted by no verifier.
            $this->assertMatchesRegularExpression(
                '/^(?:[0-9A-F]{2}:){31}[0-9A-F]{2}$/',
                $fingerprint,
                "Malformed SHA-256 fingerprint: {$fingerprint}"
            );
        }
    }

    public function test_assetlinks_is_served_as_json_without_redirect(): void
    {
        // In production the webserver serves this off disk and Laravel is never
        // reached (public/.htaccess rewrites only when !-f). This exercises the
        // in-app safety-net route, which is what answers if a deploy ever drops
        // the file from the webroot.
        $response = $this->get('/.well-known/assetlinks.json');

        $response->assertStatus(200);
        $this->assertStringStartsWith('application/json', $response->headers->get('Content-Type'));

        $decoded = json_decode($response->streamedContent() ?: $response->getContent(), true);
        $this->assertSame('android_app', $decoded[0]['target']['namespace']);
    }

    public function test_share_link_page_offers_a_working_way_into_the_app(): void
    {
        config()->set('app.frontend_share_base_url', 'https://jadal-platform.com');
        config()->set('app.android_package', 'com.jadalplatform.app');

        $response = $this->get('/d/42')->assertStatus(200);

        // The bug being fixed: the page used to be a dead end with no way out.
        //
        // Asserted as the COMPLETE string, not as loose fragments: the
        // `#Intent;` separator, the `;` between parameters and the `;end`
        // terminator are all load-bearing. Android silently ignores a
        // malformed intent URI, which would reproduce the exact dead-end this
        // change exists to fix. browser_fallback_url must be percent-encoded
        // because `;` and `&` terminate intent parameters.
        $expected = 'intent://jadal-platform.com/d/42'
            . '#Intent;scheme=https'
            . ';package=com.jadalplatform.app'
            . ';S.browser_fallback_url=https%3A%2F%2Fjadal-platform.com%2Fd%2F42'
            . ';end';

        $response->assertSee($expected, false);
    }

    public function test_share_link_page_hides_store_links_when_unconfigured(): void
    {
        // The app is not published yet; a hardcoded store URL would be a dead
        // link. Absent config must mean absent link, not a broken one.
        config()->set('app.android_store_url', null);
        config()->set('app.ios_store_url', null);

        $this->get('/d/42')
            ->assertStatus(200)
            ->assertDontSee('Google Play')
            ->assertDontSee('App Store');
    }

    public function test_share_link_page_shows_store_links_when_configured(): void
    {
        config()->set('app.android_store_url', 'https://play.google.com/store/apps/details?id=com.jadalplatform.app');

        $this->get('/d/42')
            ->assertStatus(200)
            ->assertSee('Google Play');
    }

    public function test_share_link_page_still_leaks_nothing_about_the_debate(): void
    {
        // The no-lookup property must survive the redesign: every id renders
        // identically, so this is not an existence oracle.
        $real    = $this->get('/d/42')->assertStatus(200)->getContent();
        $missing = $this->get('/d/99999999')->assertStatus(200)->getContent();

        $this->assertSame(
            str_replace('99999999', '42', $missing),
            $real,
            'pages for existing and non-existing debates must differ only by the id'
        );
    }
}
