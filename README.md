# moyarooms.com

Sito statico di Moya Rooms (MOYA RENT S.R.L.S.), in polacco.

## Come si aggiorna
1. I dati stanno in `data/apartamenty.json` (appartamenti) e `data/miasta.json` (città).
2. Si lancia `python3 tools/build.py`: rigenera la home, le pagine delle città, le pagine degli appartamenti, `404.html`, `sitemap.xml` e i link corti in `.htaccess`.
3. Si carica tutto su `main`: OVH pubblica da solo tramite webhook.

## Struttura degli indirizzi
- `/` home con tutti gli appartamenti
- `/<città>/` pagina della città (es. `/avola/`)
- `/<città>/<appartamento>/` pagina dell'appartamento (es. `/avola/apartament-z-basenem/`)
- link corti: `/adriana`, `/peppe`, `/iwona`, `/seba-1`, `/seba-2` → portano alla pagina dell'appartamento

## Cartelle
- `css/site.css`, `js/site.js` – stile e script comuni a tutte le pagine
- `img/` – foto e logo
- `fonts/` – Outfit e DM Sans ospitati sul server (licenza SIL OFL 1.1)
- `polityka-prywatnosci.html` – privacy e cookie (scritta a mano, non generata)
