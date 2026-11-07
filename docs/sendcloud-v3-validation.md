# Verifica Sendcloud v3 — 7 novembre 2026

Intervento sulla PR #28 e sul solo branch `feat/paccofacile-shipping-quotes`, partendo da `e0c4868`. Provider previsto Sendcloud v3, flag spento per default, `shipping.checkout_verified=false` invariato. Nessuna migration, configurazione di produzione, APP_KEY o `.env` reale modificati.

## Esiti locali

| Verifica | Risultato |
| --- | --- |
| Nuovi test Sendcloud su MySQL 8.4.13 | 36 casi, inclusi nella suite completa (verifica mirata iniziale: 35 superati) |
| Test Sendcloud + ShippingQuoteTest su SQLite | 64 superati, 339 asserzioni |
| Suite completa SQLite | 725 superati, 31 saltati, 4954 asserzioni |
| Suite completa MySQL 8.4.13 | 756 superati, 5256 asserzioni |
| Pint, file PHP versionati e nuovi file applicativi/test | Superato |
| Build Vite | Superata, nessuna dipendenza aggiunta |
| Route list / route:cache | Superati |
| config:cache / view:cache | Superati con percorsi cache isolati |
| schedule:list e registrazione comando diagnostico | Superati; comando soltanto manuale, non schedulato |
| Deployment/ZIP/preflight | Test del workflow ZIP e preflight inclusi nelle suite; verifica mirata SQLite: 3 superati, 5 test riservati a MySQL saltati |
| Migration/registry/manifest | Nessuna modifica a migration o registry; manifest generato e migration additiva della PR ancora autorizzata |
| git diff --check | Superato |

Database SQLite `:memory:` e file browser dedicato; MySQL su container locale isolato e database nominale `zero_sendcloud_20261107`, con ulteriori DB nuovi per i test di integrazione senza transazioni aperte. Nessun DB esistente è stato resettato o eliminato. Il test di guardia transazionale è stato verificato su entrambi i database.

I test con HTTP fake coprono:

- Basic Auth, endpoint esclusivamente v3, oggetti indirizzo, provincia ISO, dati personali non necessari omessi, assenza di filtri corriere.
- Conversioni intere/esatte, decimali stringa, più colli in una sola richiesta e totale spedizione non moltiplicato.
- Opzioni/carrier diversi, codice servizio persistito, breakdown e supplementi stimati separati, IVA sconosciuta.
- `data: []` / `null`, opzioni senza prezzo/quote_error, valute non EUR, float o precisione non rappresentabile, zero esplicito.
- 400/401/403/422/429/5xx, redirect rifiutati, timeout, JSON malformato, credenziali mancanti; errori sanitizzati e nessun retry POST.
- Limite account 80/minuto e 8/secondo condiviso tra retailer, cooldown `Retry-After`, limite locale per utente anche su cache.
- Cache dei preventivi, ordinamento a pari prezzo stabile anche dopo lettura SQL, rotazione chiavi invalidante.
- Rubrica/default e ownership, selezione e carrello multiprodotto, origini retailer separate, invalidazioni e blocco del checkout/stock.
- Scambi A→B/B→A con dati e servizi distinti, snapshot e conguaglio merce separato; nessuna accettazione economica abilitata.
- Comando diagnostico manuale con output non personale, nessuna richiesta per creare spedizioni, etichette o pagamenti.

La suite esistente continua a verificare quantità frazionarie, moderazione, import storici, legacy, IVA/commissioni, reservation e idempotenza con fake. Il fix deterministico `e0c4868` non viene rimosso: Standard ed Express rimangono verificati esplicitamente.

## Browser desktop e mobile

Playwright/Chromium, viewport 320/375/768/1280 px, applicazione e DB di test dedicati. Il bootstrap di collaudo intercetta server-side le chiamate di `SendcloudShippingProvider` con fixture OpenAPI, senza traffico Sendcloud reale; il browser non riceve le chiavi fake.

Verificati: nessuna richiesta automatica all’apertura pagina, dialogo e focus/ESC, due carrier, scelta più economica, totale ufficiale distinto da breakdown/supplementi, indicazione IVA da verificare, indirizzo alternativo e carrello coerente, blocco checkout informativo, risposta vuota e 429 con errore inline e pulsante nuovamente utilizzabile. Nessun overflow del documento o del dialogo, zero errori JavaScript e zero violazioni axe WCAG A/AA rilevate nei quattro viewport.

## Contratto verificato e collaudo reale mancante

Riferimenti, payload e mappatura sono documentati in [sendcloud-v3.md](sendcloud-v3.md). Fixture e provenienza in [tests/Fixtures/sendcloud/README.md](../tests/Fixtures/sendcloud/README.md).

Controllo configurazione effettuato senza stampare valori: Public Key assente, Private Key assente, checkout_verified falso. **Non eseguito** il collaudo autenticato con un account Sendcloud: mancano credenziali autorizzate. Non si dichiarano confermati prezzi reali, origini ammesse dai contratti, condizioni commerciali o fiscalità. La diagnostica è pronta per queste verifiche; non sono richieste chiavi nel prompt.

Non è stato chiamato alcun endpoint di creazione spedizioni, etichette, ritiri o acquisto. Nessuna nuova movimentazione wallet/Stripe e nessun pagamento reale.

## Comandi principali

```sh
# Runtime PHP 8.4 attivo, variabili solo del processo di test.
SHIPPING_QUOTES_ENABLED=false SHIPPING_PROVIDER=sendcloud \
MARKETPLACE_COMMISSION_VAT_BASIS_POINTS=0 MARKETPLACE_EXECUTION_MODE=disabled \
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3337 \
DB_DATABASE=zero_sendcloud_20261107 DB_USERNAME=root DB_PASSWORD= DB_URL= \
php artisan test --compact

SHIPPING_QUOTES_ENABLED=false SHIPPING_PROVIDER=sendcloud \
MARKETPLACE_COMMISSION_VAT_BASIS_POINTS=0 MARKETPLACE_EXECUTION_MODE=disabled \
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= CACHE_STORE=array SESSION_DRIVER=array \
php artisan test --compact

# Esclude gli scratch locali work/, assenti dal checkout CI.
git ls-files --cached --others --exclude-standard -z -- '*.php' | xargs -0 php vendor/bin/pint --test
npm run build
```

Route/config/view cache verificate con `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE` e `VIEW_COMPILED_PATH` dentro `work/sendcloud`, senza sostituire le cache dell’applicazione corrente. La CI GitHub usa il checkout pulito e il formatter completo del progetto.

## File modificati per Sendcloud

- `.env.example`
- `app/Console/Commands/DiagnoseSendcloudQuote.php`
- `app/Contracts/ShippingQuoteContextProviderInterface.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Shipping/SendcloudAccountResolver.php`
- `app/Services/Shipping/SendcloudShippingProvider.php`
- `app/Services/Shipping/ShippingQuoteService.php`
- `config/shipping.php`
- `docs/integrations.md`
- `docs/sendcloud-v3-validation.md`
- `docs/sendcloud-v3.md`
- `docs/shipping-quotes-validation.md`
- `docs/shipping-quotes.md`
- `resources/views/components/shipping-calculator.blade.php`
- `tests/Feature/SendcloudShippingIntegrationTest.php`
- `tests/Feature/SendcloudShippingProviderTest.php`
- `tests/Fixtures/sendcloud/README.md`
- `tests/Fixtures/sendcloud/shipping-options.json`
