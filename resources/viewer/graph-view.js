/* Project graph viewer.
 *
 * One client, two homes: the Graph view inside the Semitexa Observatory, which
 * reads the graph over /__observatory/graph, and the self-contained HTML file
 * `ai:review-graph:show --format=html` writes, which carries the graph inside
 * it. The difference is a DATA SOURCE — an object with summary(), node(),
 * subgraph(), path(), search() and findings(), each returning a Promise — so
 * the views are written once and cannot drift apart.
 *
 * Plain hand-written JS like the rest of the dev tools: no bundler, no
 * framework. No inline style either — the Observatory runs under a strict
 * CSP — so indentation and colour go through classes and CSS custom
 * properties set from script, which a policy does not block.
 *
 * Views:
 *   - a lazy TREE per entry point (route, command, handler); a node already
 *     shown elsewhere is marked shared (×N) and still expands in place;
 *   - a DAG of the focused node's neighbourhood where every class appears
 *     once and fan-in is drawn as weight;
 *   - a detail panel with every edge, both directions;
 *   - FINDINGS, each focusing its node.
 */
(() => {
'use strict';

const KIND_ORDER = ['serves_route', 'handles', 'produces', 'accepts', 'returns', 'injects_readonly', 'injects_mutable',
  'satisfies_contract', 'implements', 'extends', 'uses_trait', 'instantiates', 'listens_to', 'dispatches', 'references'];
const GROUPS = [
  {key: 'route', label: 'Routes'},
  {key: 'command', label: 'Commands'},
  {key: 'handler', label: 'Handlers'},
];

/* ------------------------------------------------------------ helpers */
const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; };
const kindRank = k => { const i = KIND_ORDER.indexOf(k); return i < 0 ? KIND_ORDER.length : i; };
function hash(s) { let h = 2166136261; for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); } return h >>> 0; }
/** A module always gets the same colour, in every view and every session. */
const moduleClass = m => 'm' + (hash(m || '') % 10);
function dot(node) { const d = el('span', 'gv-dot ' + (node.placeholder ? 'gv-ph' : moduleClass(node.module))); d.title = node.module || 'no module'; return d; }
function when(ts) {
  if (!ts) return 'never';
  const s = Math.max(0, Math.round(Date.now() / 1000 - ts));
  return s < 90 ? s + ' s ago' : s < 5400 ? Math.round(s / 60) + ' min ago' : s < 129600 ? Math.round(s / 3600) + ' h ago' : Math.round(s / 86400) + ' d ago';
}
const isClassId = id => id.startsWith('class:');

/* ------------------------------------------------------------ data sources */
/** The Observatory: every slice is a GET against the graph endpoint. */
function fetchSource(endpoint) {
  const get = params => fetch(endpoint + '?' + new URLSearchParams(params), {headers: {Accept: 'application/json'}, credentials: 'same-origin'})
    .then(r => r.json().catch(() => ({error: 'http-' + r.status})).then(b => (r.ok ? b : Promise.reject(b))));
  return {
    live: true,
    summary: () => get({view: 'summary'}),
    node: id => get({view: 'node', id}),
    subgraph: (id, depth) => get({view: 'subgraph', id, depth}),
    path: id => get({view: 'path', id}).then(b => b.path),
    search: q => get({view: 'search', q}).then(b => b.hits),
    findings: () => get({view: 'findings'}),
    traces: id => get({view: 'traces', id}).then(b => b.traces),
  };
}

/* ------------------------------------------------------------ the view */
function mount(root, source, options = {}) {
  const S = {
    summary: null, selected: null, focus: null, depth: 2,
    children: new Map(),   // id -> Promise<list<{node, kind}>>
    nodes: new Map(),      // id -> node json, everything seen so far
    rows: new Map(),       // id -> Set<row element>, for shared counts and reveal
    findings: null,
  };
  const hooks = {onSelect: options.onSelect || null};

  root.classList.add('gv');
  root.replaceChildren();

  /* ---- skeleton ---- */
  const bar = el('div', 'gv-pane gv-bar');
  const stat = el('span', 'gv-stat', 'loading graph…');
  const stale = el('span', 'gv-badge gv-warn', 'stale'); stale.hidden = true;
  stale.title = 'Code changed since the graph was built — rebuild with bin/semitexa ai:review-graph:generate';
  const search = el('div', 'gv-search');
  const input = el('input'); input.type = 'search'; input.placeholder = 'Find a class, route or command…  ( / )'; input.setAttribute('aria-label', 'Search the graph'); input.autocomplete = 'off';
  const hits = el('div', 'gv-hits'); hits.hidden = true; hits.setAttribute('role', 'listbox');
  search.append(input, hits);
  bar.append(stat, stale, search);

  const side = el('section', 'gv-pane gv-side');
  const tabs = el('nav', 'gv-tabs');
  const tabTree = el('button', 'on', 'Entry points'); tabTree.type = 'button';
  const tabFind = el('button', '', 'Findings'); tabFind.type = 'button';
  const findBadge = el('b'); findBadge.hidden = true; tabFind.append(findBadge);
  tabs.append(tabTree, tabFind);
  const treeScroll = el('div', 'gv-scroll');
  const tree = el('div', 'gv-tree'); tree.setAttribute('role', 'tree'); tree.tabIndex = 0;
  treeScroll.append(tree);
  const findScroll = el('div', 'gv-scroll'); findScroll.hidden = true;
  side.append(tabs, treeScroll, findScroll);

  const main = el('section', 'gv-pane gv-main');
  const mainHead = el('h2'); const mainTitle = el('span', '', 'Focus');
  const ctl = el('span', 'gv-ctl');
  mainHead.append(mainTitle, ctl);
  const wrap = el('div', 'gv-canvas-wrap');
  const mainEmpty = el('div', 'gv-empty', 'Pick a node in the tree or search for one — its neighbourhood is drawn here.');
  wrap.append(mainEmpty);
  main.append(mainHead, wrap);

  const detail = el('section', 'gv-pane gv-detail');
  const detailBody = el('div', 'gv-scroll');
  detailBody.append(el('div', 'gv-empty', 'Nothing selected.'));
  detail.append(el('h2', '', 'Node'), detailBody);

  root.append(bar, side, main, detail);

  /* ---- summary ---- */
  function fail(where, err) {
    where.replaceChildren();
    const box = el('div', 'gv-err');
    if (err && err.error === 'no-graph') {
      box.append('No project graph yet. Build one with ', el('code', '', 'bin/semitexa ai:review-graph:generate'), ', then reopen this view.');
    } else {
      box.textContent = 'Could not load: ' + ((err && (err.message || err.error)) || err);
    }
    where.append(box);
  }

  source.summary().then(sum => {
    S.summary = sum;
    stat.replaceChildren();
    stat.append(el('b', '', String(sum.counts.nodes)), ' nodes · ', el('b', '', String(sum.counts.edges)), ' edges · ',
      el('b', '', String(sum.modules.length)), ' modules · built ' + when(sum.builtAt));
    stale.hidden = !sum.stale;
    for (const g of GROUPS) for (const n of sum.entries[g.key] || []) S.nodes.set(n.id, n);
    renderGroups();
    if (options.initial) reveal(options.initial);
  }).catch(err => { stat.textContent = 'no graph'; fail(tree, err); });

  /* ---- tree ---- */
  function track(id, row) { if (!S.rows.has(id)) S.rows.set(id, new Set()); S.rows.get(id).add(row); refreshShared(id); }
  function untrack(id, row) { const set = S.rows.get(id); if (set) { set.delete(row); refreshShared(id); } }
  function refreshShared(id) {
    const set = S.rows.get(id); if (!set) return;
    for (const row of set) {
      const badge = row.querySelector('.gv-shared');
      badge.hidden = set.size < 2;
      badge.textContent = '×' + set.size;
      badge.title = 'shown ' + set.size + ' times in the tree — a dependency shared between entry points';
    }
  }

  function renderGroups() {
    tree.replaceChildren();
    for (const g of GROUPS) {
      const list = S.summary.entries[g.key] || [];
      const row = el('button', 'gv-row group');
      row.type = 'button'; row.setAttribute('role', 'treeitem'); row.setAttribute('aria-expanded', 'false');
      row.dataset.group = g.key;
      row.append(el('span', 'gv-caret', '▸'), el('span', '', g.label), el('span', 'gv-n', String(list.length)));
      const box = el('div'); box.setAttribute('role', 'group'); box.hidden = true;
      row.addEventListener('click', () => toggleGroup(row, box, list));
      tree.append(row, box);
    }
  }

  function toggleGroup(row, box, list, open) {
    const want = open === undefined ? row.getAttribute('aria-expanded') !== 'true' : open;
    row.setAttribute('aria-expanded', String(want));
    box.hidden = !want;
    if (want && !box.childElementCount) {
      for (const n of list) box.append(...nodeRow(n, null, 0, []));
    }
  }

  /** A tree row and its (empty, lazily filled) child container. */
  function nodeRow(node, kind, depth, ancestors) {
    const row = el('button', 'gv-row');
    row.type = 'button'; row.setAttribute('role', 'treeitem'); row.dataset.id = node.id;
    row.style.setProperty('--d', String(depth));
    const cycle = ancestors.includes(node.id);
    const caret = el('span', 'gv-caret', cycle ? '' : '▸');
    row.append(caret, dot(node), el('span', 'gv-name', node.name));
    if (kind) row.append(el('span', 'gv-kind', kind));
    row.append(el('span', 'gv-type', node.type));
    if (node.gaps > 0) { const g = el('span', 'gv-cycle', '⚠'); g.title = node.gaps + ' coverage gap(s) in this file: some of its references were not resolved'; row.append(g); }
    const shared = el('span', 'gv-shared'); shared.hidden = true; row.append(shared);
    if (cycle) { const c = el('span', 'gv-cycle', '↺'); c.title = 'cycle: this node is one of its own ancestors here'; row.append(c); }
    row.title = node.fqcn;
    const box = el('div'); box.setAttribute('role', 'group'); box.hidden = true;
    if (!cycle) row.setAttribute('aria-expanded', 'false');
    row._gv = {node, depth, ancestors: [...ancestors, node.id], box, cycle};
    // First click selects; a click on the caret, or on a row already selected, opens it.
    row.addEventListener('click', e => { const was = row.classList.contains('sel'); select(node.id, row); if (!cycle && (e.target === caret || was)) toggleRow(row); });
    row.addEventListener('dblclick', () => { if (!cycle) toggleRow(row); });
    track(node.id, row);
    return [row, box];
  }

  function children(id) {
    if (!S.children.has(id)) {
      S.children.set(id, source.subgraph(id, 1).then(g => {
        const byId = new Map(g.nodes.map(n => [n.id, n]));
        for (const n of g.nodes) S.nodes.set(n.id, n);
        return g.edges.filter(e => e.s === id && byId.has(e.t))
          .map(e => ({node: byId.get(e.t), kind: e.k}))
          .sort((a, b) => kindRank(a.kind) - kindRank(b.kind) || a.node.name.localeCompare(b.node.name));
      }).catch(err => { S.children.delete(id); throw err; }));
    }
    return S.children.get(id);
  }

  function toggleRow(row, open) {
    const st = row._gv; if (st.cycle) return Promise.resolve();
    const want = open === undefined ? row.getAttribute('aria-expanded') !== 'true' : open;
    row.setAttribute('aria-expanded', String(want));
    st.box.hidden = !want;
    if (!want) { collapse(st.box); return Promise.resolve(); }
    if (st.box.childElementCount) return Promise.resolve();
    const wait = el('div', 'gv-loading', 'loading…'); wait.style.setProperty('--d', String(st.depth + 1));
    st.box.append(wait);
    return children(st.node.id).then(list => {
      st.box.replaceChildren();
      if (!list.length) { const none = el('div', 'gv-loading', 'depends on nothing in the graph'); none.style.setProperty('--d', String(st.depth + 1)); st.box.append(none); }
      for (const c of list) st.box.append(...nodeRow(c.node, c.kind, st.depth + 1, st.ancestors));
    }).catch(err => { st.box.replaceChildren(el('div', 'gv-err', 'Could not expand: ' + (err.message || err.error || err))); });
  }

  /** Closing a branch forgets its rows, so shared counts describe what is visible. */
  function collapse(box) {
    box.querySelectorAll('.gv-row[data-id]').forEach(r => untrack(r.dataset.id, r));
    box.replaceChildren();
  }

  /** Expand from an entry point down to $id and select it there. */
  function reveal(id) {
    return source.path(id).then(path => {
      if (!path || !path.length) { select(id); return; }
      showTab('tree');
      const root = S.nodes.get(path[0]);
      const groupKey = root ? root.type : null;
      const groupRow = tree.querySelector('.gv-row.group[data-group="' + groupKey + '"]');
      if (!groupRow) { select(id); return; }
      toggleGroup(groupRow, groupRow.nextElementSibling, S.summary.entries[groupKey] || [], true);
      let scope = groupRow.nextElementSibling;
      let step = Promise.resolve();
      path.forEach((pid, i) => {
        step = step.then(() => {
          const row = [...scope.children].find(r => r.dataset && r.dataset.id === pid);
          if (!row) return Promise.reject(new Error('lost at ' + pid));
          if (i === path.length - 1) { select(pid, row); row.scrollIntoView({block: 'center'}); row.classList.remove('flash'); void row.offsetWidth; row.classList.add('flash'); row.focus({preventScroll: true}); return null; }
          return toggleRow(row, true).then(() => { scope = row._gv.box; });
        });
      });
      return step.catch(() => select(id));
    }).catch(() => select(id));
  }

  /* ---- selection + detail ---- */
  /** Select a node: detail panel, tree highlight and — unless told to keep it — the DAG focus. */
  function select(id, row, opts = {}) {
    S.selected = id;
    tree.querySelectorAll('.gv-row.sel').forEach(r => r.classList.remove('sel'));
    (row ? [row] : [...(S.rows.get(id) || [])]).forEach(r => r.classList.add('sel'));
    loadDetail(id);
    if (!opts.keepFocus) focusGraph(id);
    else if (api.markSelected) api.markSelected(id);
    if (hooks.onSelect) hooks.onSelect(id);
  }

  function loadDetail(id) {
    detailBody.replaceChildren(el('div', 'gv-empty', 'loading…'));
    source.node(id).then(d => {
      if (S.selected !== id) return;
      S.nodes.set(d.node.id, d.node);
      renderDetail(d);
    }).catch(err => fail(detailBody, err));
  }

  function renderDetail(d) {
    const n = d.node;
    detailBody.replaceChildren();
    const head = el('div', 'gv-head');
    const h = el('h3'); h.append(dot(n), el('span', '', n.name), el('span', 'gv-type', n.type));
    head.append(h);
    if (n.fqcn && n.fqcn !== n.name) head.append(el('div', 'gv-fq', n.fqcn));
    head.append(el('div', 'gv-meta', (n.module || 'no module') + (n.file ? ' · ' + n.file + ':' + n.line : '') + ' · fan-in ' + d.fanIn));
    const acts = el('div', 'gv-acts');
    if (source.live && isClassId(n.id)) {
      const a = el('a', '', 'source & wiring ↗'); a.href = '/__trace/node?class=' + encodeURIComponent(n.fqcn); a.target = '_blank'; a.rel = 'noopener';
      acts.append(a);
    }
    const rev = el('button', '', 'show in tree'); rev.type = 'button'; rev.className = 'gv-btn'; rev.addEventListener('click', () => reveal(n.id));
    acts.append(rev);
    head.append(acts);
    if (n.placeholder) head.append(el('div', 'gv-meta', 'Placeholder: referenced, but declared outside the scanned tree.'));
    detailBody.append(head);
    for (const g of d.gaps || []) {
      const box = el('div', 'gv-gap');
      box.append(el('b', '', g.kind.replace(/_/g, ' ')), ' · line ' + g.line + ' — ' + g.detail);
      box.title = 'The graph may be missing an edge here: what this line refers to was not resolved statically.';
      detailBody.append(box);
    }
    if (options.decorateDetail) options.decorateDetail(detailBody, d);
    if (source.traces && isClassId(n.id)) detailBody.append(traceSection(n));
    detailBody.append(edgeSection('Reaches', d.out), edgeSection('Reached by', d.in));
    if (d.truncated) detailBody.append(el('div', 'gv-empty', 'Only the first 400 edges per side are listed.'));
  }

  /** Recent recorded traces that ran this class — the way back from structure to behaviour. */
  function traceSection(n) {
    const sec = el('div', 'gv-sec');
    const h = el('h4', '', 'Recent traces'); const count = el('span', '', '…'); h.append(count); sec.append(h);
    source.traces(n.id).then(list => {
      count.textContent = String(list.length);
      if (!list.length) {
        sec.append(el('div', 'gv-empty', 'No persisted trace names this class. That is not "never ran": only recorded requests (?__trace=1) write a trace, stage mode does not.'));
        return;
      }
      for (const t of list) {
        const a = el('a', 'gv-edge'); a.href = '/__trace?file=' + encodeURIComponent(t.trace); a.target = '_blank'; a.rel = 'noopener';
        a.title = t.ts + ' · ' + t.trace;
        a.append(el('span', 'gv-dot'), el('span', 'gv-name', t.name || t.trace), el('span', 'gv-kind', t.durationMs === null ? t.kind : Math.round(t.durationMs) + ' ms'));
        sec.append(a);
      }
    }).catch(() => { count.textContent = '–'; });
    return sec;
  }

  function edgeSection(title, list) {
    const sec = el('div', 'gv-sec');
    const h = el('h4', '', title); h.append(el('span', '', String(list.length)));
    sec.append(h);
    if (!list.length) { sec.append(el('div', 'gv-empty', 'none')); return sec; }
    const sorted = [...list].sort((a, b) => kindRank(a.kind) - kindRank(b.kind) || a.node.name.localeCompare(b.node.name));
    for (const e of sorted) {
      const b = el('button', 'gv-edge'); b.type = 'button'; b.title = e.node.fqcn + ' (' + e.class + ')';
      b.append(dot(e.node), el('span', 'gv-name', e.node.name), el('span', 'gv-kind', e.kind));
      b.addEventListener('click', () => select(e.node.id));
      sec.append(b);
    }
    return sec;
  }

  /* ---- search ---- */
  let searchTimer = 0, searchSeq = 0, hitIndex = -1;
  input.addEventListener('input', () => {
    clearTimeout(searchTimer);
    const q = input.value.trim();
    if (q.length < 2) { hits.hidden = true; return; }
    searchTimer = setTimeout(() => {
      const seq = ++searchSeq;
      source.search(q).then(list => { if (seq === searchSeq) renderHits(list); }).catch(err => { hits.hidden = false; fail(hits, err); });
    }, 150);
  });
  function renderHits(list) {
    hits.replaceChildren(); hitIndex = -1;
    if (!list.length) hits.append(el('div', 'gv-empty', 'No match.'));
    for (const n of list) {
      const b = el('button', 'gv-hit'); b.type = 'button'; b.dataset.id = n.id; b.setAttribute('role', 'option');
      b.append(dot(n), el('span', 'gv-name', n.name), el('span', 'gv-type', n.type), el('span', 'gv-fq', n.fqcn));
      b.addEventListener('click', () => { hits.hidden = true; reveal(n.id); });
      hits.append(b);
    }
    hits.hidden = false;
  }
  input.addEventListener('keydown', e => {
    const items = [...hits.querySelectorAll('.gv-hit')];
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault(); if (!items.length) return;
      hitIndex = (hitIndex + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
      items.forEach((b, i) => b.classList.toggle('on', i === hitIndex)); items[hitIndex].scrollIntoView({block: 'nearest'});
    } else if (e.key === 'Enter') {
      const pick = items[Math.max(0, hitIndex)]; if (pick) pick.click();
    } else if (e.key === 'Escape') { hits.hidden = true; }
  });
  document.addEventListener('click', e => { if (!search.contains(e.target)) hits.hidden = true; });

  /* ---- tree keyboard ---- */
  tree.addEventListener('keydown', e => {
    const rows = [...tree.querySelectorAll('.gv-row')].filter(r => r.offsetParent !== null);
    const at = rows.indexOf(document.activeElement);
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault(); const next = rows[Math.min(rows.length - 1, Math.max(0, at + (e.key === 'ArrowDown' ? 1 : -1)))]; if (next) next.focus();
    } else if ((e.key === 'ArrowRight' || e.key === 'ArrowLeft') && at >= 0) {
      e.preventDefault(); const row = rows[at]; const open = e.key === 'ArrowRight';
      if (row.classList.contains('group')) row.click(); else if (row._gv && (row.getAttribute('aria-expanded') === 'true') !== open) toggleRow(row, open);
    }
  });

  /* ---- tabs ---- */
  function showTab(which) {
    tabTree.classList.toggle('on', which === 'tree'); tabFind.classList.toggle('on', which === 'find');
    treeScroll.hidden = which !== 'tree'; findScroll.hidden = which !== 'find';
    if (which === 'find' && api.loadFindings) api.loadFindings();
  }
  tabTree.addEventListener('click', () => showTab('tree'));
  tabFind.addEventListener('click', () => showTab('find'));

  /* ---- focus graph (DAG), filled in by the DAG module below ---- */
  function focusGraph(id) { S.focus = id; if (api.drawFocus) api.drawFocus(id); }

  const api = {
    S, source, root, select, reveal, showTab,
    parts: {bar, ctl, wrap, mainEmpty, mainTitle, findScroll, findBadge, detailBody},
    helpers: {el, dot, moduleClass, fail, kindRank},
    focusSearch: () => input.focus(),
  };
  (window.SemitexaGraphView.extensions || []).forEach(ext => ext(api));
  return api;
}

window.SemitexaGraphView = Object.assign(window.SemitexaGraphView || {}, {mount, fetchSource, extensions: (window.SemitexaGraphView && window.SemitexaGraphView.extensions) || []});
})();

/* ======================================================================
 * DAG: the focused node's neighbourhood, each class drawn ONCE.
 *
 * A tree repeats a shared dependency under every parent; this view draws it
 * once with an edge from each dependent, so fan-in becomes a shape you can
 * see — and a node's height grows with its fan-in across the WHOLE graph.
 *
 * Layout is hand-rolled rather than vendored: the only graph library in the
 * ecosystem (force-graph, in semitexa-os) is force-directed, which gives no
 * layers. Here: back edges found by DFS are set aside so the rest is acyclic,
 * longest-path layering puts every node right of everything it depends on,
 * and a few barycenter sweeps reduce crossings. Left to right: dependents on
 * the left, dependencies on the right, the focus one column in from its
 * direct dependents.
 *
 * Drawing is on demand (a dirty flag, not a loop) and culled to the
 * viewport — the import-atlas idea — so a 2k-node focus pans at frame rate.
 * ====================================================================== */
(() => {
'use strict';
const ext = api => {
  const {S, source, parts, helpers} = api;
  const {el, moduleClass} = helpers;
  const NODE_H = 22, ROW_GAP = 8, COL_GAP = 90, PAD_X = 12;
  const D = {
    canvas: null, ctx: null, w: 0, h: 0, dpr: 1, view: {x: 0, y: 0, k: 1},
    nodes: [], byId: new Map(), edges: [], hover: null, focus: null, seq: 0,
    dirty: false, theme: null, stats: {frameMs: 0, drawnNodes: 0, drawnEdges: 0, nodes: 0, edges: 0},
  };

  /* ---- controls ---- */
  const seg = el('span', 'gv-seg'); seg.title = 'how many steps of dependencies to draw';
  [1, 2, 3, 4].forEach(n => {
    const b = el('button', n === S.depth ? 'on' : '', 'depth ' + n); b.type = 'button';
    b.addEventListener('click', () => { S.depth = n; seg.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b)); if (D.focus) draw(D.focus); });
    seg.append(b);
  });
  const fit = el('button', 'gv-btn', 'fit'); fit.type = 'button'; fit.title = 'fit (0)';
  fit.addEventListener('click', () => fitView());
  parts.ctl.append(seg, fit);

  const canvas = el('canvas'); canvas.setAttribute('aria-label', 'dependency graph of the focused node'); canvas.tabIndex = 0;
  const tip = el('div', 'gv-tip'); tip.hidden = true;
  const note = el('div', 'gv-note');
  parts.wrap.append(canvas, tip, note);
  D.canvas = canvas; D.ctx = canvas.getContext('2d');

  /* ---- theme ---- */
  function readTheme() {
    const cs = getComputedStyle(api.root);
    const v = n => cs.getPropertyValue(n).trim();
    D.theme = {
      text: v('--gv-text'), dim: v('--gv-dim'), faint: v('--gv-faint'), line: v('--gv-line-2'), panel: v('--gv-panel-2'),
      accent: v('--gv-accent'), warn: v('--gv-warn'), mono: v('--gv-mono') || 'monospace',
      m: Array.from({length: 10}, (_, i) => v('--gv-m' + i)),
    };
    D.dirty = true; request();
  }
  matchMedia('(prefers-color-scheme: dark)').addEventListener('change', readTheme);

  /* ---- size ---- */
  function resize() {
    const r = parts.wrap.getBoundingClientRect();
    D.dpr = window.devicePixelRatio || 1; D.w = Math.max(1, r.width); D.h = Math.max(1, r.height);
    canvas.width = Math.round(D.w * D.dpr); canvas.height = Math.round(D.h * D.dpr);
    D.dirty = true; request();
  }
  new ResizeObserver(resize).observe(parts.wrap);

  /* ---- data ---- */
  function draw(id) {
    const seq = ++D.seq;
    D.focus = id;
    parts.mainEmpty.hidden = true;
    note.textContent = 'loading…';
    Promise.all([source.subgraph(id, S.depth), source.node(id)]).then(([g, d]) => {
      if (seq !== D.seq) return;
      build(g, d);
    }).catch(err => {
      if (seq !== D.seq) return;
      D.nodes = []; D.edges = []; D.byId.clear(); D.dirty = true; request();
      note.textContent = 'could not load: ' + ((err && (err.message || err.error)) || err);
    });
  }
  api.drawFocus = draw;
  api.markSelected = id => { D.selected = id; D.dirty = true; request(); };

  function build(g, d) {
    const nodes = new Map();
    for (const n of g.nodes) nodes.set(n.id, {...n, fanIn: n.fanIn || 0});
    const edges = g.edges.map(e => ({...e}));
    // Direct dependents, one column to the left: who reaches for the focus.
    const up = (d.in || []).filter(e => !nodes.has(e.node.id) || e.node.id !== g.root);
    for (const e of up) {
      if (!nodes.has(e.node.id)) nodes.set(e.node.id, {...e.node, fanIn: 0, upstream: true});
      edges.push({s: e.node.id, t: g.root, k: e.kind, c: e.class});
    }
    const focus = nodes.get(g.root); if (focus) focus.fanIn = Math.max(focus.fanIn, d.fanIn || 0);
    layout([...nodes.values()], dedupe(edges), g.root);
    D.selected = S.selected;
    S.dagStats = D.stats;
    parts.mainTitle.textContent = 'Focus · ' + (focus ? focus.name : g.root) + ' · ' + D.nodes.length + ' nodes · ' + D.edges.length + ' edges';
    note.textContent = (g.truncated ? 'truncated at ' + D.nodes.length + ' nodes · ' : '') + 'drag · wheel · dbl-click a node to focus it · click to inspect';
    fitView();
  }
  function dedupe(edges) { const seen = new Set(); return edges.filter(e => { const k = e.s + '>' + e.t + '>' + e.k; if (seen.has(k)) return false; seen.add(k); return true; }); }

  /* ---- layout ---- */
  function layout(nodes, edges, rootId) {
    const byId = new Map(nodes.map(n => [n.id, n]));
    edges = edges.filter(e => byId.has(e.s) && byId.has(e.t) && e.s !== e.t);
    const out = new Map(nodes.map(n => [n.id, []]));
    for (const e of edges) out.get(e.s).push(e);

    // 1. back edges (iterative DFS) — set aside so the rest is acyclic.
    const state = new Map(); const back = new Set();
    const starts = [...nodes.filter(n => n.upstream).map(n => n.id), rootId, ...nodes.map(n => n.id)];
    for (const s of starts) {
      if (state.has(s)) continue;
      const stack = [[s, 0]]; state.set(s, 1);
      while (stack.length) {
        const top = stack[stack.length - 1]; const list = out.get(top[0]);
        if (top[1] >= list.length) { state.set(top[0], 2); stack.pop(); continue; }
        const e = list[top[1]++]; const st = state.get(e.t);
        if (st === 1) back.add(e); else if (!st) { state.set(e.t, 1); stack.push([e.t, 0]); }
      }
    }
    const fwd = edges.filter(e => !back.has(e));

    // 2. longest-path layering (Kahn).
    const indeg = new Map(nodes.map(n => [n.id, 0])); const succ = new Map(nodes.map(n => [n.id, []])); const pred = new Map(nodes.map(n => [n.id, []]));
    for (const e of fwd) { indeg.set(e.t, indeg.get(e.t) + 1); succ.get(e.s).push(e.t); pred.get(e.t).push(e.s); }
    const layer = new Map(); const queue = nodes.filter(n => indeg.get(n.id) === 0).map(n => n.id);
    queue.forEach(id => layer.set(id, 0));
    for (let i = 0; i < queue.length; i++) {
      const id = queue[i];
      for (const t of succ.get(id)) {
        layer.set(t, Math.max(layer.get(t) || 0, layer.get(id) + 1));
        indeg.set(t, indeg.get(t) - 1); if (indeg.get(t) === 0) queue.push(t);
      }
    }
    const layers = [];
    for (const n of nodes) { const l = layer.get(n.id) || 0; (layers[l] = layers[l] || []).push(n.id); }
    for (let i = 0; i < layers.length; i++) layers[i] = layers[i] || [];

    // 3. crossing reduction: barycenter sweeps, left-to-right then back.
    const pos = new Map();
    const index = () => layers.forEach(L => L.forEach((id, i) => pos.set(id, i)));
    layers.forEach(L => L.sort((a, b) => byId.get(a).name.localeCompare(byId.get(b).name)));
    index();
    const bary = (id, nb) => { const ps = nb.get(id).map(x => pos.get(x)).filter(x => x !== undefined); return ps.length ? ps.reduce((a, b) => a + b, 0) / ps.length : pos.get(id); };
    for (let sweep = 0; sweep < 6; sweep++) {
      const forward = sweep % 2 === 0;
      for (let k = 1; k < layers.length; k++) {
        const i = forward ? k : layers.length - 1 - k;
        const nb = forward ? pred : succ;
        const scores = new Map(layers[i].map(id => [id, bary(id, nb)]));
        layers[i].sort((a, b) => scores.get(a) - scores.get(b));
        layers[i].forEach((id, j) => pos.set(id, j));
      }
    }

    // 4. coordinates: columns as wide as their widest label, rows centred.
    D.ctx.font = '12px ' + (D.theme ? D.theme.mono : 'monospace');
    let x = 0;
    for (const L of layers) {
      let colW = 0;
      for (const id of L) {
        const n = byId.get(id);
        n.w = Math.min(320, Math.ceil(D.ctx.measureText(n.name).width) + PAD_X * 2 + 10);
        // Labels live in world space, so their fit does not change with zoom: once, here.
        n.label = fitText(n.name, n.w - PAD_X * 2 - 4);
        n.h = Math.round(NODE_H + Math.min(34, 6 * Math.log2(1 + n.fanIn)));
        colW = Math.max(colW, n.w);
      }
      const total = L.reduce((a, id) => a + byId.get(id).h + ROW_GAP, -ROW_GAP);
      let y = -total / 2;
      for (const id of L) { const n = byId.get(id); n.x = x; n.y = y; y += n.h + ROW_GAP; }
      x += colW + COL_GAP;
    }
    D.nodes = nodes; D.byId = byId;
    D.edges = edges.map(e => ({...e, back: back.has(e), a: byId.get(e.s), b: byId.get(e.t)}));
    D.stats.nodes = nodes.length; D.stats.edges = D.edges.length;
    D.dirty = true; request();
  }

  /* ---- view ---- */
  function bounds() {
    let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
    for (const n of D.nodes) { x0 = Math.min(x0, n.x); y0 = Math.min(y0, n.y); x1 = Math.max(x1, n.x + n.w); y1 = Math.max(y1, n.y + n.h); }
    return {x0, y0, x1, y1};
  }
  function fitView() {
    if (!D.nodes.length) return;
    const b = bounds(); const pad = 30;
    const k = Math.min(1.4, Math.max(0.04, Math.min((D.w - pad * 2) / (b.x1 - b.x0 || 1), (D.h - pad * 2) / (b.y1 - b.y0 || 1))));
    D.view = {k, x: D.w / 2 - k * (b.x0 + b.x1) / 2, y: D.h / 2 - k * (b.y0 + b.y1) / 2};
    D.dirty = true; request();
  }
  api.fitDag = fitView;
  function zoomAt(px, py, f) {
    const k = Math.min(4, Math.max(0.03, D.view.k * f)); const r = k / D.view.k;
    D.view.x = px - (px - D.view.x) * r; D.view.y = py - (py - D.view.y) * r; D.view.k = k;
    D.dirty = true; request();
  }
  const toWorld = (px, py) => ({x: (px - D.view.x) / D.view.k, y: (py - D.view.y) / D.view.k});
  function hit(px, py) {
    const p = toWorld(px, py);
    for (let i = D.nodes.length - 1; i >= 0; i--) { const n = D.nodes[i]; if (p.x >= n.x && p.x <= n.x + n.w && p.y >= n.y && p.y <= n.y + n.h) return n; }
    return null;
  }

  /* ---- drawing ---- */
  let raf = 0;
  function request() { if (!raf) raf = requestAnimationFrame(paint); }
  function colour(n) { return n.placeholder ? D.theme.faint : D.theme.m[+moduleClass(n.module).slice(1)] || D.theme.faint; }
  function paint() {
    raf = 0;
    if (!D.dirty || !D.theme) return;
    D.dirty = false;
    const t0 = performance.now();
    const ctx = D.ctx, T = D.theme, {k, x: vx, y: vy} = D.view;
    ctx.setTransform(D.dpr, 0, 0, D.dpr, 0, 0);
    ctx.clearRect(0, 0, D.w, D.h);
    if (!D.nodes.length) { D.stats.frameMs = 0; return; }
    ctx.setTransform(D.dpr * k, 0, 0, D.dpr * k, D.dpr * vx, D.dpr * vy);
    // The visible rectangle in world coordinates: anything outside is skipped.
    const r = {x0: -vx / k, y0: -vy / k, x1: (D.w - vx) / k, y1: (D.h - vy) / k};
    const near = D.hover || (D.selected && D.byId.get(D.selected)) || null;
    let drawnEdges = 0, drawnNodes = 0;

    // Edges, batched: one path per style, so a thousand edges are a handful of strokes.
    const groups = new Map();
    for (const e of D.edges) {
      const ax = e.a.x + e.a.w, ay = e.a.y + e.a.h / 2, bx = e.b.x, by = e.b.y + e.b.h / 2;
      const dip = e.back || bx <= ax ? Math.max(ay, by) + 40 : 0;
      if (Math.max(ax, bx) < r.x0 || Math.min(ax, bx) > r.x1 || Math.min(ay, by) > r.y1 || Math.max(ay, by, dip) < r.y0) continue;
      const lit = near && (e.a === near || e.b === near);
      const style = (lit ? 'L' : near ? 'd' : 'n') + (e.back ? 'b' : e.c === 'wiring' ? 'w' : 'c') + (e.b.placeholder ? '-' : '');
      let path = groups.get(style); if (!path) groups.set(style, path = new Path2D());
      path.moveTo(ax, ay);
      if (dip) path.bezierCurveTo(ax + 60, dip, bx - 60, dip, bx, by); // a back edge loops round underneath
      else { const mx = (ax + bx) / 2; path.bezierCurveTo(mx, ay, mx, by, bx, by); }
      drawnEdges++;
    }
    ctx.lineWidth = 1 / Math.max(k, 0.35);
    for (const [style, path] of groups) {
      ctx.strokeStyle = style[0] === 'L' ? T.accent : style[1] === 'b' ? T.warn : style[1] === 'w' ? T.dim : T.line;
      ctx.globalAlpha = style[0] === 'd' ? 0.35 : 1;
      ctx.setLineDash(style.endsWith('-') ? [4, 3] : []);
      ctx.stroke(path);
    }
    ctx.setLineDash([]); ctx.globalAlpha = 1;

    // Nodes: bodies in one fill and one stroke, stripes one fill per colour.
    const visible = [];
    for (const n of D.nodes) if (!(n.x > r.x1 || n.x + n.w < r.x0 || n.y > r.y1 || n.y + n.h < r.y0)) visible.push(n);
    drawnNodes = visible.length;
    const bodies = new Path2D(), stripes = new Map();
    for (const n of visible) {
      bodies.roundRect(n.x, n.y, n.w, n.h, 6);
      const c = colour(n); let p = stripes.get(c); if (!p) stripes.set(c, p = new Path2D());
      p.rect(n.x, n.y + 3, 4, n.h - 6);
    }
    ctx.fillStyle = T.panel; ctx.fill(bodies);
    ctx.strokeStyle = T.line; ctx.lineWidth = 1 / Math.max(k, 0.35); ctx.stroke(bodies);
    for (const [c, p] of stripes) { ctx.fillStyle = c; ctx.fill(p); }
    // A ghost — a reference resolved only at runtime — is outlined dashed, like its edge.
    ctx.setLineDash([4, 3]); ctx.strokeStyle = T.warn;
    for (const n of visible) if (n.ghost) { ctx.beginPath(); ctx.roundRect(n.x, n.y, n.w, n.h, 6); ctx.stroke(); }
    ctx.setLineDash([]);
    for (const n of visible) {
      if (n.id !== D.focus && n.id !== D.selected && n !== D.hover) continue;
      ctx.strokeStyle = T.accent; ctx.lineWidth = (n.id === D.focus ? 2.2 : 1.4) / Math.max(k, 0.35);
      ctx.beginPath(); ctx.roundRect(n.x, n.y, n.w, n.h, 6); ctx.stroke();
    }

    // Text only once it is readable.
    if (k > 0.45) {
      ctx.font = '12px ' + T.mono; ctx.textBaseline = 'middle';
      ctx.fillStyle = T.text;
      for (const n of visible) if (!n.placeholder) ctx.fillText(n.label, n.x + PAD_X, n.y + n.h / 2);
      ctx.fillStyle = T.faint;
      for (const n of visible) if (n.placeholder) ctx.fillText(n.label, n.x + PAD_X, n.y + n.h / 2);
      ctx.font = '9.5px ' + T.mono;
      for (const n of visible) if (n.fanIn > 1 && n.h > NODE_H + 4) ctx.fillText('←' + n.fanIn, n.x + PAD_X, n.y + n.h - 7);
    }
    D.stats.drawnNodes = drawnNodes; D.stats.drawnEdges = drawnEdges;
    D.stats.frameMs = +(performance.now() - t0).toFixed(2);
  }
  function fitText(s, max) {
    if (D.ctx.measureText(s).width <= max) return s;
    let lo = 1, hi = s.length; // longest prefix that fits with the ellipsis
    while (lo < hi) { const mid = (lo + hi + 1) >> 1; if (D.ctx.measureText(s.slice(0, mid) + '…').width <= max) lo = mid; else hi = mid - 1; }
    return s.slice(0, lo) + '…';
  }

  /* ---- interaction ---- */
  let drag = null;
  canvas.addEventListener('pointerdown', e => { canvas.setPointerCapture(e.pointerId); drag = {x: e.offsetX, y: e.offsetY, vx: D.view.x, vy: D.view.y, moved: false}; canvas.classList.add('drag'); });
  canvas.addEventListener('pointermove', e => {
    if (drag) {
      const dx = e.offsetX - drag.x, dy = e.offsetY - drag.y;
      if (Math.abs(dx) + Math.abs(dy) > 3) drag.moved = true;
      D.view.x = drag.vx + dx; D.view.y = drag.vy + dy; D.dirty = true; request(); return;
    }
    const n = hit(e.offsetX, e.offsetY);
    if (n !== D.hover) { D.hover = n; D.dirty = true; request(); }
    if (n) {
      tip.hidden = false; tip.textContent = n.fqcn + '  ·  ' + n.type + '  ·  fan-in ' + n.fanIn + (n.placeholder ? '  ·  outside the scanned tree' : '');
      tip.style.left = Math.min(e.offsetX + 14, D.w - 300) + 'px'; tip.style.top = (e.offsetY + 14) + 'px';
    } else tip.hidden = true;
  });
  const end = e => {
    canvas.classList.remove('drag');
    if (drag && !drag.moved) { const n = hit(e.offsetX, e.offsetY); if (n) api.select(n.id, null, {keepFocus: true}); }
    drag = null;
  };
  canvas.addEventListener('pointerup', end); canvas.addEventListener('pointercancel', () => { drag = null; canvas.classList.remove('drag'); });
  canvas.addEventListener('pointerleave', () => { tip.hidden = true; if (D.hover) { D.hover = null; D.dirty = true; request(); } });
  canvas.addEventListener('dblclick', e => { const n = hit(e.offsetX, e.offsetY); if (n) api.select(n.id); else fitView(); });
  canvas.addEventListener('wheel', e => { e.preventDefault(); zoomAt(e.offsetX, e.offsetY, Math.exp(-e.deltaY * 0.0015)); }, {passive: false});
  canvas.addEventListener('keydown', e => {
    if (e.key === '+' || e.key === '=') zoomAt(D.w / 2, D.h / 2, 1.2);
    else if (e.key === '-') zoomAt(D.w / 2, D.h / 2, 1 / 1.2);
    else if (e.key === '0') fitView();
    else if (e.key.startsWith('Arrow')) { e.preventDefault(); const d = 60; D.view.x += e.key === 'ArrowLeft' ? d : e.key === 'ArrowRight' ? -d : 0; D.view.y += e.key === 'ArrowUp' ? d : e.key === 'ArrowDown' ? -d : 0; D.dirty = true; request(); }
  });

  /** For measurement: redraw N frames panning to and fro across the picture, report the worst. */
  api.benchDag = (frames = 60) => {
    let worst = 0, sum = 0, drawn = 0;
    for (let i = 0; i < frames; i++) {
      D.view.x += i < frames / 2 ? 7 : -7; D.dirty = true; paint();
      worst = Math.max(worst, D.stats.frameMs); sum += D.stats.frameMs; drawn = Math.max(drawn, D.stats.drawnNodes);
    }
    return {frames, worstMs: worst, avgMs: +(sum / frames).toFixed(2), nodes: D.stats.nodes, edges: D.stats.edges, maxDrawnNodes: drawn};
  };
  /** Zoom by $k around the picture's centre — for measurement at a readable scale. */
  api.zoomDag = k => zoomAt(D.w / 2, D.h / 2, k / D.view.k);

  api.dag = D; // inspectable from the console, like window.__observatory
  readTheme(); resize();
};
window.SemitexaGraphView = window.SemitexaGraphView || {};
(window.SemitexaGraphView.extensions = window.SemitexaGraphView.extensions || []).push(ext);
})();

/* ======================================================================
 * Findings: what `ai:review-graph:findings` reports, one click from its node.
 *
 * Loops first (each is a design question), then unused classes by
 * confidence. Medium is "nothing refers to it, but discovery keeps it alive"
 * — common and usually fine — so it is a filter, not the default. The
 * coverage line says how far "no finding" can be trusted.
 * ====================================================================== */
(() => {
'use strict';
const ext = api => {
  const {source, parts, helpers} = api;
  const {el, dot, fail} = helpers;
  const F = {data: null, loading: null, filter: 'loops'};

  api.loadFindings = () => {
    if (F.loading) return F.loading;
    parts.findScroll.replaceChildren(el('div', 'gv-empty', 'Running the finders over the whole graph…'));
    F.loading = source.findings().then(d => { F.data = d; render(); }).catch(err => { F.loading = null; fail(parts.findScroll, err); });
    return F.loading;
  };

  function render() {
    const d = F.data; const box = parts.findScroll; box.replaceChildren();
    const counts = {loops: d.cycles.length, high: 0, medium: 0, low: 0};
    for (const u of d.unused) counts[u.confidence]++;
    parts.findBadge.hidden = counts.loops + counts.high === 0;
    parts.findBadge.textContent = String(counts.loops + counts.high);

    const seg = el('div', 'gv-seg');
    for (const key of ['loops', 'high', 'medium', 'low']) {
      const b = el('button', key === F.filter ? 'on' : '', (key === 'loops' ? 'loops' : key) + ' ' + counts[key]); b.type = 'button';
      b.title = key === 'loops' ? 'classes that depend on each other in a circle' : key + '-confidence unused classes';
      b.addEventListener('click', () => { F.filter = key; render(); });
      seg.append(b);
    }
    const head = el('div', 'gv-sec'); head.append(seg); box.append(head);

    const cov = d.coverage || {};
    const gaps = Object.entries(cov.gaps || {}).map(([k, n]) => n + ' ' + k.replace(/_/g, ' ')).join(', ');
    const note = el('div', 'gv-gap');
    if (cov.complete) note.append('Coverage complete: an absence of findings can be trusted.');
    else { note.append(el('b', '', 'Coverage incomplete'), ' — ' + (gaps || 'gaps') + (cov.unresolved_references ? ', ' + cov.unresolved_references + ' unresolved' : '') + '. A class reached only through one of these looks unused when it is not.'); }
    box.append(note);

    const list = el('div', 'gv-sec');
    if (F.filter === 'loops') {
      if (!d.cycles.length) list.append(el('div', 'gv-empty', 'No dependency loops.'));
      for (const c of d.cycles) {
        const b = el('button', 'gv-find'); b.type = 'button';
        const names = c.members.map(m => m.name);
        b.append(el('span', 'gv-fc gv-medium', names.length + '×'), el('span', 'gv-ft', names.slice(0, 4).join(', ') + (names.length > 4 ? ' +' + (names.length - 4) : '')));
        b.append(el('span', 'gv-fe', 'loop of ' + names.length + ' classes: ' + c.cycle.map(id => id.replace(/^class:/, '').split('\\').pop()).join(' › ')));
        b.title = 'Focus the loop: its back edge is drawn in amber';
        if (c.members[0]) b.addEventListener('click', () => api.select(c.members[0].id));
        list.append(b);
      }
    } else {
      const rows = d.unused.filter(u => u.confidence === F.filter);
      if (!rows.length) list.append(el('div', 'gv-empty', 'None at this confidence.'));
      for (const u of rows) {
        const b = el('button', 'gv-find'); b.type = 'button';
        const t = el('span', 'gv-ft'); t.append(dot(u.node), ' ', u.node.name);
        b.append(el('span', 'gv-fc gv-' + u.confidence, u.confidence), t, el('span', 'gv-fe', u.evidence + ' · ' + u.node.file + ':' + u.node.line));
        b.addEventListener('click', () => api.select(u.node.id));
        list.append(b);
      }
    }
    box.append(list);
  }
};
window.SemitexaGraphView = window.SemitexaGraphView || {};
(window.SemitexaGraphView.extensions = window.SemitexaGraphView.extensions || []).push(ext);
})();
