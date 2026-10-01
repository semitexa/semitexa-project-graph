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
function dot(node) { const d = el('span', 'gv-dot ' + (node.placeholder ? 'ph' : moduleClass(node.module))); d.title = node.module || 'no module'; return d; }
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
  const stale = el('span', 'gv-badge warn', 'stale'); stale.hidden = true;
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
      row.append(el('span', 'gv-caret', '▸'), el('span', '', g.label), el('span', 'n', String(list.length)));
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
  function select(id, row) {
    S.selected = id;
    tree.querySelectorAll('.gv-row.sel').forEach(r => r.classList.remove('sel'));
    (row ? [row] : [...(S.rows.get(id) || [])]).forEach(r => r.classList.add('sel'));
    loadDetail(id);
    focusGraph(id);
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
    if (n.fqcn && n.fqcn !== n.name) head.append(el('div', 'fq', n.fqcn));
    head.append(el('div', 'meta', (n.module || 'no module') + (n.file ? ' · ' + n.file + ':' + n.line : '') + ' · fan-in ' + d.fanIn));
    const acts = el('div', 'acts');
    if (source.live && isClassId(n.id)) {
      const a = el('a', '', 'source & wiring ↗'); a.href = '/__trace/node?class=' + encodeURIComponent(n.fqcn); a.target = '_blank'; a.rel = 'noopener';
      acts.append(a);
    }
    const rev = el('button', '', 'show in tree'); rev.type = 'button'; rev.className = 'gv-btn'; rev.addEventListener('click', () => reveal(n.id));
    acts.append(rev);
    head.append(acts);
    if (n.placeholder) head.append(el('div', 'meta', 'Placeholder: referenced, but declared outside the scanned tree.'));
    detailBody.append(head);
    if (options.decorateDetail) options.decorateDetail(detailBody, d);
    detailBody.append(edgeSection('Reaches', d.out), edgeSection('Reached by', d.in));
    if (d.truncated) detailBody.append(el('div', 'gv-empty', 'Only the first 400 edges per side are listed.'));
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
      b.append(dot(n), el('span', 'gv-name', n.name), el('span', 'gv-type', n.type), el('span', 'fq', n.fqcn));
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
