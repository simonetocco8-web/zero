# ZeroMagazzino

Gestionale web B2B per rivenditori di materiale edile con giacenze da vendere. Laravel serve le aree rivenditore/amministratore e il form pubblico di richiesta disponibilità. Catalogo, carrello e checkout dei prodotti appartengono al **negozio esterno**; questo repository non implementa un ecommerce.

## Architettura e funzionalità

Monolite Laravel 13 con Blade, Tailwind CSS 4, Alpine.js 3 e Vite 8. Controllers sottili, Form Requests, Models/Enums, Policies, Actions/Services transazionali e Jobs. Denaro in centesimi interi, percentuali in basis point, quantità in millesimi interi. Ledger e audit append-only, vincoli/lock MySQL e receipt idempotenti proteggono gli effetti finanziari.

| Area | Funzioni implementate |
| --- | --- |
| Account | Registrazione azienda/account e scelta piano, login/logout, reset password, profilo e IBAN cifrato/mascherato |
| Rivenditore | Dashboard, stato di verifica, giacenze e foto, proposte di modifica separate dalla versione pubblicata, richieste ricevute, credito e richiesta bonifico |
| Amministratore | Approva/rifiuta/sospende rivenditori; approva/rifiuta giacenze; esito bonifici esterni, audit, filtri e paginazione |
| Cliente | Form pubblico per prodotti pubblicati, consenso privacy, antispam e richieste di disponibilità |
| Piani | FREE: 5 articoli, 10.000 € di magazzino, commissione 2%; PRO: illimitato, scambio, commissione 0,5%, 69 €/mese oppure 499 €/anno |
| Vendite/wallet | Vendite normalizzate, commissione snapshot, rimborsi idempotenti, saldo dal ledger, riserva/rilascio/pagamento payout |
| Stripe | SDK ufficiale, Checkout ricorrente, webhook firmati, aggiornamenti/insoluti/cancellazioni, queue e riconciliazione |
| Negozio | StoreGatewayInterface, FakeStoreGateway e DTO/eventi normalizzati; provider reale ancora da scegliere/implementare |

PRO pagato non approva l’azienda: pending/rejected/suspended non possono usare le funzioni operative. Il ritorno da Checkout non prova il pagamento. Le caratteristiche dei piani provengono dalle righe `plans`, inizializzate da `config/plans.php`; il seed non sovrascrive personalizzazioni. Bonifici bancari e impiego del credito al checkout rimangono esterni/non implementati. La pagina Impostazioni amministratore è ancora un placeholder; non sono presenti Customer Portal Stripe o cancellazione richiesta dal cliente nel gestionale.

## Requisiti effettivi

- **PHP >= 8.4.1** per le dipendenze nel lockfile corrente, CLI e PHP-FPM coerenti. Laravel richiede almeno 8.3, ma Symfony 8.1 risolto nel progetto richiede 8.4.1: PHP 8.3 non può installare questo lockfile.
- Composer 2. Usare `composer install`, non `update`, per distribuire le versioni fissate.
- MySQL **8.0.16+**, consigliato 8.4, con CHECK applicati e privilegi per creare trigger. MariaDB non è stata collaudata come sostituzione.
- Node.js **24 LTS** e npm per `npm ci && npm run build`; Node non serve sul server se gli asset vengono compilati in CI/localmente e distribuiti insieme alla release.
- PHP: ctype, curl, dom, fileinfo, filter, hash, iconv, json, mbstring, openssl, PDO/pdo_mysql, session, tokenizer, xml/xmlwriter e zip; pdo_sqlite/sqlite3 per la suite SQLite. pcntl necessario sulla CLI dei worker per rispettare i timeout; intl/bcmath facoltativi. Alcune estensioni sono native. `composer check-platform-reqs` verifica quelle richieste dai pacchetti; fileinfo è necessario agli upload.
- ImageMagick (`convert`) con JPEG/PNG/WebP e `proc_open` disponibile. Configurare `INVENTORY_IMAGE_BINARY` al percorso del server. Permettere upload da 15 MB, corpo HTTP da 128 MB, 8 file e directory temporanea scrivibile; usare limiti ImageMagick e runtime aggiornato.
- HTTPS, trasporto mail reale per i reset, worker persistente e scheduler ogni minuto. Cache database/Redis condivisa per lock Stripe, non `array` in produzione.

Verificato in cloud con PHP 8.4.24, Composer 2.8.8, MySQL 8.4.11 e Node 24.19.0. Requisiti e procedure Linux/cPanel dettagliati in [docs/deployment.md](docs/deployment.md).

## Installazione locale

Dalla root del repository:

```sh
composer install --prefer-dist --no-interaction
# Solo se .env non esiste; non sovrascrivere configurazioni esistenti.
test -f .env || cp .env.example .env
# Solo la prima volta, quando APP_KEY è vuota:
php artisan key:generate
npm ci
npm run build
```

Configurare privatamente `.env` con database/utente/password reali del proprio ambiente. Non rigenerare `APP_KEY` negli aggiornamenti: protegge IBAN, payload e altri dati cifrati. Nessun account, password amministrativa, API key o credenziale viene distribuito nel repository.

Con MySQL già disponibile, creare il database e un utente autorizzato alle migration. Per il solo database locale Docker, valorizzare anche `MYSQL_ROOT_PASSWORD` e avviare:

```sh
docker compose up -d --wait mysql
php artisan migrate
php artisan db:seed --class=PlanSeeder
```

Il container è di sviluppo, raggiungibile su loopback, con binlog disabilitato; non usarlo come ricetta di produzione. `MYSQL_*` inizializza soltanto volumi vuoti. Il volume `zero-mysql-data` non è un backup e non è garantito nello snapshot cloud. Non usare `down -v` per riavviare.

`DatabaseSeeder` chiama solo `PlanSeeder`, senza dati demo. `composer setup` offre un’installazione aggregata ma richiede DB/configurazione già disponibili: non è una procedura di rilascio in produzione.

## Avvio in sviluppo

```sh
php artisan serve --no-reload
# Terminale separato, se serve hot reload al posto degli asset compilati:
npm run dev
# Terminali/processi separati per gli eventi asincroni:
php artisan queue:work database --queue=integrations,default --sleep=3 --timeout=45 --tries=5
php artisan schedule:work
```

Adattare la connessione worker a `INTEGRATIONS_QUEUE_CONNECTION`. Worker e scheduler sono necessari per i webhook anche se il gateway store resta fake. In produzione usare process supervisor e cron, non `artisan serve`, Vite dev server o `schedule:work` come servizi HTTP.

Nella macchina cloud preparata, il runtime è fuori dal checkout:

```sh
export PATH=/workspace/runtime/bin:$PATH
export COMPOSER_HOME=/workspace/runtime/composer
export COMPOSER_CACHE_DIR=/workspace/runtime/composer-cache
export npm_config_cache=/workspace/runtime/npm-cache
cd /workspace/zero
```

Il Composer cloud usa un plugin di trasporto locale per gli archivi GitHub del medesimo commit via codeload; non è una dipendenza applicativa o un requisito di produzione. TLS/verifiche restano attivi. Le attività cloud usano il checkout isolato esistente; nessun worktree necessario.

## Account amministratore

Registrare un account con password scelta privatamente. Un operatore fidato con accesso al server può assegnargli il ruolo da `php artisan tinker`:

```php
$user = App\Models\User::where('email', 'account-operatore@example.com')->firstOrFail();
$user->role = App\Enums\UserRole::Admin;
$user->save();
```

Sostituire l’indirizzo con l’account autorizzato. Nessun endpoint pubblico assegna ruoli. Verifica email e approvazione aziendale sono processi diversi; le operazioni richiedono azienda approved e piano attivo, secondo Policy.

## Test, formatter e CI

```sh
composer validate --strict
composer check-platform-reqs
vendor/bin/pint --test
npm ci
npm run build
php artisan test --compact --no-ansi
```

La suite predefinita usa SQLite in memoria, sessioni/cache in memoria e trasporti fake. I sei test specifici MySQL sono saltati esplicitamente. Per vincoli e concorrenza eseguire la stessa suite su **database dedicato e sacrificabile**:

```sh
DB_CONNECTION=mysql DB_DATABASE=zeromagazzino_test php artisan test --compact --no-ansi
```

Provisionare prima questo database e fornire host/utente/password nel processo se diversi da `.env`. Mai usare il database applicativo o di produzione: la suite ricrea lo schema. Non eseguire contemporaneamente le due suite sullo stesso checkout/database. Elenco test, copertura, migration e risultati finali in [docs/testing.md](docs/testing.md) e [docs/release-readiness.md](docs/release-readiness.md).

[GitHub Actions](.github/workflows/ci.yml) esegue installazione Composer dal lockfile, controllo piattaforma, formatter completo, `npm ci`/build, suite MySQL con concorrenza, suite SQLite e verifica cache config/route/view/scheduler. Runner/database effimeri, APP_KEY generata durante il job, azioni fissate a commit e permessi `contents: read`; nessun secret di produzione. Il job non distribuisce l’app. PHPStan/Psalm non configurati.

## Distribuzione e operatività

Seguire [la procedura Linux/cPanel](docs/deployment.md), [le variabili ambiente](docs/environment.md) e [la checklist pre-produzione](docs/pre-production-checklist.md).

Punti essenziali:

- Document root **esclusivamente `<app>/public`**, codice e `.env` fuori dalla directory pubblica; PHP-FPM e CLI sulla stessa versione.
- `.env` privato, `APP_ENV=production`, `APP_DEBUG=false`, URL HTTPS, cookie Secure, SMTP reale. Back up della chiave di cifratura separato e protetto.
- `composer install --no-dev --optimize-autoloader`, asset `public/build`, migration e `PlanSeeder`; mai `migrate:fresh` o seed demo sul server reale.
- Solo `storage` e `bootstrap/cache` scrivibili all’utente applicativo; `.env` leggibile solo agli utenti necessari. Mai `chmod 777`.
- `storage:link` riguarda solo `storage/app/public`: **non** esporre `storage/app/private` o l’intera `storage/app`. Le foto delle giacenze sono private, con route autorizzata, e non richiedono questo link.
- Cache config/route/view dopo la configurazione, restart dei worker dopo il rilascio; connessione queue durevole e cron ogni minuto.
- Backup verificati di DB, upload privati e configurazione/APP_KEY; non basta conservare il catalogo Git.

## Integrazioni e recupero

Configurare Stripe nell’account corretto con prezzi ricorrenti EUR da 69 €/mese e 499 €/anno e endpoint HTTPS `/webhooks/stripe`. Firma, eventi richiesti, idempotenza, semantica insoluti/cancellazioni e tutorial per provider store in [docs/integrations.md](docs/integrations.md).

`STORE_DRIVER=fake` mantiene il negozio simulato. Il protocollo `/webhooks/store/fake` è solo un adapter di test e resta disabilitato senza `STORE_WEBHOOK_SECRET`; non sostituisce un provider ecommerce reale. Prima della produzione concordare mapping, disponibilità, tasse, spedizioni, rimborsi e riconciliazione.

```sh
php artisan queue:failed
php artisan integrations:retry
php artisan billing:sync
php artisan store:sync
# Solo dopo diagnosi, con ID locale della receipt:
php artisan integrations:retry --event=ID_LOCALE --force
```

Non cancellare receipt, ledger o audit per ripetere operazioni. Monitorare errori, eventi esauriti, backlog e disponibilità di cron/worker/mail. `MAIL_MAILER=log` è solo per sviluppo: contiene link di reset e non va usato in produzione.

Regole: [AGENTS.md](AGENTS.md). Specifiche: [requirements](docs/requirements.md), [architecture](docs/architecture.md), [database](docs/database.md), [UI/UX](docs/ui-ux.md), [audit](docs/audit.md).

## cPanel senza SSH o Terminale

Per la sola prima installazione tramite File Manager: [procedura cPanel senza CLI](docs/cpanel-no-ssh-deployment.md). Il workflow manuale **Build deploy package** genera `zeromagazzino-deploy.zip` con vendor e asset; un installer web temporaneo, disabilitato senza `ZERO_INSTALL_TOKEN`, esegue migration/PlanSeeder/cache e deve essere eliminato dopo l’uso. Queue e scheduler richiedono comunque un servizio del provider; il pacchetto non esegue deployment automatici.
