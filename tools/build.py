#!/usr/bin/env python3
"""Genera le pagine statiche di moyarooms.com a partire da data/*.json.

Uso:  python3 tools/build.py
Crea/aggiorna: index.html, <citta>/index.html, <citta>/<appartamento>/index.html,
404.html, sitemap.xml e i link corti in .htaccess.
"""
import json, os, re, html, urllib.parse, datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = 'https://moyarooms.com'
WA = '393446501947'
WA_SHOW = '+39 344 650 1947'
MAIL = 'info@moyarooms.com'
TODAY = datetime.date.today().isoformat()
HOME_CAR = {'url': 'https://moyatravel.com/wynajem-samochodu-wlochy/', 'text': 'wynajem samochodu we Włoszech'}

APTS = json.load(open(os.path.join(ROOT, 'data/apartamenty.json'), encoding='utf-8'))
CITIES = json.load(open(os.path.join(ROOT, 'data/miasta.json'), encoding='utf-8'))
CITY = {c['slug']: c for c in CITIES}

e = lambda s: html.escape(str(s), quote=True)
url_of = lambda a: f"/{a['citySlug']}/{a['slug']}/"
img = lambda name: f"/img/{name}.jpg"


def wa(text):
    return f"https://wa.me/{WA}?text={urllib.parse.quote(text)}"


def plural_zdjec(n):
    if n == 1:
        return 'zdjęcie'
    if n % 10 in (2, 3, 4) and n % 100 not in (12, 13, 14):
        return 'zdjęcia'
    return 'zdjęć'


WA_ICON = ('<svg viewBox="0 0 32 32" aria-hidden="true"><path fill="#fff" d="M16 3a13 13 0 0 0-11.2 19.6L3 29l6.6-1.7A13 13 0 1 0 16 3zm0 23.6c-2 0-4-.5-5.7-1.6l-.4-.2-3.9 1 1-3.8-.3-.4A10.6 10.6 0 1 1 16 26.6zm5.8-7.9c-.3-.2-1.9-.9-2.2-1-.3-.1-.5-.2-.7.2l-1 1.2c-.2.2-.4.2-.7.1a8.7 8.7 0 0 1-4.3-3.8c-.3-.6.3-.5 1-1.7.1-.2 0-.4 0-.6l-1-2.4c-.3-.6-.5-.5-.7-.5h-.6c-.2 0-.6.1-.9.4-.3.3-1.1 1.1-1.1 2.7s1.2 3.1 1.3 3.3c.2.2 2.3 3.5 5.6 4.9 2.1.9 2.9 1 4 .8.6-.1 1.9-.8 2.2-1.5.3-.8.3-1.4.2-1.5l-.6-.3z"/></svg>')


def page(*, path, title, description, body, og_image='/img/hero.jpg', jsonld=None, gal=None, noindex=False, wa_text='Dzień dobry! Piszę ze strony Moya Rooms.', car=None):
    canonical = SITE + path
    ld = ''
    if jsonld:
        ld = '<script type="application/ld+json">' + json.dumps(jsonld, ensure_ascii=False) + '</script>\n'
    galjs = ''
    if gal:
        galjs = '<script>window.GAL=' + json.dumps(gal, ensure_ascii=False) + ';</script>\n'
    robots = '<meta name="robots" content="noindex">\n' if noindex else ''
    car = car or HOME_CAR
    carband = f'''<section class="band carband">
<div class="wrap">
<div>
<h2>Szukasz samochodu?</h2>
<p>Auto na wakacje wynajmiesz u nas, w Moya Travel – po polsku i bez stresu. Sprawdź: <a href="{car['url']}">{e(car['text'])}</a>.</p>
</div>
<a class="btn main" href="{car['url']}">Bezpłatna wycena auta →</a>
</div>
</section>
'''
    return f'''<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{e(title)}</title>
<meta name="description" content="{e(description)}">
{robots}<link rel="canonical" href="{canonical}">
<link rel="icon" href="/favicon-48.png" type="image/png" sizes="48x48">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta property="og:type" content="website">
<meta property="og:locale" content="pl_PL">
<meta property="og:site_name" content="Moya Rooms">
<meta property="og:title" content="{e(title)}">
<meta property="og:description" content="{e(description)}">
<meta property="og:url" content="{canonical}">
<meta property="og:image" content="{SITE}{og_image}">
<link rel="stylesheet" href="/fonts/fonts.css">
<link rel="stylesheet" href="/css/site.css">
{ld}</head>
<body>

<header>
<nav class="nav" aria-label="Menu główne">
<a class="logo" href="/"><img src="/img/logo-mark.png" alt="" width="40" height="40">Moya Rooms</a>
<div class="links">
<a href="/#apartamenty">Apartamenty</a>
<a href="/#rezerwacja" class="hide-s">Jak zarezerwować</a>
<a href="#kontakt">Kontakt</a>
</div>
</nav>
</header>

<main id="top">
{body}
{carband}<section id="kontakt" class="contact band">
<div class="wrap">
<div>
<h2>Kontakt</h2>
<p>Napisz do nas po polsku – pomożemy wybrać apartament i termin.</p>
</div>
<div class="cbox">
<a class="btn main" href="{e(wa(wa_text))}">Napisz na WhatsApp</a>
<div>WhatsApp: <span class="big">{WA_SHOW}</span></div>
<div>E-mail: <a class="big" href="mailto:{MAIL}">{MAIL}</a></div>
</div>
</div>
</section>
</main>

<footer class="band" style="padding-block:28px">
<div class="wrap">
<div><strong style="color:var(--brown)">Moya Rooms</strong> · MOYA RENT S.R.L.S. · P.IVA 03375650805 · Via Trapezzoli Sud 73, 89134 Reggio Calabria</div>
<div><a href="/polityka-prywatnosci.html">Polityka prywatności i cookies</a> · © <span id="y">2026</span></div>
</div>
</footer>

<a class="fab" href="{e(wa(wa_text))}" aria-label="Napisz na WhatsApp">
{WA_ICON}
</a>
{lightbox() if gal else ''}{galjs}<script src="/js/site.js" defer></script>
</body>
</html>
'''


def lightbox():
    return '''<div class="lb" id="lb" role="dialog" aria-modal="true" aria-label="Galeria zdjęć">
<img id="lbImg" alt="">
<button class="x" id="lbX" aria-label="Zamknij">×</button>
<button class="pv" id="lbP" aria-label="Poprzednie zdjęcie">‹</button>
<button class="nx" id="lbN" aria-label="Następne zdjęcie">›</button>
<div class="cap" id="lbC"></div>
</div>
'''


def crumbs(items):
    """items: [(name, path or None)]"""
    parts = []
    for i, (name, path) in enumerate(items):
        if i:
            parts.append('<span aria-hidden="true">›</span>')
        parts.append(f'<a href="{path}">{e(name)}</a>' if path else f'<span aria-current="page">{e(name)}</span>')
    ld = {"@context": "https://schema.org", "@type": "BreadcrumbList", "itemListElement": [
        {"@type": "ListItem", "position": i + 1, "name": n, **({"item": SITE + p} if p else {})}
        for i, (n, p) in enumerate(items)]}
    return '<nav class="crumbs" aria-label="Jesteś tutaj">' + ''.join(parts) + '</nav>', ld


def card(a, heading='h3'):
    u = url_of(a)
    n = len(a['imgs'])
    first = a['imgs'][0]
    tags = ''.join(f'<span>{e(t)}</span>' for t in a.get('tags', []))
    units = ''
    if a.get('units'):
        units = '<ul class="units">' + ''.join(f'<li><b>{e(x[0])}</b> – {e(x[1])}</li>' for x in a['units']) + '</ul>'
    city = CITY[a['citySlug']]
    wa_t = f"Dzień dobry! Interesuje mnie {a['title']} {city['loc']} ({a['name']}). {SITE}{u}"
    return f'''<article class="apt">
<a class="ph" href="{u}"><img src="{img(first[0])}" alt="{e(a['title'])} {e(city['loc'])} – {e(first[1])}" loading="lazy" width="800" height="600"><span class="n">{n} {plural_zdjec(n)}</span></a>
<div class="in"><{heading}><a href="{u}">{e(a['title'])}</a></{heading}><div class="sub">{e(a['name'])}</div><div class="where">{e(a['where'])}</div>
<p>{e(a.get('desc', ''))}</p>{units}{'<div class="tags">' + tags + '</div>' if tags else ''}
<div class="foot"><span class="cin">{'CIN: ' + e(a['cin']) if a.get('cin') else ''}</span><span class="bts"><a class="btn sm" href="{u}">Szczegóły</a><a class="btn sm main" href="{e(wa(wa_t))}">Zapytaj o termin</a></span></div></div>
</article>'''


# ---------- home ----------
def build_home():
    regions = []
    for a in APTS:
        if a['region'] not in regions:
            regions.append(a['region'])
    chips = ''.join(f'<a href="/{c["slug"]}/">{e(c["name"])}</a>' for c in CITIES)
    filters = ''
    if len(regions) > 1:
        filters = '<div class="filters" id="filters" role="group" aria-label="Region">' + \
            '<button aria-pressed="true" data-r="all">Wszystkie</button>' + \
            ''.join(f'<button aria-pressed="false" data-r="{e(r)}">{e(r)}</button>' for r in regions) + '</div>'
    blocks = []
    for r in regions:
        lst = [a for a in APTS if a['region'] == r]
        cs = []
        for a in lst:
            if a['citySlug'] not in cs:
                cs.append(a['citySlug'])
        links = ', '.join(f'<a href="/{c}/">{e(CITY[c]["name"])}</a>' for c in cs)
        blocks.append(f'<div class="region" data-region="{e(r)}"><h3>{e(r)} <small>{links}</small></h3><div class="grid">' +
                      '\n'.join(card(a) for a in lst) + '</div></div>')
    body = f'''<section class="hero band">
<div class="wrap">
<div>
<h1>Apartamenty na południu Włoch</h1>
<p class="lead">Wybierz apartament, napisz do nas po polsku i zarezerwuj bezpośrednio.</p>
<div class="chips">{chips}</div>
<div class="acts">
<a class="btn main" href="{e(wa('Dzień dobry! Piszę ze strony Moya Rooms.'))}">Napisz na WhatsApp</a>
<a class="btn" href="#apartamenty">Zobacz apartamenty</a>
</div>
</div>
<figure style="margin:0">
<div class="arch"><img src="/img/hero.jpg" alt="Zachód słońca nad zatoczką San Giovanni Li Cuti w Katanii" width="1600" height="1200"></div>
<figcaption>San Giovanni Li Cuti, Katania – kilka kroków od <a href="/katania/apartament-przy-morzu/">Apartamentu przy morzu</a></figcaption>
</figure>
</div>
</section>

<section id="apartamenty" class="band">
<div class="wrap">
<h2>Nasze apartamenty</h2>
<p style="color:var(--soft);max-width:42em">Napisz, kiedy przyjeżdżasz i ile was jest – pomożemy wybrać najlepszą opcję.</p>
{filters}
<div id="list">
{chr(10).join(blocks)}
</div>
</div>
</section>

<section id="rezerwacja" class="band" style="background:var(--sand)">
<div class="wrap">
<h2>Jak zarezerwować</h2>
<div class="steps">
<div class="step"><h3>Napisz do nas</h3><p>Na WhatsApp lub e-mailem: termin, liczba osób i apartament, który Cię interesuje.</p></div>
<div class="step"><h3>Sprawdzamy dostępność</h3><p>Odpowiadamy po polsku i wysyłamy cenę za Twój termin.</p></div>
<div class="step"><h3>Potwierdzamy rezerwację</h3><p>Dostajesz potwierdzenie i wszystkie informacje o przyjeździe.</p></div>
</div>
</div>
</section>

<section id="wycieczki" class="band" hidden>
<div class="wrap"><h2>Wycieczki</h2><p>Sekcja w przygotowaniu.</p></div>
</section>
'''
    return page(path='/', title='Moya Rooms – Apartamenty we Włoszech',
                description='Apartamenty na południu Włoch: Katania, Palermo i Avola. Obsługa i rezerwacja po polsku.',
                body=body)


# ---------- città ----------
def build_city(c):
    lst = [a for a in APTS if a['citySlug'] == c['slug']]
    cr, ld = crumbs([('Moya Rooms', '/'), (c['name'], None)])
    others = ' · '.join(f'<a href="/{o["slug"]}/">{e(o["h1"])}</a>' for o in CITIES if o['slug'] != c['slug'])
    body = f'''<section class="band phead">
<div class="wrap">
{cr}
<h1>{e(c['h1'])}</h1>
<p class="cityintro">{e(c['intro'])}</p>
</div>
</section>

<section class="band">
<div class="wrap">
<h2>{len(lst)} {'apartament' if len(lst) == 1 else 'apartamenty'} {e(c['loc'])}</h2>
<div class="grid">
{chr(10).join(card(a) for a in lst)}
</div>
<p style="margin-top:28px;color:var(--soft)">Zobacz też: {others}</p>
</div>
</section>
'''
    return page(path=f"/{c['slug']}/", title=c['title'], description=c['description'], body=body,
                og_image=img(lst[0]['imgs'][0][0]), jsonld=ld,
                wa_text=f"Dzień dobry! Interesują mnie apartamenty {c['loc']}.", car=c.get('car'))


# ---------- appartamento ----------
def build_apt(a):
    c = CITY[a['citySlug']]
    u = url_of(a)
    cr, ld = crumbs([('Moya Rooms', '/'), (c['name'], f"/{c['slug']}/"), (a['title'], None)])
    n = len(a['imgs'])
    gal_btns = []
    for i, (name, cap) in enumerate(a['imgs'][:5]):
        extra = f'<span class="all">Wszystkie zdjęcia ({n})</span>' if i == 0 else ''
        load = '' if i == 0 else ' loading="lazy"'
        gal_btns.append(f'<button data-i="{i}" aria-label="Otwórz zdjęcie: {e(cap)}"><img src="{img(name)}" alt="{e(a["title"])} {e(c["loc"])} – {e(cap)}"{load}>{extra}</button>')
    thumbs = ''.join(f'<button data-i="{i}" aria-label="Otwórz zdjęcie: {e(cap)}"><img src="{img(name)}" alt="{e(cap)}" loading="lazy"></button>'
                     for i, (name, cap) in enumerate(a['imgs']))
    tags = ''.join(f'<span>{e(t)}</span>' for t in a.get('tags', []))
    units = ''
    if a.get('units'):
        units = '<h2>Opcje pobytu</h2><ul class="units">' + ''.join(f'<li><b>{e(x[0])}</b> – {e(x[1])}</li>' for x in a['units']) + '</ul>'
    equip = ''
    if a.get('equip'):
        equip = '<h2>Wyposażenie</h2><ul class="eq">' + ''.join(f'<li>{e(x)}</li>' for x in a['equip']) + '</ul>'
    info = ''
    if a.get('info'):
        info = '<h2>Dobrze wiedzieć</h2><dl class="facts">' + ''.join(f'<div><dt>{e(k)}</dt><dd>{e(v)}</dd></div>' for k, v in a['info']) + '</dl>'
    wa_t = f"Dzień dobry! Interesuje mnie {a['title']} {c['loc']} ({a['name']}). {SITE}{u}"
    others = [o for o in APTS if o['id'] != a['id'] and o['citySlug'] == a['citySlug']]
    others += [o for o in APTS if o['citySlug'] != a['citySlug']]
    others = others[:3]
    body = f'''<section class="band phead">
<div class="wrap">
{cr}
<h1>{e(a['h1'])}</h1>
<p class="sub">{e(a['name'])} · {e(a['where'])}</p>
<div class="gal">{''.join(gal_btns)}</div>
</div>
</section>

<section class="band">
<div class="wrap pbody">
<div>
<h2>Opis</h2>
<p class="desc">{e(a.get('desc', ''))}</p>
{'<div class="tags" style="margin-top:16px">' + tags + '</div>' if tags else ''}
{units}
{equip}
{info}
<h2>Wszystkie zdjęcia</h2>
<div class="thumbs">{thumbs}</div>
</div>
<aside class="box" aria-label="Rezerwacja">
<h2>Zapytaj o termin</h2>
<p>Napisz, kiedy przyjeżdżasz i ile was jest. Odpowiadamy po polsku i wysyłamy cenę za Twój termin.</p>
<a class="btn main" href="{e(wa(wa_t))}">Napisz na WhatsApp</a>
<a class="btn" href="mailto:{MAIL}?subject={urllib.parse.quote(a['title'] + ' ' + c['loc'])}">Napisz e-mail</a>
{'<p class="cin">CIN: ' + e(a['cin']) + '</p>' if a.get('cin') else ''}
</aside>
</div>
</section>

<section class="band" style="background:var(--sand)">
<div class="wrap">
<h2>Zobacz też</h2>
<div class="grid">
{chr(10).join(card(o) for o in others)}
</div>
</div>
</section>
'''
    desc = a.get('desc', '')
    meta = f"{a['h1']}: {desc}"
    if len(meta) > 158:
        meta = meta[:155].rsplit(' ', 1)[0].rstrip(',.–- ') + '…'
    gal = [[img(nm), cap] for nm, cap in a['imgs']]
    return page(path=u, title=f"{a['h1']} | Moya Rooms", description=meta, body=body,
                og_image=img(a['imgs'][0][0]), jsonld=ld, gal={'name': a['title'], 'imgs': gal}, wa_text=wa_t, car=c.get('car'))


def build_404():
    body = '''<section class="band err">
<div class="wrap">
<h1>Nie znaleźliśmy tej strony</h1>
<p class="lead" style="margin-inline:auto">Możliwe, że adres się zmienił. Zobacz nasze apartamenty:</p>
<div class="chips" style="justify-content:center">''' + ''.join(f'<a href="/{c["slug"]}/">{e(c["name"])}</a>' for c in CITIES) + '''</div>
<p><a class="btn main" href="/">Strona główna</a></p>
</div>
</section>
'''
    return page(path='/404.html', title='Nie znaleziono strony | Moya Rooms', description='Strona nie istnieje.', body=body, noindex=True)


def write(rel, content):
    p = os.path.join(ROOT, rel)
    os.makedirs(os.path.dirname(p), exist_ok=True)
    open(p, 'w', encoding='utf-8').write(content)


def main():
    write('index.html', build_home())
    urls = ['/']
    for c in CITIES:
        write(f"{c['slug']}/index.html", build_city(c))
        urls.append(f"/{c['slug']}/")
    for a in APTS:
        write(f"{a['citySlug']}/{a['slug']}/index.html", build_apt(a))
        urls.append(url_of(a))
    write('404.html', build_404())
    urls.append('/polityka-prywatnosci.html')
    sm = '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' + \
        ''.join(f'<url><loc>{SITE}{u}</loc><lastmod>{TODAY}</lastmod></url>\n' for u in urls) + '</urlset>\n'
    write('sitemap.xml', sm)
    # link corti in .htaccess, tra i due marcatori
    ht_path = os.path.join(ROOT, '.htaccess')
    ht = open(ht_path, encoding='utf-8').read()
    short = '# BEGIN link corti (generato da tools/build.py)\n' + \
        ''.join(f"RewriteRule ^{re.escape(a['short'])}/?$ {url_of(a)} [R=301,L]\n" for a in APTS) + \
        '# END link corti\n'
    ht = re.sub(r'# BEGIN link corti.*?# END link corti\n', '', ht, flags=re.S)
    ht = ht.replace('RewriteRule ^(.*)$ https://moyarooms.com/$1 [R=301,L]\n',
                    'RewriteRule ^(.*)$ https://moyarooms.com/$1 [R=301,L]\n\n' + short, 1)
    if 'ErrorDocument 404' not in ht:
        ht += '\nErrorDocument 404 /404.html\n'
    open(ht_path, 'w', encoding='utf-8').write(ht)
    print('Pagine generate:', len(urls) - 1, '+ 404')


if __name__ == '__main__':
    main()
