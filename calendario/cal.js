(() => {
  const API = '/calendario/api.php';
  const $ = s => document.querySelector(s);
  const P = new URLSearchParams(location.search);
  const AUTH = { o: P.get('o') || '', k: P.get('k') || '', mk: P.get('mk') || '' };
  let lang = 'it', data = null, cur = 0, ym = null, sel = null, today = '', lastMsg = null;

  const T = {
    it: {
      months: ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'],
      wd: ['L', 'M', 'M', 'G', 'V', 'S', 'D'],
      close: 'Chiudi', open: 'Riapri', cancel: 'Annulla', moya: 'Prenotazione Moya', unmoya: 'Togli prenotazione Moya',
      pick: 'Ora tocca l\'ultimo giorno del periodo.',
      range: (a, b, n) => `Dal ${a} al ${b} · ${n} ${n === 1 ? 'notte' : 'notti'}`,
      ts: t => { const d = new Date(t * 1000); return `${d.toLocaleDateString('it-IT')} alle ${d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' })}`; },
      saved: t => `Salvato · ultimo aggiornamento il ${t}`, upd: t => `Ultimo aggiornamento il ${t}`, never: 'Nessuna modifica ancora.',
      err: 'Non sono riuscito a salvare. Riprova tra poco.', bad: 'Questo link non è valido. Chiedi un nuovo link a Moya Rooms.',
      copied: 'Copiato', impOk: (t, n) => `Letto il ${t}: ${n} ${n === 1 ? 'prenotazione' : 'prenotazioni'}.`,
      impErr: 'Non riesco a leggere uno dei link: controlla che sia il link .ics di «Esporta calendario».',
      impNone: 'Nessun link collegato.', badUrl: 'Uno dei link non è valido.', openCal: 'Apri calendario', copyLink: 'Copia link proprietario',
      created: 'Creato. Link copiato:'
    },
    pl: {
      months: ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'],
      wd: ['Pn', 'Wt', 'Śr', 'Cz', 'Pt', 'So', 'Nd'],
      close: 'Zamknij', open: 'Otwórz', cancel: 'Anuluj', moya: 'Rezerwacja Moya', unmoya: 'Usuń rezerwację Moya',
      pick: 'Teraz dotknij ostatniego dnia okresu.',
      range: (a, b, n) => `Od ${a} do ${b} · ${n} ${n === 1 ? 'noc' : (n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14) ? 'noce' : 'nocy')}`,
      ts: t => { const d = new Date(t * 1000); return `${d.toLocaleDateString('pl-PL')} o ${d.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' })}`; },
      saved: t => `Zapisano · ostatnia aktualizacja ${t}`, upd: t => `Ostatnia aktualizacja ${t}`, never: 'Jeszcze bez zmian.',
      err: 'Nie udało się zapisać. Spróbuj ponownie za chwilę.', bad: 'Ten link jest nieprawidłowy. Poproś Moya Rooms o nowy link.',
      copied: 'Skopiowano', impOk: (t, n) => `Odczytano ${t}: rezerwacje: ${n}.`,
      impErr: 'Nie mogę odczytać jednego z linków: sprawdź, czy to link .ics z «Eksportuj kalendarz».',
      impNone: 'Brak połączonych linków.', badUrl: 'Jeden z linków jest nieprawidłowy.', openCal: 'Otwórz kalendarz', copyLink: 'Kopiuj link właściciela',
      created: 'Utworzono. Link skopiowany:'
    }
  };

  // ---------- lingua ----------
  function setLang(l) {
    lang = l;
    document.documentElement.lang = l;
    document.querySelectorAll('.lang button').forEach(b => b.setAttribute('aria-pressed', b.dataset.l === l));
    try { localStorage.setItem('mr_lang', l); } catch (e) {}
    if (data) render();
  }
  document.querySelectorAll('.lang button').forEach(b => b.addEventListener('click', () => setLang(b.dataset.l)));

  // ---------- date ----------
  const pad = n => String(n).padStart(2, '0');
  const iso = (y, m, d) => `${y}-${pad(m + 1)}-${pad(d)}`;
  const short = s => `${s.slice(8, 10)}/${s.slice(5, 7)}`;
  const nights = (a, b) => Math.round((Date.parse(b) - Date.parse(a)) / 864e5) + 1;

  // ---------- server ----------
  async function call(action, body) {
    const opt = { method: body ? 'POST' : 'GET', cache: 'no-store' };
    let url = `${API}?action=${action}`;
    const auth = { o: AUTH.o, k: AUTH.k, mk: AUTH.mk };
    if (body) { const f = new FormData(); for (const [k, v] of Object.entries({ ...auth, ...body })) f.append(k, v); opt.body = f; }
    else url += '&' + new URLSearchParams(auth).toString();
    const r = await fetch(url, opt);
    let j = {}; try { j = await r.json(); } catch (e) {}
    if (!r.ok || !j.ok) { const e = new Error(j.error || 'http'); e.code = j.error; e.status = r.status; throw e; }
    return j;
  }
  function msg(t, bad) { const s = $('#status'); s.textContent = t; s.classList.toggle('err', !!bad); lastMsg = [t, bad]; }

  // ---------- calendario ----------
  function apt() { return data.apts[cur]; }
  function stat(a, closed, d) {
    if (a.moya.some(b => d >= b.from && d < b.to)) return 'moya';
    if (a.ext.some(r => d >= r[0] && d < r[1])) return 'ext';
    if (closed.has(d)) return 'closed';
    return '';
  }
  function render() {
    const L = T[lang], a = apt();
    $('#ownerName').textContent = data.owner.name;
    const box = $('#apts');
    box.hidden = data.apts.length < 2;
    box.innerHTML = '';
    data.apts.forEach((x, i) => {
      const b = document.createElement('button');
      b.type = 'button'; b.textContent = x.name; b.setAttribute('aria-pressed', i === cur);
      b.onclick = () => { cur = i; sel = null; render(); };
      box.appendChild(b);
    });
    const [y, m] = ym;
    $('#mtitle').textContent = `${L.months[m]} ${y}`;
    $('#wd').innerHTML = L.wd.map(w => `<div class="wd">${w}</div>`).join('');
    const closed = new Set(a.closed);
    const first = (new Date(y, m, 1).getDay() + 6) % 7, n = new Date(y, m + 1, 0).getDate();
    const days = $('#days'); days.innerHTML = '';
    for (let i = 0; i < first; i++) days.appendChild(document.createElement('div'));
    const s0 = sel && sel.start, s1 = sel && (sel.end || sel.start);
    for (let d = 1; d <= n; d++) {
      const ds = iso(y, m, d), b = document.createElement('button');
      b.type = 'button'; b.className = 'd'; b.textContent = d; b.dataset.d = ds;
      const st = stat(a, closed, ds);
      if (st) b.classList.add(st);
      if (ds < today) { b.classList.add('past'); b.disabled = true; }
      if (ds === today) b.classList.add('today');
      if (s0 && ds >= s0 && ds <= s1) b.classList.add('sel');
      b.onclick = () => tap(ds);
      days.appendChild(b);
    }
    bar();
    $('#exp').value = a.export_url;
    if (document.activeElement !== $('#imp')) $('#imp').value = a.import_urls.join('\n');
    const is = $('#impStatus');
    is.textContent = !a.import_urls.length ? L.impNone : (a.ext_err.length ? L.impErr : L.impOk(L.ts(a.ext_at), a.ext.length));
    if (!lastMsg) msg(a.updated ? L.upd(L.ts(a.updated)) : L.never);
  }
  function tap(d) {
    if (d < today) return;
    if (!sel || sel.end) sel = { start: d };
    else { sel.end = d; if (sel.end < sel.start) [sel.start, sel.end] = [sel.end, sel.start]; }
    render();
  }
  function bar() {
    const L = T[lang], b = $('#bar'), acts = $('#acts');
    if (!sel) { b.hidden = true; return; }
    b.hidden = false; acts.innerHTML = '';
    const add = (label, cls, fn) => { const x = document.createElement('button'); x.type = 'button'; x.className = 'btn ' + cls; x.textContent = label; x.onclick = fn; acts.appendChild(x); };
    if (!sel.end) { $('#selText').textContent = L.pick; add(L.cancel, '', () => { sel = null; render(); }); return; }
    $('#selText').textContent = L.range(short(sel.start), short(sel.end), nights(sel.start, sel.end));
    add(L.close, 'main', () => act('close'));
    add(L.open, '', () => act('open'));
    if (data.admin) { add(L.moya, '', () => act('moya')); add(L.unmoya, '', () => act('unmoya')); }
    add(L.cancel, '', () => { sel = null; render(); });
  }
  async function act(op) {
    const L = T[lang];
    try {
      const r = await call('set', { apt: apt().id, op, from: sel.start, to: sel.end });
      data.apts[cur] = r.apt; sel = null; lastMsg = null;
      msg(L.saved(L.ts(r.apt.updated)));
      render();
    } catch (e) { msg(L.err, true); }
  }
  $('#prev').onclick = () => { let [y, m] = ym; m--; if (m < 0) { m = 11; y--; } ym = [y, m]; render(); };
  $('#next').onclick = () => { let [y, m] = ym; m++; if (m > 11) { m = 0; y++; } ym = [y, m]; render(); };
  $('#copyExp').onclick = async () => {
    const i = $('#exp'); i.select();
    try { await navigator.clipboard.writeText(i.value); } catch (e) { document.execCommand('copy'); }
    $('#copyExp').textContent = T[lang].copied;
  };
  $('#saveImp').onclick = async () => {
    const L = T[lang];
    try {
      const r = await call('import', { apt: apt().id, urls: $('#imp').value });
      data.apts[cur] = r.apt; $('#imp').blur(); render();
    } catch (e) { $('#impStatus').textContent = e.code === 'bad_url' || e.code === 'too_many' ? L.badUrl : L.err; }
  };

  // ---------- area Moya Rooms ----------
  async function admin() {
    const L = T[lang];
    $('#admin').hidden = false;
    const r = await call('admin_list');
    const ul = $('#owners'); ul.innerHTML = '';
    for (const o of r.owners) {
      const li = document.createElement('li');
      const h = document.createElement('strong'); h.textContent = o.name;
      const p = document.createElement('div'); p.className = 'who'; p.style.margin = '2px 0 0'; p.textContent = o.apts.join(' · ');
      const a = document.createElement('a'); a.className = 'btn'; a.href = `?o=${encodeURIComponent(o.id)}&mk=${encodeURIComponent(AUTH.mk)}`; a.textContent = L.openCal;
      const c = document.createElement('button'); c.type = 'button'; c.className = 'btn'; c.textContent = L.copyLink;
      c.onclick = async () => { try { await navigator.clipboard.writeText(o.link); c.textContent = L.copied; } catch (e) { prompt('', o.link); } };
      li.append(h, p, a, c); ul.appendChild(li);
    }
  }
  $('#newOwner').addEventListener('submit', async ev => {
    ev.preventDefault();
    const f = new FormData(ev.target);
    try {
      const r = await call('admin_new', { name: f.get('name'), apts: f.get('apts') });
      try { await navigator.clipboard.writeText(r.link); } catch (e) {}
      $('#newLink').textContent = T[lang].created + ' ' + r.link;
      ev.target.reset(); admin();
    } catch (e) { $('#newLink').textContent = T[lang].err; }
  });

  // ---------- avvio ----------
  const q = P.get('lang');
  let saved = null; try { saved = localStorage.getItem('mr_lang'); } catch (e) {}
  setLang(q === 'pl' || q === 'it' ? q : saved || ((navigator.language || '').toLowerCase().startsWith('pl') ? 'pl' : 'it'));
  (async () => {
    try {
      if (AUTH.mk && !AUTH.o) { await admin(); $('#boot').hidden = true; return; }
      data = await call('load');
      today = data.today;
      const t = new Date(today + 'T12:00:00'); ym = [t.getFullYear(), t.getMonth()];
      $('#boot').hidden = true; $('#cal').hidden = false;
      render();
    } catch (e) { $('#boot').textContent = T[lang].bad; }
  })();
})();
