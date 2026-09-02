/**
 * Chart.js initialization for the nurse/physician/admin operational
 * dashboards. Loaded only on those three pages (see the @vite call in each
 * dashboard Blade view) — not bundled into the global app.js entry, since
 * no other page needs it.
 *
 * This file is presentation only: it reads the already-computed
 * {labels, datasets} payload each chart canvas carries in its
 * `data-chart-payload` attribute (produced server-side by
 * DashboardAnalyticsService) and turns it into a Chart.js instance. It
 * never fetches data, recalculates a metric, or determines ownership —
 * the server remains the sole source of truth for every number rendered
 * here.
 *
 * Only the controllers/elements actually used are registered (no
 * `chart.js/auto`) to keep this bundle small: every chart on these
 * dashboards is a line or a bar.
 */
import {
    Chart,
    BarController,
    BarElement,
    LineController,
    LineElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
} from 'chart.js';

Chart.register(
    BarController,
    BarElement,
    LineController,
    LineElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
);

const BRAND_GREEN = '#0f6b3d';
const BRAND_TEAL = '#1d7fa8';

// Mirrors design-system/clsu-telemedicine/pages/dashboards-shared.md §2 —
// the same colors used for the <x-dash.badge> status/priority pills, so a
// bar in a chart and a badge in a table always mean the same color.
const STATUS_COLORS = {
    pending: '#f59e0b',
    reviewed: '#0891b2',
    scheduled: '#4f46e5',
    active: '#0f6b3d',
    completed: '#94a3b8',
    rejected: '#dc2626',
    cancelled: '#cbd5e1',
};

const PRIORITY_COLORS = { High: '#dc2626', Normal: '#94a3b8' };
const TYPE_COLORS = { Initial: BRAND_GREEN, 'Follow-up': BRAND_TEAL };

// A validated categorical palette (dataviz skill's references/palette.md) for
// charts with no other natural per-bar color, e.g. "Most reported symptoms" —
// unlike hbar-status/splitbar-priority/-type above, there's no fixed
// label->meaning mapping to key off, just N distinct categories. Order is the
// CVD-safety mechanism (validated on the adjacent pairlist, not cosmetic) —
// don't reorder. Cycles past 8 categories, which would start repeating a
// color; there's no chart on these dashboards that currently shows more.
const CATEGORICAL_PALETTE = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];

// Sev 1 -> Sev 4, left to right. Index 2 (severity 3) is overridden with a
// diagonal hatch pattern below — it's the pre-selected default value, not
// necessarily a deliberate patient choice (Phase 1 §07 / Phase 2 AD-10).
const SEVERITY_COLORS = ['#cbd5e1', '#fcd34d', '#f59e0b', '#b91c1c'];
const SEVERITY_DEFAULT_INDEX = 2;

function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false;
}

/**
 * A small tileable diagonal-stripe pattern, used as a bar's fill so the
 * "this bucket contains defaults" caveat is visible without color and
 * without reading the footnote (non-color encoding, Phase 2 §2/§11).
 */
function hatchPattern(baseColor) {
    const tile = document.createElement('canvas');
    tile.width = 8;
    tile.height = 8;
    const ctx = tile.getContext('2d');
    ctx.fillStyle = baseColor;
    ctx.fillRect(0, 0, 8, 8);
    ctx.strokeStyle = 'rgba(255,255,255,0.65)';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(0, 8);
    ctx.lineTo(8, 0);
    ctx.stroke();

    return ctx.createPattern(tile, 'repeat');
}

function baseOptions(extra = {}) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: prefersReducedMotion() ? false : { duration: 250 },
        plugins: {
            legend: { display: false },
            tooltip: { enabled: true },
            ...(extra.plugins ?? {}),
        },
        ...extra,
    };
}

function seriesLabel(payload, fallback) {
    return payload.datasets?.[0]?.label ?? fallback;
}

function initLine(canvas, payload) {
    const data = payload.datasets[0]?.data ?? [];

    return new Chart(canvas, {
        type: 'line',
        data: {
            labels: payload.labels,
            datasets: [{
                label: seriesLabel(payload, 'Requests'),
                data,
                borderColor: BRAND_GREEN,
                backgroundColor: 'rgba(15, 107, 61, 0.12)',
                fill: true,
                tension: 0.25,
                pointRadius: data.length > 60 ? 0 : 2,
                pointHoverRadius: 4,
            }],
        },
        options: baseOptions({
            scales: {
                x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 }, grid: { display: false } },
                y: { beginAtZero: true, ticks: { precision: 0 } },
            },
        }),
    });
}

function initHorizontalBar(canvas, payload, colorFor) {
    const labels = payload.labels;
    const data = payload.datasets[0]?.data ?? [];

    return new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: seriesLabel(payload, 'Requests'),
                data,
                backgroundColor: labels.map((label, i) => (
                    colorFor ? colorFor(label) : CATEGORICAL_PALETTE[i % CATEGORICAL_PALETTE.length]
                )),
            }],
        },
        options: baseOptions({
            indexAxis: 'y',
            scales: {
                x: { beginAtZero: true, ticks: { precision: 0 } },
                y: { grid: { display: false } },
            },
        }),
    });
}

/**
 * A single horizontal bar split into segments — one per label — used for a
 * binary split (priority, initial vs follow-up) where the whole point is
 * to see the proportion at a glance rather than compare two separate bars.
 * Reshapes the backend's {labels:[a,b], datasets:[{data:[x,y]}]} into one
 * category with two stacked datasets; this is a display-only transform of
 * numbers the server already computed, not a recalculation of them.
 */
function initSplitBar(canvas, payload, colorFor) {
    const labels = payload.labels;
    const data = payload.datasets[0]?.data ?? [];

    return new Chart(canvas, {
        type: 'bar',
        data: {
            labels: [''],
            datasets: labels.map((label, i) => ({
                label,
                data: [data[i] ?? 0],
                backgroundColor: colorFor ? colorFor(label) : BRAND_GREEN,
            })),
        },
        options: baseOptions({
            indexAxis: 'y',
            plugins: { legend: { display: true, position: 'bottom' } },
            scales: {
                x: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
                y: { stacked: true, display: false },
            },
        }),
    });
}

function initSeverityBar(canvas, payload) {
    const labels = payload.labels;
    const data = payload.datasets[0]?.data ?? [];

    return new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: seriesLabel(payload, 'Reports'),
                data,
                backgroundColor: labels.map((_, i) => (
                    i === SEVERITY_DEFAULT_INDEX
                        ? hatchPattern(SEVERITY_COLORS[SEVERITY_DEFAULT_INDEX])
                        : (SEVERITY_COLORS[i] ?? BRAND_GREEN)
                )),
            }],
        },
        options: baseOptions({
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, ticks: { precision: 0 } },
            },
        }),
    });
}

const RENDERERS = {
    line: (canvas, payload) => initLine(canvas, payload),
    // STATUS_COLORS keys are lowercase (matching request_status's raw enum
    // value); labels reaching here are ucfirst()'d for display
    // (admin/dashboard.blade.php's $statusLabelsForDisplay), so the lookup
    // must lowercase first or every bar falls through to the gray default.
    'hbar-status': (canvas, payload) => initHorizontalBar(canvas, payload, (l) => STATUS_COLORS[l.toLowerCase()] ?? '#94a3b8'),
    hbar: (canvas, payload) => initHorizontalBar(canvas, payload, null),
    'splitbar-priority': (canvas, payload) => initSplitBar(canvas, payload, (l) => PRIORITY_COLORS[l] ?? '#94a3b8'),
    'splitbar-type': (canvas, payload) => initSplitBar(canvas, payload, (l) => TYPE_COLORS[l] ?? BRAND_GREEN),
    severity: (canvas, payload) => initSeverityBar(canvas, payload),
};

function renderChart(canvas) {
    let payload;

    try {
        payload = JSON.parse(canvas.dataset.chartPayload ?? '{}');
    } catch (error) {
        console.error('[dashboards] could not parse chart payload', error);
        return;
    }

    const type = canvas.dataset.chartType;
    const render = RENDERERS[type] ?? RENDERERS.hbar;

    try {
        render(canvas, payload);
    } catch (error) {
        // A single bad chart must never take the rest of the dashboard down.
        console.error(`[dashboards] chart "${type}" failed to render`, error);
        const wrapper = canvas.closest('[data-chart-wrapper]') ?? canvas.parentElement;
        if (wrapper) {
            wrapper.innerHTML = '<p class="text-sm text-red-600" role="alert">This chart could not be displayed. The data table below is still available.</p>';
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('canvas[data-chart]').forEach(renderChart);
});
