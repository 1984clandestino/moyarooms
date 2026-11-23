(() => {
  // anno nel footer
  const y = document.getElementById('y');
  if (y) y.textContent = new Date().getFullYear();

  // filtri per regione (home, solo se ci sono più regioni)
  const f = document.getElementById('filters');
  if (f) f.addEventListener('click', ev => {
    const b = ev.target.closest('button');
    if (!b) return;
    for (const x of f.querySelectorAll('button')) x.setAttribute('aria-pressed', x === b);
    for (const r of document.querySelectorAll('.region'))
      r.hidden = !(b.dataset.r === 'all' || r.dataset.region === b.dataset.r);
  });

  // galleria a schermo intero (pagina appartamento)
  const G = window.GAL, lb = document.getElementById('lb');
  if (!G || !lb) return;
  const li = document.getElementById('lbImg'), lc = document.getElementById('lbC');
  let i = 0, last = null;
  const show = () => {
    const [src, cap] = G.imgs[i];
    li.src = src;
    li.alt = G.name + ' – ' + cap;
    lc.textContent = cap + ' (' + (i + 1) + '/' + G.imgs.length + ')';
  };
  const open = n => { last = document.activeElement; i = n; show(); lb.classList.add('on'); document.getElementById('lbX').focus(); };
  const close = () => { lb.classList.remove('on'); if (last) last.focus(); };
  const step = d => { i = (i + d + G.imgs.length) % G.imgs.length; show(); };
  document.addEventListener('click', ev => {
    const b = ev.target.closest('[data-i]');
    if (b) open(+b.dataset.i);
  });
  document.getElementById('lbX').onclick = close;
  document.getElementById('lbP').onclick = () => step(-1);
  document.getElementById('lbN').onclick = () => step(1);
  lb.addEventListener('click', ev => { if (ev.target === lb) close(); });
  document.addEventListener('keydown', ev => {
    if (!lb.classList.contains('on')) return;
    if (ev.key === 'Escape') close();
    if (ev.key === 'ArrowRight') step(1);
    if (ev.key === 'ArrowLeft') step(-1);
  });
  let x0 = null;
  lb.addEventListener('touchstart', ev => { x0 = ev.touches[0].clientX; }, { passive: true });
  lb.addEventListener('touchend', ev => {
    if (x0 === null) return;
    const dx = ev.changedTouches[0].clientX - x0;
    if (Math.abs(dx) > 40) step(dx < 0 ? 1 : -1);
    x0 = null;
  });
})();
