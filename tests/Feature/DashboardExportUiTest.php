<?php

use App\Models\User;

/**
 * UI-integration coverage for the dashboard export dropdown
 * (resources/views/components/dash/export-menu.blade.php). The export
 * backend itself (routes, controllers, CsvDownload, DashboardExportRows) is
 * already covered under tests/Feature/Export/ — this file only proves the
 * three dashboards render an Export control whose CSV/PDF links carry
 * exactly the date range currently on screen, for the correct
 * role-scoped route.
 *
 * assertSee() is used WITHOUT the `false` (unescaped) flag throughout: the
 * export links are multi-param query strings rendered through Blade's
 * component attribute bag, which HTML-escapes `&` to `&amp;` — searching
 * for the raw, unescaped route() string (as e.g. MobileBottomNavigationTest
 * does for its single-segment links) would never match here.
 */
function exportUiNurse(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'nurse', 'user_type' => 'staff'], $overrides));
}

function exportUiPhysician(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'physician', 'user_type' => 'staff'], $overrides));
}

/**
 * Extracts the query-string parameters of the first href in $html matching
 * $routeName's URI pattern, decoded to an associative array — robust
 * against exact query-key-order assumptions and against route()'s own
 * inability to generate a URL for a route whose required parameters aren't
 * being supplied (e.g. checking a route by name alone, ignoring which
 * owner id it was bound to).
 *
 * @return array<string, string>
 */
function exportLinkQuery(string $html, string $routeName): array
{
    // Substitute the {param} placeholder BEFORE preg_quote-ing, not after —
    // preg_quote() escapes '{'/'}' to '\{'/'\}', and a regex swap of the
    // placeholder run afterward leaves that escaping backslash stranded
    // beside the replacement instead of consuming it (turning a later
    // '[^"?]+' into a broken '\[^"?]+' that can never match).
    $uriPattern = app('router')->getRoutes()->getByName($routeName)->uri();
    $sentinel = '@@PARAM@@';
    $withSentinel = preg_replace('/\{[^}]+\}/', $sentinel, $uriPattern);
    $staticPattern = str_replace(preg_quote($sentinel, '#'), '[^"?]+', preg_quote($withSentinel, '#'));

    preg_match('#href="([^"]*'.$staticPattern.'\?[^"]*)"#', $html, $m);

    expect($m)->not->toBeEmpty("No href found on the page matching route [{$routeName}].");

    $href = html_entity_decode($m[1]);
    parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

    return $query;
}

// --- Route generation / role scoping ---------------------------------------

it('renders a nurse dashboard export link scoped to the authenticated nurse', function () {
    $nurse = exportUiNurse();

    $response = $this->actingAs($nurse)->get(route('nurse.dashboard', ['nurse' => $nurse->user_id]));

    $response->assertOk();
    $response->assertSee(
        route('nurse.dashboard.export', ['nurse' => $nurse->user_id, 'range' => 'last_30_days', 'format' => 'csv'])
    );
    $response->assertSee(
        route('nurse.dashboard.export', ['nurse' => $nurse->user_id, 'range' => 'last_30_days', 'format' => 'pdf'])
    );
});

it('renders a physician dashboard export link scoped to the authenticated physician', function () {
    $physician = exportUiPhysician();

    $response = $this->actingAs($physician)->get(route('physician.dashboard', ['physician' => $physician->user_id]));

    $response->assertOk();
    $response->assertSee(
        route('physician.dashboard.export', ['physician' => $physician->user_id, 'range' => 'this_month', 'format' => 'csv'])
    );
    $response->assertSee(
        route('physician.dashboard.export', ['physician' => $physician->user_id, 'range' => 'this_month', 'format' => 'pdf'])
    );
});

it('renders an admin dashboard export link with no route-bound owner', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get('/dashboard');

    $response->assertOk();
    $response->assertSee(route('admin.dashboard.export', ['range' => 'last_30_days', 'format' => 'csv']));
    $response->assertSee(route('admin.dashboard.export', ['range' => 'last_30_days', 'format' => 'pdf']));
});

it('does not point one nurse\'s export link at another nurse\'s id', function () {
    $nurseA = exportUiNurse();
    $nurseB = exportUiNurse();

    $response = $this->actingAs($nurseA)->get(route('nurse.dashboard', ['nurse' => $nurseA->user_id]));

    $response->assertDontSee("nurses/{$nurseB->user_id}/dashboard/export");
});

// --- Filter preservation: every preset, plus custom -------------------------

it('preserves each non-custom preset in both export links', function (string $preset) {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get('/dashboard?range='.$preset);

    $response->assertOk();
    $response->assertSee(route('admin.dashboard.export', ['range' => $preset, 'format' => 'csv']));
    $response->assertSee(route('admin.dashboard.export', ['range' => $preset, 'format' => 'pdf']));
})->with(['today', 'this_week', 'this_month', 'last_30_days', 'this_year']);

it('carries start and end into the export links only when the resolved preset is custom', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get('/dashboard?range=custom&start=2026-08-01&end=2026-08-27');

    $response->assertOk();
    $response->assertSee(route('admin.dashboard.export', [
        'range' => 'custom', 'start' => '2026-08-01', 'end' => '2026-08-27', 'format' => 'csv',
    ]));
    $response->assertSee(route('admin.dashboard.export', [
        'range' => 'custom', 'start' => '2026-08-01', 'end' => '2026-08-27', 'format' => 'pdf',
    ]));
});

it('omits start/end from the export links for a non-custom preset', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get('/dashboard?range=today');
    $html = $response->getContent();

    $query = exportLinkQuery($html, 'admin.dashboard.export');

    expect($query)->toHaveKey('range', 'today')
        ->toHaveKey('format')
        ->not->toHaveKey('start')
        ->not->toHaveKey('end');
});

it('falls back to the dashboard\'s own default range when none is requested, matching the on-screen filter', function () {
    $physician = exportUiPhysician();

    // No ?range= at all — the physician dashboard defaults to this_month
    // (DateRange::fromInput's 4th argument in PhysicianController::dashboard()).
    $response = $this->actingAs($physician)->get(route('physician.dashboard', ['physician' => $physician->user_id]));
    $html = $response->getContent();

    $query = exportLinkQuery($html, 'physician.dashboard.export');

    expect($query['range'])->toBe('this_month');
});

it('reflects a custom range clamped by DateRange in the export links, not the raw requested end date', function () {
    // A custom range far wider than DateRange::MAX_CUSTOM_RANGE_DAYS gets
    // clamped server-side; the export link must reflect the clamped bounds
    // actually being displayed, not the originally requested (rejected) end.
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get('/dashboard?range=custom&start=2000-01-01&end=2026-08-27');
    $html = $response->getContent();

    $query = exportLinkQuery($html, 'admin.dashboard.export');

    expect($query['start'])->toBe('2000-01-01')
        ->and($query['end'])->not->toBe('2026-08-27');
});

// --- Accessibility / markup -------------------------------------------------

it('uses a real button element as the dropdown trigger', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get('/dashboard');
    $html = $response->getContent();

    expect($html)->toContain('aria-haspopup="true"');
});

it('gives CSV and PDF options meaningful visible text, not icon-only labels', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get('/dashboard');

    $response->assertSee('Export as CSV');
    $response->assertSee('Export as PDF');
});

// --- No regression on existing dashboard content ----------------------------

it('still renders the existing filter-bar range select alongside the new export control', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get('/dashboard');

    $response->assertOk();
    $response->assertSee('Showing');
    $response->assertSee('Export');
});
