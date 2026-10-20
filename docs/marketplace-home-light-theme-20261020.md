# Refresh homepage e tema — 20 ottobre 2026

## EXISTING — implementazione

Tema light predefinito (`html[data-theme="light"]`), con override dei token semantici per dark. La palette navy originale è conservata in dark; sidebar gestionali navy in entrambe le modalità. Pulsanti aqua con testo navy, etichette secondarie con aqua scuro nel light per mantenere il contrasto.

Lo script iniziale di `x-head`, prima di Vite, legge esclusivamente `light`/`dark` dalla chiave localStorage `theme`, con fallback light anche se lo storage è indisponibile. Aggiorna `theme-color`, etichetta e `aria-pressed` dei toggle; sincronizza le altre schede tramite evento storage. Nessuna preferenza DB. Toggle presente anche su mobile nel marketplace, nelle aree gestionali e nelle pagine di accesso.

L'accesso riservato deciso il 18 ottobre rimane invariato: guest vede ingresso/registrazione, utenti non abilitati vengono indirizzati alla propria area. Nessuna modifica alle rotte, alle policy o alle regole economiche. `MARKETPLACE_EXECUTION_MODE` e la configurazione runtime non sono stati modificati.

## Home e dati

Hero originale con immagini autorizzate dei primi quattro prodotti (fallback illustrato se assenti), ricerca nell'header, tutte le categorie attive, quattro vantaggi, occasioni (massimo 8, listino superiore al prezzo ZeroMagazzino), banner vendita, prodotti in evidenza configurati, ultimi 8 prodotti, massimo 15 marchi distinti da articoli visibili, banner scambio e massimo 4 prodotti scambiabili, sezioni editoriali per edilizia/edilizia pesante e ferramenta/elettroutensili, recensioni demo e CTA finale.

Le sezioni editoriali risolvono nomi/slug senza ID fissi e sono mostrate quando contengono prodotti: non si inventano contenuti per categorie vuote. Le altre sezioni gestiscono esplicitamente l'assenza di offerte/marchi. Nessuna immagine remota o logo marchio scaricato. I link scambio applicano il filtro esistente; le autorizzazioni PRO restano quelle esistenti.

`MarketplaceController::home()` delega la raccolta a `MarketplaceCatalog::homeData()`. Tutte le selezioni riutilizzano il catalogo o `PublicInventory`; relazioni foto/categoria/rivenditore caricate eager, nessuna query per card. Aggiunto `list_price_cents` alla selezione condivisa per esporre il prezzo barrato e il risparmio intero. Nessuna migration.

Card con immagini contenute su fondo bianco, prezzo principale evidente, listino barrato solo se superiore, risparmio arrotondato per difetto, badge spedizione/ritiro/scambio e dati rivenditore. Rail prodotti mobile e griglia desktop; recensioni scorribili anche da tastiera con focus visibile.

## Recensioni dimostrative

`config/marketplace_reviews.php`: 20 nomi fittizi e testi differenti, 18 valutazioni da 5 e 2 da 4. Media calcolata dai dati: 4,9/5. Badge Demo su ogni card, dicitura esplicita che i testi non rappresentano esperienze verificate. Sostituire questa configurazione quando saranno disponibili testimonianze reali.

## Asset mancanti — OPEN

Nel checkout ricevuto esiste solo `public/favicon.ico`. Non sono presenti, neppure altrove nel workspace:

- `public/brand/zero-magazzino-logo.png`
- `public/favicon-32x32.png`
- `public/apple-touch-icon.png`

Il componente brand è pronto a usare il PNG con `alt="ZeroMagazzino.it"`, dimensioni contenute e proporzioni preservate, su un supporto bianco adatto anche a sidebar/dark. Finché il file non è disponibile mantiene il marchio testuale esistente per evitare immagini rotte. I tre link favicon sono predisposti come richiesto; PNG e Apple restano da fornire. Nessun logo sostitutivo inventato. Il rendering del logo reale e il caricamento dei due nuovi asset non possono essere verificati finché mancano i file.

## File modificati

- `app/Http/Controllers/MarketplaceController.php`
- `app/Services/MarketplaceCatalog.php`
- `config/marketplace_reviews.php`
- `resources/css/app.css`
- `resources/views/components/area-shell.blade.php`
- `resources/views/components/brand.blade.php`
- `resources/views/components/head.blade.php`
- `resources/views/components/marketplace-card.blade.php`
- `resources/views/components/marketplace-layout.blade.php`
- `resources/views/components/theme-toggle.blade.php`
- `resources/views/layouts/guest.blade.php`
- `resources/views/marketplace/home.blade.php`
- `tests/Feature/PublicMarketplaceTest.php`
- questo documento.

## Verifiche

- `composer validate`: valido.
- `vendor/bin/pint --test`: passato.
- `php artisan test --compact`: 400 passati, 27 saltati, 2405 asserzioni. Suite su SQLite isolato in memoria, nessun reset del database esistente. Test dipendenti da MySQL non convalidati da questa esecuzione.
- `php artisan test --compact --filter=PublicMarketplaceTest`: 14 passati, 190 asserzioni; nuova copertura per deals/scambi visibili, tutte le categorie, recensioni, markup tema e favicon.
- `npm run build`: passato.
- `git diff --check`: passato; `routes/web.php` identico a HEAD e route:list eseguito correttamente.

L'ambiente inietta un valore non numerico per `MARKETPLACE_COMMISSION_VAT_BASIS_POINTS`; per i soli comandi di test e l'anteprima è stata usata la variabile vuota (valore nullable ammesso). Nessun file di configurazione modificato. La prima esecuzione integrale senza accesso rete aveva 9 errori nei test installer dovuti al blocco socket; rieseguita con accesso ai socket locali, termina senza errori.

Browser Chromium/Playwright su nuovo SQLite dedicato con dati fittizi, senza operazioni di pagamento: home a 375, 430, 768, 1024, 1440 px nei due temi; dashboard admin e rivenditore a 375 e 1440 px nei due temi. Controlli axe WCAG 2 A/AA e 2.1 AA, overflow, persistenza toggle dopo reload. Le fixture non contengono foto: verificato il fallback, non il rendering di fotografie reali. Screenshot e risultati tecnici conservati nella cartella di lavoro `work/screenshots/` (non versionata).

Esito finale browser: 18 combinazioni pagina/tema/larghezza controllate, zero violazioni axe e zero overflow orizzontale. Corrette durante la verifica l'etichettatura semantica delle stelle e la raggiungibilità da tastiera del rail recensioni. L'anteprima usa il router Laravel e gli asset Vite compilati serviti localmente.
