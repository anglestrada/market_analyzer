/* Kalshi Market Analyzer — UI behaviour and Chart.js charts.
 *
 * Pages embed data as <script type="application/json" id="...">, and mark canvases with
 *   <canvas data-chart="price" data-source="d-price" data-group="m" data-empty="No data">
 * Each data-chart value maps to a builder in `builders` below.
 */
(() => {
  'use strict';

  const root = document.documentElement;
  const css = (name) => getComputedStyle(root).getPropertyValue(name).trim();
  const $$ = (sel, el = document) => Array.from(el.querySelectorAll(sel));
  const readJSON = (id) => {
    const el = id && document.getElementById(id);
    if (!el) return null;
    try { return JSON.parse(el.textContent); } catch { return null; }
  };

  // Dark grey + green palette (mirrors the CSS variables in app.css).
  const C = {
    yes: '#7ccba6', no: '#d9b382', accent: '#7ccba6', amber: '#e2b35d', sky: '#8fb4d6',
    violet: '#b4a7d6', slate: '#6f7873', down: '#f07a7a', up: '#63d394',
  };
  const STATUS = {
    article_found: { label: 'Article found', color: '#7ccba6' },
    no_explanation_found: { label: 'No explanation found', color: '#6f7873' },
    news_search_failed: { label: 'Search failed', color: '#e2b35d' },
    pending: { label: 'Pending', color: '#8fb4d6' },
  };
  const PALETTE = ['#7ccba6', '#d9b382', '#8fb4d6', '#e2b35d', '#b4a7d6', '#f07a7a', '#63d394', '#c9ced0'];

  const rgba = (hex, a) => {
    const n = parseInt(hex.slice(1), 16);
    return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`;
  };
  /** Vertical fade under a line. */
  const gradient = (hex, top = 0.3) => (ctx) => {
    const { chart } = ctx;
    const area = chart.chartArea;
    if (!area) return rgba(hex, top / 2);
    const g = chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
    g.addColorStop(0, rgba(hex, top));
    g.addColorStop(1, rgba(hex, 0));
    return g;
  };
  const cents = (v) => (v == null ? '—' : `${(+v).toFixed(1)}¢`);
  const num = (v) => (v == null ? '—' : Number(v).toLocaleString());

  /* ------------------------------------------------------------------
   * Chart.js defaults for the dark theme
   * ---------------------------------------------------------------- */
  function themeCharts() {
    if (!window.Chart) return;
    const d = Chart.defaults;
    d.color = css('--muted');
    d.borderColor = css('--grid');
    d.font.family = getComputedStyle(document.body).fontFamily;
    d.font.size = 12;
    const t = d.plugins.tooltip;
    Object.assign(t, {
      backgroundColor: css('--tooltip-bg'), titleColor: css('--text'), bodyColor: css('--text'), footerColor: css('--muted'),
      borderColor: css('--border'), borderWidth: 1, padding: 10, cornerRadius: 10, boxPadding: 4, usePointStyle: true,
    });
    Object.assign(d.plugins.legend.labels, { usePointStyle: true, boxWidth: 8, boxHeight: 8, padding: 14 });
    d.elements.line.borderWidth = 2;
    d.elements.point.radius = 0;
    d.elements.point.hoverRadius = 5;
    d.elements.bar.borderRadius = 4;
    d.animation.duration = 650;
    Object.values(Chart.instances).forEach((c) => c.update('none'));
  }

  /* ------------------------------------------------------------------
   * Shared chart options
   * ---------------------------------------------------------------- */
  const timeX = (extra = {}) => ({ type: 'time', grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 28 }, ...extra });
  const centsY = (extra = {}) => ({ min: 0, max: 100, ticks: { callback: (v) => `${v}¢`, stepSize: 25 }, ...extra });

  const groups = {};
  const hasZoom = () => { try { return !!Chart.registry.getPlugin('zoom'); } catch { return false; } };
  const syncGroup = ({ chart }) => {
    const peers = chart.$group && groups[chart.$group];
    if (!peers) return;
    const { min, max } = chart.scales.x;
    peers.forEach((o) => { if (o !== chart && o.zoomScale) o.zoomScale('x', { min, max }, 'none'); });
  };
  const zoomOptions = () => (hasZoom() ? {
    zoom: { wheel: { enabled: true, speed: 0.12 }, pinch: { enabled: true }, mode: 'x', onZoomComplete: syncGroup },
    pan: { enabled: true, mode: 'x', onPanComplete: syncGroup },
    limits: { x: { min: 'original', max: 'original', minRange: 30 * 60 * 1000 } },
  } : undefined);

  /** Big number in the middle of a doughnut. */
  const centerText = {
    id: 'centerText',
    afterDraw(chart, _args, opts) {
      if (!opts || opts.text == null) return;
      const { ctx, chartArea: { left, right, top, bottom } } = chart;
      const x = (left + right) / 2;
      const y = (top + bottom) / 2;
      ctx.save();
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillStyle = css('--text');
      ctx.font = `700 28px ${Chart.defaults.font.family}`;
      ctx.fillText(opts.text, x, y - 8);
      ctx.fillStyle = css('--muted');
      ctx.font = `12px ${Chart.defaults.font.family}`;
      ctx.fillText(opts.sub || '', x, y + 16);
      ctx.restore();
    },
  };

  /* ------------------------------------------------------------------
   * Chart builders. Return a Chart, or false when there is nothing to draw.
   * ---------------------------------------------------------------- */
  const builders = {
    /** Market page: YES/NO price, movement markers, per-interval volume. */
    price(canvas, d) {
      const s = d.series || [];
      if (!s.length) return false;
      let prev = null;
      const vol = s.map((p) => {
        let dv = null;
        if (p.v != null) { if (prev != null) dv = Math.max(0, p.v - prev); prev = p.v; }
        return { x: p.t, y: dv };
      });
      const maxVol = Math.max(1, ...vol.map((v) => v.y || 0));
      const half = (d.bucket || 300) * 1000;
      const moves = d.moves || [];

      return new Chart(canvas, {
        type: 'line',
        data: {
          datasets: [
            { label: d.yesLabel || 'YES', data: s.map((p) => ({ x: p.t, y: p.yes })), borderColor: C.yes, backgroundColor: gradient(C.yes, 0.28), fill: 'origin', tension: 0.25, spanGaps: true, order: 2 },
            { label: d.noLabel || 'NO', data: s.map((p) => ({ x: p.t, y: p.no })), borderColor: C.no, backgroundColor: C.no, borderDash: [5, 4], borderWidth: 1.5, tension: 0.25, spanGaps: true, order: 3 },
            { type: 'scatter', label: 'Movement', data: moves, backgroundColor: C.down, borderColor: '#7a2e2e', borderWidth: 1, pointStyle: 'triangle', rotation: 180, pointRadius: 8, pointHoverRadius: 10, order: 0 },
            { type: 'bar', label: 'Volume', data: vol, yAxisID: 'vol', backgroundColor: rgba(C.sky, 0.35), borderRadius: 2, barPercentage: 1, categoryPercentage: 1, order: 4 },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          scales: { x: timeX(), y: centsY(), vol: { position: 'right', display: false, beginAtZero: true, max: maxVol * 4 } },
          plugins: {
            zoom: zoomOptions(),
            tooltip: {
              filter: (i) => i.dataset.type !== 'scatter',
              callbacks: {
                label: (i) => (i.dataset.yAxisID === 'vol' ? ` Volume: ${num(i.parsed.y)}` : ` ${i.dataset.label}: ${cents(i.parsed.y)}`),
                afterBody: (items) => {
                  if (!items.length) return [];
                  const x = items[0].parsed.x;
                  return moves.filter((m) => Math.abs(Date.parse(m.x) - x) <= half).map((m) => `▼ ${m.label}`);
                },
              },
            },
          },
        },
      });
    },

    /** Market page: YES bid/ask band and last trade. */
    spread(canvas, d) {
      const s = d.series || [];
      if (!s.some((p) => p.bid != null || p.ask != null || p.last != null)) return false;
      return new Chart(canvas, {
        type: 'line',
        data: {
          datasets: [
            { label: 'YES ask', data: s.map((p) => ({ x: p.t, y: p.ask })), borderColor: rgba(C.down, 0.8), borderWidth: 1.5, backgroundColor: rgba(C.accent, 0.12), fill: '+1', stepped: true, spanGaps: true },
            { label: 'YES bid', data: s.map((p) => ({ x: p.t, y: p.bid })), borderColor: rgba(C.yes, 0.85), borderWidth: 1.5, fill: false, stepped: true, spanGaps: true },
            { label: 'Last trade', data: s.map((p) => ({ x: p.t, y: p.last })), borderColor: C.amber, borderWidth: 1.5, borderDash: [2, 3], fill: false, stepped: true, spanGaps: true },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          scales: { x: timeX(), y: { ticks: { callback: (v) => `${v}¢` } } },
          plugins: {
            legend: { position: 'bottom' },
            zoom: zoomOptions(),
            tooltip: { callbacks: { label: (i) => ` ${i.dataset.label}: ${cents(i.parsed.y)}` } },
          },
        },
      });
    },

    /** Movements per day, stacked by explanation status. */
    movementsDaily(canvas, d) {
      if (!d.labels || !d.labels.length || !d.total) return false;
      const sets = Object.entries(d.datasets).filter(([, v]) => v.some((n) => n > 0));
      return new Chart(canvas, {
        type: 'bar',
        data: {
          labels: d.labels,
          datasets: sets.map(([k, v]) => ({
            label: STATUS[k]?.label || k, data: v, backgroundColor: STATUS[k]?.color || C.slate,
            borderRadius: 4, borderSkipped: false, maxBarThickness: 26,
          })),
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          scales: {
            x: { stacked: true, grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
            y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
          },
          plugins: { legend: { position: 'bottom' } },
        },
      });
    },

    /** Doughnut of explanation outcomes. */
    statusDonut(canvas, d) {
      const entries = Object.entries(d.totals || {}).filter(([, n]) => n > 0);
      if (!entries.length) return false;
      const total = entries.reduce((a, [, n]) => a + n, 0);
      return new Chart(canvas, {
        type: 'doughnut',
        data: {
          labels: entries.map(([k]) => STATUS[k]?.label || k),
          datasets: [{
            data: entries.map(([, n]) => n),
            backgroundColor: entries.map(([k]) => STATUS[k]?.color || C.slate),
            borderColor: () => css('--card'), borderWidth: 3, hoverOffset: 6,
          }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '72%',
          plugins: {
            legend: { position: 'bottom' },
            centerText: { text: String(total), sub: total === 1 ? 'movement' : 'movements' },
            tooltip: { callbacks: { label: (i) => ` ${i.label}: ${i.parsed} (${Math.round((100 * i.parsed) / total)}%)` } },
          },
        },
        plugins: [centerText],
      });
    },

    /** Drop-size histogram. */
    histogram(canvas, d) {
      return new Chart(canvas, {
        type: 'bar',
        data: { labels: d.labels, datasets: [{ label: 'Movements', data: d.values, backgroundColor: d.values.map((_, i) => rgba(C.down, 0.4 + i * 0.1)), borderRadius: 6 }] },
        options: {
          responsive: true, maintainAspectRatio: false,
          scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 } } },
          plugins: { legend: { display: false } },
        },
      });
    },

    /** Movements by hour of day. */
    hours(canvas, d) {
      const max = Math.max(1, ...d.values);
      return new Chart(canvas, {
        type: 'bar',
        data: { labels: d.labels, datasets: [{ label: 'Movements', data: d.values, backgroundColor: d.values.map((v) => rgba(C.accent, 0.25 + 0.75 * (v / max))), borderRadius: 4 }] },
        options: {
          responsive: true, maintainAspectRatio: false,
          scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 6 } }, y: { beginAtZero: true, ticks: { precision: 0 } } },
          plugins: { legend: { display: false } },
        },
      });
    },

    /** Horizontal bar of keyword matches. */
    keywords(canvas, d) {
      return new Chart(canvas, {
        type: 'bar',
        data: { labels: d.labels, datasets: [{ label: 'Matches', data: d.values, backgroundColor: d.labels.map((_, i) => PALETTE[i % PALETTE.length]), borderRadius: 6, maxBarThickness: 18 }] },
        options: {
          indexAxis: 'y', responsive: true, maintainAspectRatio: false,
          scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: { grid: { display: false } } },
          plugins: { legend: { display: false } },
        },
      });
    },

    /** Watchlist: several markets' YES price on one chart. */
    compare(canvas, d) {
      if (!d.series || !d.series.length) return false;
      return new Chart(canvas, {
        type: 'line',
        data: {
          datasets: d.series.map((s, i) => ({
            label: s.label, data: s.points, borderColor: PALETTE[i % PALETTE.length], backgroundColor: PALETTE[i % PALETTE.length],
            tension: 0.25, spanGaps: true,
          })),
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          interaction: { mode: 'nearest', axis: 'x', intersect: false },
          scales: { x: timeX(), y: centsY() },
          plugins: {
            legend: { position: 'bottom' },
            zoom: zoomOptions(),
            tooltip: { callbacks: { label: (i) => ` ${i.dataset.label}: ${cents(i.parsed.y)}` } },
          },
        },
      });
    },

    /** Admin: markets found per scan, movements, duration, status-coloured points. */
    scanHealth(canvas, d) {
      if (!d.length) return false;
      const sc = { completed: C.yes, partial: C.amber, failed: C.down, running: C.sky };
      return new Chart(canvas, {
        type: 'line',
        data: {
          datasets: [
            {
              label: 'Markets found', data: d.map((r) => ({ x: r.t, y: r.found })), borderColor: C.accent, backgroundColor: gradient(C.accent, 0.25),
              fill: 'origin', tension: 0.3, pointRadius: d.length < 150 ? 3 : 1.5, pointBackgroundColor: d.map((r) => sc[r.status] || C.slate), pointBorderWidth: 0,
            },
            { type: 'bar', label: 'Movements', data: d.map((r) => ({ x: r.t, y: r.moves })), yAxisID: 'mv', backgroundColor: rgba(C.amber, 0.75), maxBarThickness: 6 },
            { label: 'Duration (s)', data: d.map((r) => ({ x: r.t, y: r.dur })), yAxisID: 'dur', borderColor: rgba(C.sky, 0.8), borderDash: [3, 3], borderWidth: 1.5, tension: 0.3 },
          ],
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          scales: {
            x: timeX(),
            y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'markets' } },
            mv: { position: 'right', beginAtZero: true, grid: { display: false }, ticks: { precision: 0 }, title: { display: true, text: 'movements' } },
            dur: { display: false, beginAtZero: true },
          },
          plugins: {
            legend: { position: 'bottom' },
            tooltip: { callbacks: { afterBody: (items) => (items.length ? `Status: ${d[items[0].dataIndex].status}` : '') } },
          },
        },
      });
    },
  };

  function placeholder(canvas, text) {
    const p = document.createElement('div');
    p.className = 'chart-empty';
    p.textContent = text;
    canvas.replaceWith(p);
  }

  function initCharts() {
    const canvases = $$('canvas[data-chart]');
    if (!canvases.length) return;
    if (!window.Chart) {
      canvases.forEach((c) => placeholder(c, 'Charts could not load (Chart.js missing). Run "npm install" or check your internet connection.'));
      return;
    }
    if (window.ChartZoom) { try { Chart.register(window.ChartZoom); } catch { /* already registered */ } }
    themeCharts();

    canvases.forEach((canvas) => {
      const build = builders[canvas.dataset.chart];
      const data = readJSON(canvas.dataset.source);
      let chart = false;
      try { chart = build && data ? build(canvas, data) : false; } catch (err) { console.error(`[chart ${canvas.dataset.chart}]`, err); }
      if (!chart) { placeholder(canvas, canvas.dataset.empty || 'No data yet.'); return; }
      if (canvas.dataset.group) {
        chart.$group = canvas.dataset.group;
        (groups[chart.$group] ||= []).push(chart);
      }
    });

    $$('[data-reset-zoom]').forEach((btn) => {
      if (!hasZoom()) { btn.hidden = true; return; }
      btn.addEventListener('click', () => (groups[btn.dataset.resetZoom] || []).forEach((c) => c.resetZoom && c.resetZoom()));
    });
  }

  /* ------------------------------------------------------------------
   * Relative times: "5 minutes ago", "in 3 days"
   * ---------------------------------------------------------------- */
  const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
  function relTime(ms) {
    const s = Math.round((ms - Date.now()) / 1000);
    const a = Math.abs(s);
    if (a < 45) return 'just now';
    if (a < 2700) return rtf.format(Math.round(s / 60), 'minute');
    if (a < 79200) return rtf.format(Math.round(s / 3600), 'hour');
    if (a < 2592000) return rtf.format(Math.round(s / 86400), 'day');
    return rtf.format(Math.round(s / 2592000), 'month');
  }
  function updateRelTimes() {
    $$('[data-reltime]').forEach((el) => {
      const t = Date.parse(el.getAttribute('datetime'));
      if (!Number.isNaN(t)) el.textContent = relTime(t);
    });
  }

  /* ------------------------------------------------------------------
   * Sortable tables: <table class="sortable">, optional data-value on cells
   * ---------------------------------------------------------------- */
  function initSorting() {
    $$('table.sortable').forEach((table) => {
      const heads = $$('thead th', table);
      heads.forEach((th, idx) => {
        if (th.hasAttribute('data-nosort')) return;
        th.classList.add('sort');
        th.addEventListener('click', () => {
          const dir = th.dataset.dir === 'asc' ? 'desc' : 'asc';
          heads.forEach((h) => delete h.dataset.dir);
          th.dataset.dir = dir;
          const body = table.tBodies[0];
          const key = (row) => {
            const cell = row.cells[idx];
            const raw = cell ? (cell.dataset.value ?? cell.textContent.trim()) : '';
            const n = Number(raw);
            return raw !== '' && !Number.isNaN(n) ? n : raw.toLowerCase();
          };
          Array.from(body.rows)
            .sort((a, b) => {
              const x = key(a); const y = key(b);
              const r = typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y));
              return dir === 'asc' ? r : -r;
            })
            .forEach((r) => body.appendChild(r));
        });
      });
    });
  }

  /* ------------------------------------------------------------------
   * Instant filter: <input data-filter=".event-card" data-empty="#no-match">
   * ---------------------------------------------------------------- */
  function initFilters() {
    $$('input[data-filter]').forEach((input) => {
      const empty = input.dataset.empty ? document.querySelector(input.dataset.empty) : null;
      const run = () => {
        const q = input.value.trim().toLowerCase();
        let shown = 0;
        $$(input.dataset.filter).forEach((el) => {
          const hit = !q || (el.dataset.search || el.textContent).toLowerCase().includes(q);
          el.hidden = !hit;
          if (hit) shown++;
        });
        if (empty) empty.hidden = shown > 0 || !q;
      };
      input.addEventListener('input', run);
      document.addEventListener('keydown', (e) => {
        const tag = document.activeElement?.tagName;
        if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) { e.preventDefault(); input.focus(); }
        if (e.key === 'Escape' && document.activeElement === input) { input.value = ''; run(); input.blur(); }
      });
    });
  }

  /* ------------------------------------------------------------------
   * Small touches
   * ---------------------------------------------------------------- */
  function countUp() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    $$('[data-count]').forEach((el) => {
      const target = parseFloat(el.dataset.count);
      if (!Number.isFinite(target)) return;
      const dec = parseInt(el.dataset.decimals || '0', 10);
      const suffix = el.dataset.suffix || '';
      const fmt = (v) => v.toLocaleString(undefined, { minimumFractionDigits: dec, maximumFractionDigits: dec }) + suffix;
      const start = performance.now();
      const step = (now) => {
        const p = Math.min(1, (now - start) / 700);
        el.textContent = fmt(target * (1 - Math.pow(1 - p, 3)));
        if (p < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    });
  }

  function initFlash() {
    $$('.flash').forEach((f) => setTimeout(() => f.classList.add('fade'), 5000));
  }

  function initBusyForms() {
    $$('form[data-busy]').forEach((form) => form.addEventListener('submit', () => {
      const btn = form.querySelector('button');
      if (btn) { btn.disabled = true; btn.classList.add('busy'); btn.textContent = form.dataset.busy; }
    }));
  }

  /** <body data-autorefresh="300"> reloads every 5 min while the tab is visible and you aren't typing. */
  function initAutoRefresh() {
    const secs = parseInt(document.body.dataset.autorefresh || '0', 10);
    if (!secs) return;
    setInterval(() => {
      const tag = document.activeElement?.tagName;
      if (!document.hidden && !['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) location.reload();
    }, secs * 1000);
  }

  /* ------------------------------------------------------------------
   * Overview: market watch list → selected market chart → news context.
   * Clicking a market (list or "Latest drops" row) loads it from
   * /api/market.php without a page reload.
   * ---------------------------------------------------------------- */
  function initOverview() {
    const box = document.getElementById('overview');
    if (!box) return;
    const el = (name) => box.querySelector(`[data-el="${name}"]`);
    const tz = box.dataset.tz || undefined;
    const threshold = box.dataset.threshold || '5';
    const fmtWhen = new Intl.DateTimeFormat(undefined, { timeZone: tz, month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    const when = (iso) => (iso ? fmtWhen.format(new Date(iso)) : '—');
    const fmtClock = new Intl.DateTimeFormat(undefined, { timeZone: tz, hour: 'numeric', minute: '2-digit' });
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
    const price = (v) => (v == null ? '—' : `${Number.isInteger(+v) ? +v : (+v).toFixed(1)}¢`);
    const pp = (v) => {
      if (v == null) return '<span class="flat">—</span>';
      const cls = v > 0.05 ? 'up' : v < -0.05 ? 'down' : 'flat';
      const sign = cls === 'up' ? '+' : cls === 'down' ? '−' : '±';
      return `<span class="delta ${cls}">${sign}${Math.abs(v).toFixed(1)} pp</span>`;
    };
    const STAR = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>';

    const state = {
      id: null, data: null, chart: null, req: 0,
      range: box.dataset.range || '24h',
      side: ['yes', 'no', 'both'].includes(localStorage.getItem('kma-side')) ? localStorage.getItem('kma-side') : 'yes',
    };
    const chartArea = box.querySelector('.chart-area');
    const chartState = el('chart-state');

    /* ----- states ----- */
    function setChartState(kind, html) {
      chartState.className = `chart-state${kind ? ` is-${kind}` : ''}`;
      chartState.innerHTML = html || '';
      chartState.hidden = !kind;
    }
    function setLoading(on, marketChanged) {
      chartArea.classList.toggle('is-loading', on);
      box.setAttribute('aria-busy', on ? 'true' : 'false');
      if (on) {
        setChartState('loading', '<span class="spinner" aria-hidden="true"></span><span>Loading price history…</span>');
        if (marketChanged) el('news-body').innerHTML = '<span class="skeleton w-80"></span><span class="skeleton w-60"></span><span class="skeleton w-40"></span>';
      } else if (chartState.classList.contains('is-loading')) {
        setChartState(null);
      }
    }
    function showError(message, retry, marketChanged) {
      chartArea.classList.remove('is-loading');
      setChartState('error', `<b>Couldn't load this market.</b><span>${esc(message)}</span><button type="button" class="ghost" data-retry>Try again</button>`);
      chartState.querySelector('[data-retry]').addEventListener('click', retry);
      if (marketChanged || !state.data) {
        el('news-body').innerHTML = '<div class="news-status tone-error"><b>News context unavailable</b><p>It will appear once the market loads.</p></div>';
      }
    }

    /* ----- chart ----- */
    function ensureChart() {
      if (state.chart) return state.chart;
      if (!window.Chart) return null;
      themeCharts();
      const line = (key, color) => ({
        key, label: key.toUpperCase(), data: [], borderColor: color, borderWidth: 2, tension: 0.2, spanGaps: true,
        backgroundColor: gradient(color, 0.2), fill: 'start',
        pointRadius: (ctx) => (ctx.dataIndex === ctx.dataset.data.length - 1 ? 3.5 : 0),
        pointBackgroundColor: color, pointHoverRadius: 4,
      });
      const marks = (key, label) => ({
        key, type: 'scatter', label, data: [], backgroundColor: C.down, borderColor: '#5c2424', borderWidth: 1,
        pointStyle: 'triangle', rotation: 180, pointRadius: 6, pointHoverRadius: 8,
      });
      state.chart = new Chart(document.getElementById('overview-chart'), {
        type: 'line',
        data: { datasets: [line('yes', C.yes), line('no', C.no), marks('yesDrops', 'YES drop'), marks('noDrops', 'NO drop')] },
        options: {
          responsive: true, maintainAspectRatio: false, animation: { duration: 250 },
          interaction: { mode: 'nearest', axis: 'x', intersect: false },
          scales: {
            x: { type: 'time', grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 32, color: css('--muted') } },
            y: { ticks: { callback: (v) => `${v}¢`, maxTicksLimit: 5, color: css('--muted') }, grid: { color: css('--grid') }, border: { display: false } },
          },
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                title: (items) => (items.length ? when(new Date(items[0].parsed.x).toISOString()) : ''),
                label: (i) => (i.dataset.type === 'scatter' ? ` ${i.raw.label}` : ` ${i.dataset.label}: ${price(i.parsed.y)}`),
              },
            },
          },
        },
      });
      return state.chart;
    }

    function drawChart(d) {
      const series = d.series || [];
      if (!window.Chart) {
        setChartState('error', '<b>Charts couldn\'t load.</b><span>Chart.js is missing. Run <code>npm install</code> or check your internet connection.</span>');
        return;
      }
      if (!series.length) {
        if (state.chart) state.chart.data.datasets.forEach((ds) => { ds.data = []; });
        state.chart?.update('none');
        setChartState('empty', `<b>No price history for ${esc(d.range.label)}.</b><span>The scanner saves one snapshot every 5 minutes.</span>${d.range.key !== 'all' ? '<button type="button" class="ghost" data-show-all>Show all time</button>' : ''}`);
        chartState.querySelector('[data-show-all]')?.addEventListener('click', () => setRange('all'));
        return;
      }
      setChartState(null);
      const chart = ensureChart();
      const show = { yes: state.side !== 'no', no: state.side !== 'yes' };
      const both = state.side === 'both';
      const byKey = Object.fromEntries(chart.data.datasets.map((ds) => [ds.key, ds]));
      byKey.yes.data = series.map((p) => ({ x: p.t, y: p.yes }));
      byKey.no.data = series.map((p) => ({ x: p.t, y: p.no }));
      byKey.yes.label = `YES${d.market.yes_label ? ` · ${d.market.yes_label}` : ''}`;
      byKey.no.label = `NO${d.market.no_label ? ` · ${d.market.no_label}` : ''}`;
      const drops = (side) => (d.moves || []).filter((m) => m.side === side)
        .map((m) => ({ x: m.x, y: m.y, label: `${side.toUpperCase()} dropped ${m.pts.toFixed(1)} pp` }));
      byKey.yesDrops.data = drops('yes');
      byKey.noDrops.data = drops('no');
      byKey.yes.hidden = !show.yes; byKey.yesDrops.hidden = !show.yes;
      byKey.no.hidden = !show.no; byKey.noDrops.hidden = !show.no;
      byKey.yes.fill = both ? false : 'start';
      byKey.no.fill = both ? false : 'start';

      // Fit the y-axis to what is visible, padded and rounded to 5¢ so small moves stay readable.
      const vals = [];
      series.forEach((p) => { if (show.yes && p.yes != null) vals.push(p.yes); if (show.no && p.no != null) vals.push(p.no); });
      const lo = Math.min(...vals); const hi = Math.max(...vals);
      chart.options.scales.y.min = Math.max(0, Math.floor((lo - 4) / 5) * 5);
      chart.options.scales.y.max = Math.min(100, Math.ceil((hi + 4) / 5) * 5);
      chart.update();
    }

    /* ----- news context ----- */
    function renderNews(a, market) {
      const body = el('news-body');
      const drop = a.drop
        ? `<span class="side side-${a.drop.side}">${a.drop.side.toUpperCase()}</span><span class="delta down">−${a.drop.pts.toFixed(1)} pp</span>`
        : '';
      const dropText = a.drop ? `${a.drop.side.toUpperCase()} −${a.drop.pts.toFixed(1)} pp drop on ${when(a.drop.at)}` : '';
      const status = (tone, title, text) => `<div class="news-status${tone ? ` tone-${tone}` : ''}"><b>${title}</b><p>${text}</p></div>`;
      const link = (text, url, cls = '') => (url
        ? `<a class="${cls}" href="${esc(url)}" target="_blank" rel="noopener noreferrer">${esc(text)}</a>`
        : `<span class="${cls}">${esc(text)}</span>`);
      const kwList = (list) => (list || []).map((k) => `<span class="kw">${esc(k)}</span>`).join('');

      // Evidence timeline: best item per source + the drop, in time order.
      const dropDay = a.drop ? new Date(a.drop.at).toDateString() : '';
      const clock = (iso) => {
        if (!iso) return '—';
        const d = new Date(iso);
        return d.toDateString() === dropDay ? fmtClock.format(d) : when(iso);
      };
      const stance = (s) => ({
        supports: '<span class="stance stance-supports" title="Fits the direction of the drop">fits</span>',
        contradicts: '<span class="stance stance-contradicts" title="Points the other way">against</span>',
      }[s] || '');
      const items = a.timeline || [];
      const timeline = items.length > 1 ? `
        <h3 class="tl-head">Evidence timeline</h3>
        <ol class="timeline">${items.map((i) => `
          <li class="tl-item tl-${esc(i.source)}${i.source === 'drop' ? ' is-drop' : ''}">
            <time class="num">${esc(clock(i.at))}</time>
            <div class="tl-body">
              <span class="src-chip src-${esc(i.source)}">${esc(i.label)}</span>${stance(i.stance)}
              ${i.source === 'drop' ? `<b class="delta down">${esc(i.headline)}</b>` : link(i.headline, i.url, 'tl-text')}
              ${i.detail ? `<span class="tl-detail">${esc(i.detail)}</span>` : ''}
            </div>
          </li>`).join('')}
        </ol>` : '';
      const CHECK = {
        found: 'found', article_found: 'article', none: 'nothing', no_explanation_found: 'nothing',
        failed: 'failed', news_search_failed: 'failed', skipped: 'skipped', pending: 'pending', not_configured: 'not set up',
      };
      const checked = (a.checked || []).length ? `<p class="checked">Checked: ${(a.checked).map((c) => `<span class="chk chk-${esc(c.status)}"${c.note ? ` title="${esc(c.note)}"` : ''}>${esc(c.label)} <i>${esc(CHECK[c.status] || c.status)}</i></span>`).join('')}</p>` : '';
      const note = '<p class="news-note">Every item is a possible explanation; causation is unverified.</p>';

      const h = a.headline;
      if (h) {
        const art = h.source === 'news' ? a.article : null;
        body.innerHTML = `
          <span class="news-kicker">Strongest · ${esc(h.label)} · score ${h.score}</span>
          ${link(h.headline, h.url, 'news-title')}
          <span class="news-meta">${art ? `${esc(art.source || 'Unknown source')} · Published ${esc(when(art.published_at))}` : esc(h.detail || '')}</span>
          ${art?.description ? `<p class="news-desc">${esc(art.description)}</p>` : ''}
          <dl class="facts">
            <dt>Drop</dt><dd>${drop}</dd>
            <dt>Detected</dt><dd>${esc(when(a.drop?.at))}</dd>
            ${(h.keywords || []).length ? `<dt>Matched keywords</dt><dd><span class="kw-list">${kwList(h.keywords)}</span></dd>` : ''}
            ${a.drops > 1 ? `<dt>Drops recorded</dt><dd>${a.drops}</dd>` : ''}
          </dl>
          ${timeline}${checked}${note}`;
        return;
      }
      if (a.status === 'no_explanation_found') {
        body.innerHTML = status('', 'No explanation found',
          `Nothing matched the keyword rules for the ${esc(dropText)}.`)
          + timeline + checked
          + '<p class="news-note">This doesn\'t rule out a cause. The keyword rules may not cover it.</p>';
      } else if (a.status === 'news_search_failed') {
        body.innerHTML = status('warn', 'News search failed',
          `The search for the ${esc(dropText)} didn't complete. It is retried automatically on the next scan.`) + timeline + checked;
      } else if (a.status === 'pending') {
        body.innerHTML = status('', 'Analysis pending',
          `A ${esc(dropText)} was detected. The searches run at the end of the scan.`) + timeline + checked;
      } else {
        body.innerHTML = status('', 'No significant drop yet',
          `News is searched only after YES or NO falls by at least ${esc(threshold)} pp between two scans. ${market.status === 'open' ? 'This market hasn\'t had one yet.' : 'This market never had one.'}`);
      }
    }

    /* ----- header, price, footer ----- */
    function render() {
      const d = state.data;
      if (!d) return;
      const m = d.market;
      const main = state.side === 'no' ? 'no' : 'yes';
      const statusText = { open: 'Open market', closed: 'Closed market', settled: 'Settled market', cancelled: 'Cancelled market' }[m.status] || 'Market';

      el('eyebrow').textContent = `UFC · ${statusText}`;
      el('name').textContent = m.name;
      el('event').textContent = [m.event, m.event_start ? `Fight ${when(m.event_start)}` : null].filter(Boolean).join(' · ');

      el('actions').innerHTML = `
        <form method="post" action="${esc(box.dataset.watchAction)}" class="inline">
          <input type="hidden" name="csrf_token" value="${esc(box.dataset.csrf)}">
          <input type="hidden" name="action" value="${d.watched ? 'remove' : 'add'}">
          <input type="hidden" name="market_id" value="${m.id}">
          <input type="hidden" name="return" value="${esc(location.pathname + location.search)}">
          <button class="btn-watch${d.watched ? ' on' : ''}" title="${d.watched ? 'Remove from' : 'Add to'} watchlist">${STAR}<span>${d.watched ? 'Watching' : 'Watch'}</span></button>
        </form>
        <a class="pill" href="${esc(m.url)}">Details</a>`;

      const label = main === 'yes' ? m.yes_label : m.no_label;
      el('price').textContent = price(d.latest ? d.latest[main] : null);
      el('price-side').textContent = `${main.toUpperCase()}${label ? ` · ${label}` : ''}`;
      if (!d.latest) {
        el('change').innerHTML = '<span class="flat">No snapshot saved yet</span>';
      } else if (state.side === 'both') {
        el('change').innerHTML = `YES ${pp(d.change.yes)} · NO ${pp(d.change.no)} since previous scan`;
      } else {
        el('change').innerHTML = d.change[main] == null
          ? '<span class="flat">Only one scan so far, so there is no change yet</span>'
          : `${pp(d.change[main])} since previous scan`;
      }

      const pts = (d.series || []).map((p) => p[main]).filter((v) => v != null);
      el('foot-left').textContent = `Price history · ${d.range.label} · price in cents${state.side === 'both' ? ' · green = YES, sand = NO' : ''}`;
      el('foot-right').innerHTML = pts.length
        ? `${main.toUpperCase()} start ${price(pts[0])} · now ${price(pts[pts.length - 1])} · ${pp(pts[pts.length - 1] - pts[0])}`
        : '';

      drawChart(d);
      renderNews(d.analysis || { status: 'none' }, m);
    }

    /* ----- loading ----- */
    async function load(id, { view = false, marketChanged = true, quiet = false } = {}) {
      const req = ++state.req;
      const timer = quiet ? null : setTimeout(() => setLoading(true, marketChanged), 150);
      try {
        const url = new URL(box.dataset.api, location.href);
        url.searchParams.set('id', id);
        url.searchParams.set('range', state.range);
        if (view) url.searchParams.set('view', '1');
        const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        let body = null;
        try { body = await res.json(); } catch { /* not JSON, handled below */ }
        if (res.status === 401 && body?.login) { location.href = body.login; return; }
        if (!res.ok || !body || body.error) throw new Error(body?.error || `The server responded with ${res.status}.`);
        if (req !== state.req) return;   // a newer click won
        state.data = body;
        state.id = id;
        clearTimeout(timer);
        setLoading(false);
        render();
      } catch (err) {
        clearTimeout(timer);
        if (req === state.req && !quiet) showError(err.message || 'Network error.', () => load(id, { view, marketChanged }), marketChanged);
      }
    }

    function syncUrl() {
      const u = new URL(location.href);
      u.searchParams.set('market', state.id);
      u.searchParams.set('range', state.range);
      history.replaceState(null, '', u);
    }

    function markSelected(id) {
      $$('.mw-item, .drops-table tr[data-market-id]').forEach((n) => {
        const on = +n.dataset.marketId === id;
        n.classList.toggle('is-selected', on);
        if (n.classList.contains('mw-item')) { if (on) n.setAttribute('aria-current', 'true'); else n.removeAttribute('aria-current'); }
      });
    }

    function select(id, { scroll = false } = {}) {
      if (!id) return;
      state.id = id;
      markSelected(id);
      syncUrl();
      load(id, { view: true, marketChanged: true });
      if (scroll && box.getBoundingClientRect().top < 0) box.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function setRange(key) {
      state.range = key;
      $$('[data-range]', el('ranges')).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.range === key)));
      syncUrl();
      load(state.id, { marketChanged: false });
    }

    /* ----- wiring ----- */
    $$('.mw-item', box).forEach((a) => a.addEventListener('click', (e) => {
      if (e.metaKey || e.ctrlKey || e.shiftKey) return;   // let "open in new tab" work
      e.preventDefault();
      select(+a.dataset.marketId);
    }));
    $$('.drops-table tbody tr[data-market-id]').forEach((tr) => tr.addEventListener('click', (e) => {
      if (e.metaKey || e.ctrlKey || e.shiftKey) return;
      e.preventDefault();
      select(+tr.dataset.marketId, { scroll: true });
    }));
    $$('[data-side]', el('sides')).forEach((b) => {
      b.setAttribute('aria-pressed', String(b.dataset.side === state.side));
      b.addEventListener('click', () => {
        state.side = b.dataset.side;
        localStorage.setItem('kma-side', state.side);
        $$('[data-side]', el('sides')).forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
        render();
      });
    });
    $$('[data-range]', el('ranges')).forEach((b) => b.addEventListener('click', () => setRange(b.dataset.range)));

    // Hide event group labels whose markets are all filtered out.
    const filter = box.querySelector('.mw-search input');
    filter?.addEventListener('input', () => {
      $$('.mw-group', box).forEach((g) => {
        let n = g.nextElementSibling; let any = false;
        while (n && !n.classList.contains('mw-group')) { if (n.classList.contains('mw-item') && !n.hidden) any = true; n = n.nextElementSibling; }
        g.hidden = !any;
      });
    });

    // Initial market comes embedded in the page, so there is no extra request on load.
    const initial = readJSON('overview-initial');
    if (initial && initial.market) {
      state.data = initial;
      state.id = initial.market.id;
      render();
    } else {
      setChartState('empty', '<b>No market selected.</b><span>Pick a market from the list.</span>');
      el('news-body').innerHTML = '<p class="news-note">News context appears here for the selected market.</p>';
    }

    // Quietly refresh the selected market every 5 minutes (matches the scan interval).
    setInterval(() => { if (!document.hidden && state.id) load(state.id, { marketChanged: false, quiet: true }); }, 300000);
  }

  initCharts();
  updateRelTimes();
  setInterval(updateRelTimes, 30000);
  initSorting();
  initFilters();
  initOverview();
  countUp();
  initFlash();
  initBusyForms();
  initAutoRefresh();
})();
