(() => {
  // filtro per città
  const tabs = document.getElementById('hmTabs');
  if (tabs) tabs.addEventListener('click', ev => {
    const b = ev.target.closest('button');
    if (!b) return;
    for (const x of tabs.querySelectorAll('button')) x.setAttribute('aria-pressed', x === b);
    for (const c of document.querySelectorAll('#hmList .apt'))
      c.hidden = !(b.dataset.c === 'all' || c.dataset.city === b.dataset.c);
  });

  // mappa interattiva (OpenStreetMap), caricata solo quando ci si avvicina
  const el = document.getElementById('hmMap');
  if (!el) return;
  const pts = JSON.parse(el.dataset.pts || '[]');
  let started = false;
  const load = () => {
    if (started) return;
    started = true;
    const css = document.createElement('link');
    css.rel = 'stylesheet'; css.href = '/js/leaflet/leaflet.css';
    document.head.appendChild(css);
    const s = document.createElement('script');
    s.src = '/js/leaflet/leaflet.js'; s.onload = init;
    document.head.appendChild(s);
  };
  function popup(p) {
    const a = document.createElement('a');
    a.className = 'hm-pop'; a.href = p.url;
    const img = document.createElement('img'); img.src = p.img; img.alt = '';
    const t = document.createElement('strong'); t.textContent = p.title;
    const w = document.createElement('span'); w.textContent = p.where;
    const go = document.createElement('em'); go.textContent = 'Zobacz apartament';
    a.append(img, t, w, go);
    return a;
  }
  function init() {
    const L = window.L;
    const mobile = L.Browser.mobile;
    const map = L.map(el, { scrollWheelZoom: false, dragging: !mobile, tap: !mobile });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 18,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);
    const icon = L.divIcon({ className: 'hm-pin', html: '<span></span>', iconSize: [24, 24], iconAnchor: [12, 12], popupAnchor: [0, -14] });
    const ms = pts.map(p => L.marker([p.lat, p.lng], { icon, title: p.title, alt: p.title }).addTo(map).bindPopup(popup(p), { maxWidth: 220 }));
    if (ms.length) map.fitBounds(L.featureGroup(ms).getBounds(), { padding: [36, 36], maxZoom: 13 });
  }
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver(es => {
      if (es.some(x => x.isIntersecting)) { io.disconnect(); load(); }
    }, { rootMargin: '400px' });
    io.observe(el);
  } else load();
})();
