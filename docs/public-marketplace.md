# Marketplace pubblico — 13 ottobre 2026

## EXISTING — proposta di scambio operativa

Il pulsante Proponi uno scambio apre ora il form versionato PRO: non è più un placeholder. Richiede prodotto published/scambiabile con disponibilità, entrambe le parti PRO attive e approved, ed esclude il proprietario. change_pending resta visibile per gli acquisti ma non selezionabile negli scambi. Quantità pubbliche includono anche le prenotazioni exchange. Dettagli in [exchanges.md](exchanges.md); le descrizioni di placeholder nelle sezioni storiche sono superate.


## EXISTING — route e funzionalità

- `/`: homepage marketplace con ricerca, categorie principali, ultime otto pubblicazioni, CTA rivenditore/accesso. `marketplace.featured_item_ids` predispone una selezione curata futura: vuota per default, massimo otto prodotti e stessa regola di visibilità del catalogo.
- `/marketplace`: catalogo paginato a 24 prodotti, ricerca su nome/marca/SKU/EAN, filtri categoria/marca/condizione/provincia/prezzo/consegna/scambio, quattro ordinamenti server-side.
- `/marketplace/categoria/{slug}`: catalogo della categoria; il filtro categoria in query non può sostituire quella della route. I prodotti già assegnati a categorie disattivate restano visibili; la homepage e il selettore generale mostrano le categorie attive.
- `/marketplace/prodotto/{id}`: scheda con foto approvate, dati commerciali, quantità/unità, IVA descrittiva, venditore, provincia/località e consegna. ID stabile scelto intenzionalmente per mantenere URL/canonical dopo cambi nome; lo slug categoria usa il catalogo Category esistente.
- `/marketplace/prodotto/{id}/immagini/{position}`: immagine approvata servita dal filesystem privato dopo controllo del prodotto e della referenza.
- `/marketplace/prodotto/{id}/acquista`: auth + capacità purchase, pagina informativa senza scritture, ordini o incassi. Guest indirizzato al login; customer e retailer ammessi, admin escluso.
- `/marketplace/prodotto/{id}/scambio`: auth e controllo degli stessi requisiti del pulsante, pagina informativa senza proposta/reservation.

La richiesta informazioni usa le route esistenti `/products/{item}/request`, aggiungendo il ritorno alla scheda. Validazione, consenso, CSRF, antispam, rate limit, ownership e lock restano conservati. Una richiesta non prenota stock e non costituisce acquisto.

## Visibilità e privacy

PublicInventory centralizza query e controllo: status published/change_pending, published_at presente e Retailer approved. Draft/pending/rejected/archived e aziende pending/rejected/suspended non compaiono in homepage, catalogo, categorie, ricerca/facet o schede. Le schede non ammesse rispondono 404. Le query pubbliche selezionano solo colonne approvate; non selezionano proposed_data/proposed_images, IBAN o contatti privati. La scheda usa esclusivamente colonne standard e inventory_images approvate, mai le proposte.

La lettura pubblica è una capacità Policy distinta dalla view privata. La route immagini usa immagini della relazione corrente del prodotto, posizione numerica, disk local e riferimento inventory/UUID.jpg validato da InventoryPhotos. Rifiuta parametri proposed, riferimenti arbitrari e file mancanti. Nessun symlink verso storage/private, nessuna URL diretta al path. Cache-Control private/no-store impedisce riuso persistente dopo sospensione o cambio foto; l'eleggibilità è ricontrollata a ogni richiesta. La route auth precedente continua a proteggere immagini/proposte del proprietario/admin.

Proponi uno scambio appare e funziona solo se l'offerta exchange_available è pubblica, entrambi i rivenditori sono approved con PRO temporalmente valido e benefit scambio, il visitatore è autenticato e non è il proprietario. Customer, admin, FREE, PRO scaduto e proprietario non sono ammessi. Scambio non è ancora un workflow operativo. Le pagine autenticate mantengono private/no-store, anche quando il contenuto pubblico mostra CTA personalizzate.

## Query e performance

MarketplaceRequest valida input e whitelist di ordinamento; importi filtro convertiti esattamente in centesimi con Brick Math, senza float. Range incoerenti, precisione eccessiva, array o valori non validi sono rifiutati. I caratteri `%`, `_` e il carattere escape della ricerca sono trattati letteralmente; SQL parametrizzato e colonne/order fissi. Ordine per prezzo o published_at con ID come spareggio deterministico. Query string dei filtri conservata nella paginazione, page size fissata e page bounded.

MarketplaceCatalog eager-loads retailer, categoria e immagini approvate; il numero di query del catalogo è costante rispetto ai prodotti mostrati (otto query normalmente, entro nove nel test), non una query per card. Facet marca/provincia derivano esclusivamente da offerte visibili. Nessun gateway/SDK remoto chiamato per leggere il catalogo, nessuna dipendenza da ID esterno o StorePublication riuscita.

Migration additiva `2026_10_13_000002_add_marketplace_catalog_indexes` aggiunge indici status/data/ID, status/prezzo/ID, categoria/status/prezzo/ID, brand/status ed EAN; gli indici esistenti coprono anche stato/provincia e FK. Non cambia dati o migration precedenti. La ricerca parziale LIKE `%term%` può richiedere scansione dei candidati ammessi: gli indici aiutano filtri/visibilità/ordinamento, non equivalgono a un motore full-text. Misurare su volumi reali prima di introdurre full-text/search engine; nessun benchmark di produzione dichiarato.

## UX e SEO

Blade/Tailwind/Alpine, card responsive, galleria con selezione foto, stati vuoti, target tattili e nessuno scroll orizzontale nei viewport collaudati. Filtri mobile in bottom sheet native dialog: Escape/Chiudi, focus gestito dal browser, nessuna duplicazione di input/ID. Senza JavaScript resta un details utilizzabile; desktop sidebar. Invio GET server-side, non filtraggio client dei prodotti privati.

Title, meta description, canonical e OpenGraph essenziali sul layout marketplace. Canonical prodotto stabile sull'ID; categorie per slug; paginazione con canonical della pagina. Combinazioni ricerca/filtri/sort noindex,follow con canonical base per limitare duplicati. Testi e attributi escaped, descrizioni SEO derivate solo da testo approvato. Nessuno script, dato fiscale o secret inserito nei metadata.

## Verifica

Suite esistente e PublicMarketplaceTest: SQLite **244 passati, 1377 asserzioni, 6 skip MySQL**; MySQL **250 passati, 1413 asserzioni**. Coperti visibilità/stati/aziende, versioni approvate, gateway assente, ricerca e wildcard, tutti i filtri, ordinamenti/paginazione, input invalidi, immagini/private path/proposte, scheda/SEO, acquisto placeholder, permessi scambio e query count senza N+1.

Build Vite e Pint superati. **30 controlli Chromium/axe** su homepage, catalogo, categoria, scheda, richiesta e risultati vuoti a 320/375/768/1280 px, includendo drawer mobile/invio e cambio immagine: nessun overflow orizzontale, nessuna violazione rilevata nei tag WCAG 2A/2AA/2.1AA, nessun errore JavaScript rilevato. È collaudo automatico locale, non certificazione WCAG o prova di produzione.

## Deployment e limiti

Eseguire solo `php artisan migrate --force` e build asset della stessa release, mantenendo backup/config/APP_KEY/storage e procedure deployment.md. Nessun migrate:fresh/refresh/reset o rimozione dati; test su database nuovi e isolati con infrastruttura additiva già presente. Nessun prodotto demo aggiunto al database applicativo.

PLANNED: carrello, ordini, reservation, scambi operativi e MarketplacePayments. SubscriptionBilling PRO e integrazioni store conservati; il marketplace pubblico non li usa per renderizzare prodotti. Il workflow di approvazione mantiene per ora l'export esistente: questo incremento non ne modifica il dispatch. OPEN BUSINESS DECISION: incasso/trasferimenti, IVA/fatture, rimborsi, conguagli e TTL reservation restano aperti. Quantità mostrate non sono una prenotazione o garanzia di checkout.
