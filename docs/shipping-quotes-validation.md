> **Storico della prima implementazione Paccofacile/fake.** Per il passaggio corrente a Sendcloud v3 e i nuovi collaudi consultare [sendcloud-v3-validation.md](sendcloud-v3-validation.md). I comandi e i risultati sotto si riferiscono alla revisione precedente.

# Verifica quotazioni spedizioni — 7 novembre 2026

Tutti i database usati sono nuovi/isolati o transazionali di test. Nessuna chiamata di pagamento o spedizione reale, nessun database produzione, nessun comando distruttivo. PHP 8.4.26; SQLite; MySQL 8.4.13 in container dedicato sulla porta locale 3337. Le variabili marketplace del runtime sono sovrascritte soltanto nei processi di test con valori fake/disabilitati; `.env` e APP_KEY reali inalterati.

## Risultati

| Controllo eseguito | Esito |
| --- | --- |
| Suite completa SQLite | 688 superati, 31 saltati, 4716 asserzioni. I salti comprendono i test di concorrenza/vincoli esclusivi MySQL. |
| Suite completa MySQL | 719 superati, 5018 asserzioni. |
| Concorrenza checkout dinamico MySQL | 1 superato, 10 asserzioni: due processi simultanei, un ordine/pagamento/reservation, una tariffa consumata. Incluso nella suite MySQL finale. |
| Regressione finale ShippingQuoteTest + MarketplaceCartOrderTest + ExchangePresentationTest | 48 superati, 324 asserzioni. |
| Regressione import e quotazioni dopo controllo di compatibilità | 64 superati, 279 asserzioni. |
| Regressione finale presentazione scambi/giacenze | 28 superati, 301 asserzioni. |
| Nuovi test ShippingQuoteTest | 27 superati, 101 asserzioni, anche su SQLite. |
| Laravel Pint sui file modificati | Superato, controllo finale `--dirty --test`. |
| Vite produzione | Superato (Vite 8.3.3). Nessuna nuova dipendenza. |
| Route list e route:cache | Superati; endpoint vendita/scambio sotto middleware esistenti e throttle. |
| config:cache e view:cache | Superati con file cache isolati in `work/`, senza sostituire la cache dell’applicazione corrente. |
| schedule:list | Superato: pulizia quote giornaliera registrata. |
| Manifest ZIP/registry migration | Generato, nuova migration registrata; migration storiche senza diff. Test release/deployment compresi nelle suite. |
| git diff --check | Superato. |

## Browser autentico, dati esclusivamente fittizi

Playwright + Chromium, applicazione Laravel locale con DB SQLite dedicato e provider fake consentito solo in ambiente `testing`; nessuna credenziale provider reale. Le verifiche monetarie complete sono nei test Laravel, dove l’autorizzazione fiscale di fixture è impostata soltanto in memoria.

- Viewport 320, 375, 768 e 1280 px: prodotto e dialogo senza overflow orizzontale; nessuna richiesta di quotazione al caricamento.
- Apertura popup, indirizzo predefinito, richiesta esplicita, due corrieri TEST e soluzione più economica.
- Chiusura ESC e restituzione focus al pulsante iniziale; dialogo HTML nativo per navigazione/focus modale.
- axe WCAG 2 A/AA e 2.1 AA: zero violazioni rilevate nei quattro viewport; zero errori JavaScript.
- Destinazione alternativa, selezione tariffa, aggiunta al carrello con stessa destinazione visibile; nessun salvataggio implicito della rubrica.
- Checkout informativo bloccato esplicitamente prima di pagamento/prenotazione.
- Risposta vuota e risposta HTTP 429 iniettate via intercettazione browser: messaggio inline e ripristino del pulsante. Queste risposte non rappresentano lo schema Paccofacile.
- Form scambi: calcolo distinto A→B e B→A, due alternative per lato, scelta servizi e creazione proposta con i riferimenti. Il collaudo ha identificato e corretto l’uso del riferimento al form nel codice Alpine.

Una prima esecuzione di verifica finale aveva ereditato le impostazioni del DB browser; è stata esclusa dagli esiti e ripetuta con SQLite `:memory:` e flag di base espliciti. Il controllo Pint è stato eseguito separatamente dalle variabili di cache isolate Laravel. Questi tentativi non indicano collaudi superati e non hanno toccato dati di produzione.

## Comandi di riferimento

```sh
# Ambiente PHP attivato localmente; config marketplace solo di test.
SHIPPING_QUOTES_ENABLED=false SHIPPING_PROVIDER=paccofacile \
MARKETPLACE_COMMISSION_VAT_BASIS_POINTS=0 MARKETPLACE_EXECUTION_MODE=disabled \
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= CACHE_STORE=array SESSION_DRIVER=array \
php artisan test --compact

# Usare esclusivamente un database dedicato e nuovo, mai un DB produzione.
SHIPPING_QUOTES_ENABLED=false SHIPPING_PROVIDER=paccofacile \
MARKETPLACE_COMMISSION_VAT_BASIS_POINTS=0 MARKETPLACE_EXECUTION_MODE=disabled \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3337 \
DB_DATABASE=zero_shipping_release_20261107b DB_USERNAME=root DB_PASSWORD= DB_URL= \
php artisan test --compact

php vendor/bin/pint --dirty --test
npm run build
# Route/config/view cache: usare percorsi APP_CONFIG_CACHE, APP_ROUTES_CACHE e
# VIEW_COMPILED_PATH dedicati al collaudo, come nella verifica eseguita.
```

## Copertura e limiti

Coperti: nuovi dati e moderazione, precisione e numeri invalidi, CSV completo con mapping/fissi e conversioni, prosecuzione import precedenti, origine senza CAP, default/alternativo/assenza indirizzi, cache e multi-tariffa, nessun prezzo inventato su risposta vuota/malformata, multipacco e frazioni esatte, gruppo multiprodotto, multi-vendor con pickup, IVA e commissioni, scadenza e mutazioni, tampering e ownership, checkout/snapshot/idempotenza, concorrenza stock MySQL, scambi distinti e controproposta immutabile, compatibilità storica.

**Non testato né attivo:** autenticazione, schema, errori e tariffe ufficiali Paccofacile, validità contrattuale, limiti e territori, sandbox ufficiale, fatturazione/incasso reale del trasporto. Non sono stati inventati endpoint o payload. Timeout/retry HTTP reali e contract test vanno implementati dopo il contratto ufficiale; l’adapter attuale è deliberatamente chiuso e verificato con `Http::fake()` / `Http::assertNothingSent()`.

Questa PR abilita l’architettura e la verifica locale; non certifica la release per incassi dinamici in produzione. Checklist di attivazione e rollback in [shipping-quotes.md](shipping-quotes.md).

## File modificati

- `.env.example`
- `app/Actions/CreateMarketplaceOrder.php`
- `app/Actions/SaveInventoryItem.php`
- `app/Actions/UpdateRetailerProfile.php`
- `app/Contracts/ShippingQuoteProviderInterface.php`
- `app/Http/Controllers/CartController.php`
- `app/Http/Controllers/CheckoutController.php`
- `app/Http/Controllers/ExchangeShippingQuoteController.php`
- `app/Http/Controllers/ShippingQuoteController.php`
- `app/Http/Requests/CartItemRequest.php`
- `app/Http/Requests/ExchangeProposalRequest.php`
- `app/Http/Requests/InventoryRequest.php`
- `app/Http/Requests/RetailerCompanyRequest.php`
- `app/Http/Requests/ShippingQuoteRequest.php`
- `app/Models/CartItem.php`
- `app/Models/InventoryItem.php`
- `app/Models/Retailer.php`
- `app/Models/ShippingQuote.php`
- `app/Policies/ShippingQuotePolicy.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/ExchangeRules.php`
- `app/Services/ExchangeWorkflow.php`
- `app/Services/InventoryImport/ImportFields.php`
- `app/Services/InventoryImport/ImportWorkflow.php`
- `app/Services/InventoryRules.php`
- `app/Services/MarketplaceCart.php`
- `app/Services/MarketplaceQuote.php`
- `app/Services/Shipping/FakeShippingProvider.php`
- `app/Services/Shipping/PaccofacileShippingProvider.php`
- `app/Services/Shipping/ShippingPackaging.php`
- `app/Services/Shipping/ShippingParcelBuilder.php`
- `app/Services/Shipping/ShippingQuoteService.php`
- `config/shipping.php`
- `database/migrations/2026_11_07_000001_add_shipping_quotation_foundations.php`
- `deployment/additive-migrations.json`
- `docs/integrations.md`
- `docs/shipping-quotes-validation.md`
- `docs/shipping-quotes.md`
- `resources/views/cart/checkout.blade.php`
- `resources/views/cart/index.blade.php`
- `resources/views/components/company-fields.blade.php`
- `resources/views/components/inventory/product-version.blade.php`
- `resources/views/components/layouts/retailer.blade.php`
- `resources/views/components/shipping-calculator.blade.php`
- `resources/views/exchanges/form.blade.php`
- `resources/views/inventory/form.blade.php`
- `resources/views/marketplace/show.blade.php`
- `resources/views/retailer/profile.blade.php`
- `routes/console.php`
- `routes/web.php`
- `tests/Feature/ShippingCheckoutConcurrencyTest.php`
- `tests/Feature/ShippingQuoteTest.php`
