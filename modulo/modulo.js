(() => {
  const API = '/modulo/api.php';
  const MIN = 8, MAX = 25;
  const t0 = Date.now();
  const $ = s => document.querySelector(s);
  const form = $('#f'), msg = $('#msg'), btn = $('#sendBtn');
  let lang = 'it';
  const photos = []; // {file, url}

  // ---------- lingua ----------
  const T = {
    it: { min: n => `Servono almeno ${MIN} foto (ora ${n}).`, max: `Massimo ${MAX} foto.`, req: n => n === 1 ? 'Manca 1 risposta obbligatoria: è evidenziata in rosso.' : `Mancano ${n} risposte obbligatorie: sono evidenziate in rosso.`,
          pool: 'Scrivi i mesi di apertura della piscina.', sending: 'Invio delle risposte…', up: (i, n) => `Carico la foto ${i} di ${n}…`,
          fin: 'Ultimo passo…', fail: 'Qualcosa non ha funzionato. Riprova tra poco oppure scrivici a info@moyarooms.com.',
          fast: 'Hai compilato troppo in fretta: attendi qualche secondo e riprova.', count: n => n < MIN ? `${n} foto scelte (ne servono almeno ${MIN})` : `${n} foto scelte`, email: 'Controlla l\'indirizzo e-mail.', price: 'Scrivi almeno il prezzo per 1–2 persone.' },
    pl: { min: n => `Potrzebujemy co najmniej ${MIN} zdjęć (teraz ${n}).`, max: `Maksymalnie ${MAX} zdjęć.`, req: n => n === 1 ? 'Brakuje 1 obowiązkowej odpowiedzi – zaznaczyliśmy ją na czerwono.' : `Brakuje ${n} obowiązkowych odpowiedzi – zaznaczyliśmy je na czerwono.`,
          pool: 'Wpisz, w jakich miesiącach basen jest czynny.', sending: 'Wysyłam odpowiedzi…', up: (i, n) => `Przesyłam zdjęcie ${i} z ${n}…`,
          fin: 'Ostatni krok…', fail: 'Coś poszło nie tak. Spróbuj ponownie za chwilę albo napisz do nas: info@moyarooms.com.',
          fast: 'Formularz wypełniono zbyt szybko – odczekaj kilka sekund i spróbuj ponownie.', count: n => n < MIN ? `Wybrane zdjęcia: ${n} (potrzeba co najmniej ${MIN})` : `Wybrane zdjęcia: ${n}`, email: 'Sprawdź adres e-mail.', price: 'Podaj co najmniej cenę dla 1–2 osób.' }
  };
  function setLang(l) {
    lang = l;
    document.documentElement.lang = l;
    $('#lingua').value = l === 'pl' ? 'Polacco' : 'Italiano';
    document.querySelectorAll('.lang button').forEach(b => b.setAttribute('aria-pressed', b.dataset.l === l));
    document.querySelectorAll('option[data-it]').forEach(o => o.textContent = o.dataset[l]);
    document.querySelectorAll('[data-ph-it]').forEach(i => i.placeholder = i.dataset['ph' + (l === 'pl' ? 'Pl' : 'It')]);
    document.querySelectorAll('[data-al-it]').forEach(i => i.setAttribute('aria-label', i.dataset['al' + (l === 'pl' ? 'Pl' : 'It')]));
    renderCount();
    try { localStorage.setItem('mr_lang', l); } catch (e) {}
  }
  document.querySelectorAll('.lang button').forEach(b => b.addEventListener('click', () => setLang(b.dataset.l)));
  const qs = new URLSearchParams(location.search).get('lang');
  let saved = null; try { saved = localStorage.getItem('mr_lang'); } catch (e) {}
  setLang(qs === 'pl' || qs === 'it' ? qs : saved || ((navigator.language || '').toLowerCase().startsWith('pl') ? 'pl' : 'it'));

  // ---------- piscina ----------
  $('#hasPool').addEventListener('change', e => { $('#poolBox').hidden = !e.target.checked; });

  // ---------- prezzi ----------
  // riassunto leggibile per l'e-mail + controllo: almeno un prezzo per 1–2 persone
  function buildPrices() {
    const rows = {};
    document.querySelectorAll('#ptab input').forEach(i => {
      const v = i.value.trim();
      if (!v) return;
      (rows[i.dataset.r] = rows[i.dataset.r] || {})[i.dataset.c] = v;
    });
    $('#prezzi').value = Object.entries(rows).map(([r, c]) =>
      `${r}: bassa ${c.bassa ? c.bassa + ' €' : '–'} · alta ${c.alta ? c.alta + ' €' : '–'}`).join('\n');
    const ok = !!($('#p1bassa').value.trim() || $('#p1alta').value.trim());
    $('#p1bassa').setCustomValidity(ok ? '' : T[lang].price);
    return ok;
  }
  document.querySelectorAll('#ptab input').forEach(i => i.addEventListener('input', buildPrices));

  // ---------- foto ----------
  const input = $('#foto');
  input.addEventListener('change', () => {
    for (const f of input.files) {
      if (photos.length >= MAX) { showMsg(T[lang].max); break; }
      if (!f.type.startsWith('image/') && !/\.(jpe?g|png|webp|heic|heif)$/i.test(f.name)) continue;
      photos.push({ file: f, url: URL.createObjectURL(f) });
    }
    input.value = '';
    renderThumbs();
  });
  function renderThumbs() {
    const box = $('#thumbs');
    box.innerHTML = '';
    photos.forEach((p, i) => {
      const d = document.createElement('div');
      d.innerHTML = `<img alt="" src="${p.url}"><span>${i + 1}</span><button type="button" aria-label="Rimuovi / Usuń">×</button>`;
      d.querySelector('button').onclick = () => { URL.revokeObjectURL(p.url); photos.splice(i, 1); renderThumbs(); };
      box.appendChild(d);
    });
    renderCount();
  }
  function renderCount() { const c = $('#count'); c.textContent = photos.length ? T[lang].count(photos.length) : ''; if (photos.length >= MIN) c.classList.remove('bad'); }

  async function shrink(file) {
    try {
      const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
      const s = Math.min(1, 2000 / Math.max(bmp.width, bmp.height));
      const c = document.createElement('canvas');
      c.width = Math.round(bmp.width * s); c.height = Math.round(bmp.height * s);
      c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
      const blob = await new Promise(r => c.toBlob(r, 'image/jpeg', 0.85));
      if (blob) return blob;
    } catch (e) {}
    return file; // se il browser non sa ridurla, mandiamo l'originale
  }

  // ---------- invio ----------
  function showMsg(t, scroll = true) { msg.textContent = t; msg.hidden = !t; if (t && scroll) msg.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
  function progress(p, t) { $('#prog').hidden = false; $('#barI').style.width = p + '%'; $('#progT').textContent = t; }
  async function post(data) {
    const r = await fetch(API, { method: 'POST', body: data });
    let j = {}; try { j = await r.json(); } catch (e) {}
    if (!r.ok || !j.ok) { const err = new Error(j.error || 'http'); err.code = j.error; throw err; }
    return j;
  }

  form.addEventListener('submit', async ev => {
    ev.preventDefault();
    showMsg('');
    $('#piscina').required = $('#hasPool').checked;
    const pricesOk = buildPrices();
    form.classList.add('tried');
    if (!form.checkValidity()) {
      const bads = [...form.querySelectorAll(':invalid')].filter(el => el.tagName !== 'FIELDSET');
      const n = new Set(bads.map(el => el.type === 'radio' ? 'r:' + el.name : el)).size;
      const bad = bads[0];
      showMsg(bad && bad.type === 'email' && bad.value ? T[lang].email : (n === 1 && !pricesOk ? T[lang].price : T[lang].req(n)), false);
      if (bad) {
        // porta l'utente alla prima risposta mancante (non al messaggio in fondo)
        const box = bad.closest('.f, .consent, fieldset') || bad;
        box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => bad.focus({ preventScroll: true }), 400);
      }
      return;
    }
    if (photos.length < MIN) {
      const c = $('#count');
      c.textContent = T[lang].min(photos.length); c.classList.add('bad');
      showMsg(T[lang].min(photos.length), false);
      $('.drop').scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    btn.disabled = true;
    try {
      progress(3, T[lang].sending);
      const fd = new FormData(form);
      fd.set('cin', (fd.get('cin') || '').toString().toUpperCase());
      fd.append('action', 'start');
      fd.append('t0', String(t0));
      const { id, k } = await post(fd);
      for (let i = 0; i < photos.length; i++) {
        progress(5 + Math.round(90 * i / photos.length), T[lang].up(i + 1, photos.length));
        const blob = await shrink(photos[i].file);
        const name = photos[i].file.name.replace(/\.[^.]+$/, '') + '.jpg';
        let tries = 0;
        for (;;) {
          try {
            const p = new FormData();
            p.append('action', 'photo'); p.append('id', id); p.append('k', k);
            p.append('nome_file', name); p.append('foto', blob, name);
            await post(p); break;
          } catch (e) { if (++tries >= 3) throw e; await new Promise(r => setTimeout(r, 1500)); }
        }
      }
      progress(97, T[lang].fin);
      const fin = new FormData(); fin.append('action', 'finish'); fin.append('id', id); fin.append('k', k);
      await post(fin);
      progress(100, '');
      $('#formView').hidden = true; $('#doneView').hidden = false;
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) {
      $('#prog').hidden = true;
      showMsg(e.code === 'too_fast' ? T[lang].fast : T[lang].fail);
      btn.disabled = false;
    }
  });
})();
