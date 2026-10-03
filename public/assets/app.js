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

  const C = { yes: '#22c55e', no: '#f43f5e', accent: '#818cf8', amber: '#f59e0b', sky: '#38bdf8', violet: '#a78bfa', slate: '#64748b' };
  const STATUS = {
    article_found: { label: 'Article found', color: '#22c55e' },
    no_explanation_found: { label: 'No explanation', color: '#f59e0b' },
    news_search_failed: { label: 'Search failed', color: '#f43f5e' },
    pending: { label: 'Pending', color: '#38bdf8' },
  };
  const PALETTE = ['#818cf8', '#22c55e', '#f59e0b', '#38bdf8', '#f43f5e', '#a78bfa', '#14b8a6', '#ec4899'];

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
   * Theme (dark / light) — also re-colours existing charts
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

  $$('[data-theme-toggle]').forEach((btn) => btn.addEventListener('click', () => {
    const next = root.dataset.theme === 'light' ? 'dark' : 'light';
    root.dataset.theme = next;
    localStorage.setItem('kma-theme', next);
    themeCharts();
  }));

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
            { type: 'scatter', label: 'Movement', data: moves, backgroundColor: C.amber, borderColor: '#78350f', borderWidth: 1, pointStyle: 'triangle', rotation: 180, pointRadius: 8, pointHoverRadius: 10, order: 0 },
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
            { label: 'YES ask', data: s.map((p) => ({ x: p.t, y: p.ask })), borderColor: rgba(C.no, 0.85), borderWidth: 1.5, backgroundColor: rgba(C.accent, 0.16), fill: '+1', stepped: true, spanGaps: true },
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
        data: { labels: d.labels, datasets: [{ label: 'Movements', data: d.values, backgroundColor: d.values.map((_, i) => rgba(C.amber, 0.45 + i * 0.1)), borderRadius: 6 }] },
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
      const sc = { completed: C.yes, partial: C.amber, failed: C.no, running: C.sky };
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

  initCharts();
  updateRelTimes();
  setInterval(updateRelTimes, 30000);
  initSorting();
  initFilters();
  countUp();
  initFlash();
  initBusyForms();
  initAutoRefresh();
})();
