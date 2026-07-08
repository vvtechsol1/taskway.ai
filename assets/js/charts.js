/* Taskway — tiny dependency-free SVG charts. Theme-aware, soft styling. */
(function (w) {
  'use strict';
  const NS = 'http://www.w3.org/2000/svg';
  const cssVar = (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim();

  function el(name, attrs, children) {
    const e = document.createElementNS(NS, name);
    for (const k in (attrs || {})) e.setAttribute(k, attrs[k]);
    (children || []).forEach((c) => e.appendChild(c));
    return e;
  }
  function fmtMin(m) {
    if (m >= 60) { const h = m / 60; return (Math.round(h * 10) / 10) + 'h'; }
    return m + 'm';
  }

  /* Vertical bar chart. data: [{label, value}] (value in minutes by default) */
  function bars(node, data, opts) {
    opts = opts || {};
    node = typeof node === 'string' ? document.getElementById(node) : node;
    if (!node) return;
    node.innerHTML = '';
    const W = 100 * Math.max(data.length, 1), H = opts.height || 150;
    const pad = { t: 14, b: 24, l: 4, r: 4 };
    const max = Math.max(1, ...data.map((d) => d.value), opts.goal || 0);
    const bw = (W - pad.l - pad.r) / data.length;
    const barW = Math.min(46, bw * 0.52);
    const track = cssVar('--surface-3') || '#eee';
    const txt = cssVar('--text-3') || '#999';
    const svg = el('svg', { viewBox: `0 0 ${W} ${H}`, preserveAspectRatio: 'none', role: 'img' });

    // gradient def
    const grad = el('linearGradient', { id: 'twbg' + (opts.gid || 0), x1: '0', y1: '0', x2: '0', y2: '1' });
    grad.appendChild(el('stop', { offset: '0%', 'stop-color': opts.color || cssVar('--violet') || '#6C5CE7' }));
    grad.appendChild(el('stop', { offset: '100%', 'stop-color': opts.color2 || cssVar('--violet-300') || '#A29BFE' }));
    svg.appendChild(el('defs', {}, [grad]));

    // goal line
    if (opts.goal) {
      const gy = pad.t + (1 - opts.goal / max) * (H - pad.t - pad.b);
      svg.appendChild(el('line', { x1: pad.l, y1: gy, x2: W - pad.r, y2: gy, stroke: cssVar('--mint') || '#12B886', 'stroke-width': 1.4, 'stroke-dasharray': '4 4', opacity: .7 }));
    }

    data.forEach((d, i) => {
      const cx = pad.l + bw * i + bw / 2;
      const h = (d.value / max) * (H - pad.t - pad.b);
      const y = H - pad.b - h;
      const isToday = d.today;
      const r = el('rect', {
        x: cx - barW / 2, y: y, width: barW, height: Math.max(h, d.value > 0 ? 3 : 2), rx: 6,
        fill: d.value > 0 ? `url(#twbg${opts.gid || 0})` : track, opacity: isToday ? 1 : (d.value > 0 ? .92 : .6)
      });
      r.appendChild(el('title', {}, [document.createTextNode((d.label || '') + ': ' + fmtMin(d.value))]));
      r.style.transition = 'height .5s cubic-bezier(.4,0,.2,1)';
      svg.appendChild(r);
      if (opts.labels !== false) {
        const t = el('text', { x: cx, y: H - 8, 'text-anchor': 'middle', fill: isToday ? (cssVar('--primary') || '#6C5CE7') : txt, 'font-size': 10, 'font-weight': isToday ? 700 : 500 });
        t.textContent = d.label;
        svg.appendChild(t);
      }
    });
    node.appendChild(svg);
  }

  /* Donut. segments: [{label, value, color}] */
  function donut(node, segments, opts) {
    opts = opts || {};
    node = typeof node === 'string' ? document.getElementById(node) : node;
    if (!node) return;
    node.innerHTML = '';
    const size = opts.size || 150, sw = opts.stroke || 20, r = (size - sw) / 2, c = 2 * Math.PI * r, cx = size / 2;
    const total = segments.reduce((s, d) => s + d.value, 0);
    const svg = el('svg', { viewBox: `0 0 ${size} ${size}` });
    svg.appendChild(el('circle', { cx, cy: cx, r, fill: 'none', stroke: cssVar('--surface-3') || '#eee', 'stroke-width': sw }));
    let off = 0;
    if (total > 0) {
      segments.forEach((d) => {
        if (d.value <= 0) return;
        const len = (d.value / total) * c;
        const circ = el('circle', {
          cx, cy: cx, r, fill: 'none', stroke: d.color, 'stroke-width': sw, 'stroke-linecap': 'round',
          'stroke-dasharray': `${len} ${c - len}`, 'stroke-dashoffset': -off,
          transform: `rotate(-90 ${cx} ${cx})`
        });
        circ.appendChild(el('title', {}, [document.createTextNode(d.label + ': ' + d.value)]));
        circ.style.transition = 'stroke-dasharray .6s cubic-bezier(.4,0,.2,1)';
        svg.appendChild(circ);
        off += len;
      });
    }
    const big = el('text', { x: cx, y: cx - 2, 'text-anchor': 'middle', fill: cssVar('--text') || '#222', 'font-size': 26, 'font-weight': 800 });
    big.textContent = opts.centerValue != null ? opts.centerValue : total;
    svg.appendChild(big);
    if (opts.centerLabel) {
      const sub = el('text', { x: cx, y: cx + 16, 'text-anchor': 'middle', fill: cssVar('--text-3') || '#999', 'font-size': 11, 'font-weight': 600 });
      sub.textContent = opts.centerLabel;
      svg.appendChild(sub);
    }
    node.appendChild(svg);
  }

  /* Area/line chart for trends. data: [{label, value}] */
  function area(node, data, opts) {
    opts = opts || {};
    node = typeof node === 'string' ? document.getElementById(node) : node;
    if (!node) return;
    node.innerHTML = '';
    const W = 100 * Math.max(data.length, 1), H = opts.height || 150, pad = { t: 14, b: 24, l: 6, r: 6 };
    const max = Math.max(1, ...data.map((d) => d.value));
    const stepX = (W - pad.l - pad.r) / Math.max(1, data.length - 1);
    const pts = data.map((d, i) => [pad.l + stepX * i, pad.t + (1 - d.value / max) * (H - pad.t - pad.b)]);
    const svg = el('svg', { viewBox: `0 0 ${W} ${H}`, preserveAspectRatio: 'none' });
    const col = opts.color || cssVar('--violet') || '#6C5CE7';
    const grad = el('linearGradient', { id: 'twar', x1: '0', y1: '0', x2: '0', y2: '1' });
    grad.appendChild(el('stop', { offset: '0%', 'stop-color': col, 'stop-opacity': .28 }));
    grad.appendChild(el('stop', { offset: '100%', 'stop-color': col, 'stop-opacity': 0 }));
    svg.appendChild(el('defs', {}, [grad]));
    const line = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
    svg.appendChild(el('path', { d: `${line} L ${pts[pts.length - 1][0]} ${H - pad.b} L ${pts[0][0]} ${H - pad.b} Z`, fill: 'url(#twar)' }));
    svg.appendChild(el('path', { d: line, fill: 'none', stroke: col, 'stroke-width': 2.4, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }));
    pts.forEach((p, i) => {
      const dot = el('circle', { cx: p[0], cy: p[1], r: 3, fill: cssVar('--surface') || '#fff', stroke: col, 'stroke-width': 2 });
      dot.appendChild(el('title', {}, [document.createTextNode(data[i].label + ': ' + fmtMin(data[i].value))]));
      svg.appendChild(dot);
    });
    node.appendChild(svg);
  }

  w.TWChart = { bars, donut, area, fmtMin };
})(window);
