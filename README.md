# ZeroMagazzino

Gestionale B2B per giacenze di materiale edile. Il negozio online rimane un sistema esterno.

## Bootstrap disponibile

- Laravel 13, PHP >= 8.3, MySQL 8+, Blade, Tailwind CSS 4, Alpine.js 3 e Vite 8; versioni risolte nei lockfile.
- Autenticazione Laravel Breeze: registrazione dell'account personale, login/logout, recupero e modifica password tramite password broker Laravel.
- Aree rivenditore e amministratore separate da `UserPolicy`, con pagine placeholder responsive. Il domain model è implementato; le funzioni gestionali, i pagamenti e le integrazioni reali restano da sviluppare.
- Design system Blade riutilizzabile; galleria `/design-system` disponibile solo nell'ambiente `local` e con autenticazione.

L'ambiente cloud è stato verificato con PHP 8.4.24, Laravel 13.35.0, MySQL 8.4.11 e Node.js 24.19.0. Servono Composer 2, Node.js compatibile con Vite 8 (20.19+ oppure 22.12+), npm e MySQL 8+. Per il database Docker opzionale servono Docker e Compose v2.

Estensioni PHP: quelle richieste da Laravel e Composer, più `pdo_mysql`; per la suite rapida `pdo_sqlite`, `sqlite3`, `dom`, `xml`, `xmlwriter`, `mbstring`. `composer check-platform-reqs` verifica i requisiti delle dipendenze installate.

## Installazione

Dalla root del checkout:

```sh
composer install
cp .env.example .env
php artisan key:generate
npm ci
npm run build
```

Copiare `.env.example` solo se `.env` non esiste. Non rigenerare `APP_KEY` su un ambiente già configurato: invalida sessioni e dati cifrati.

Configurare privatamente `.env`: `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Il default applicativo è MySQL; SQLite è usato solo nella suite rapida. Nessuna credenziale viene distribuita nel repository.

È disponibile un servizio MySQL locale in `compose.yaml`. Per usarlo configurare anche `MYSQL_ROOT_PASSWORD` in `.env`, scegliere un utente applicativo diverso da root, quindi:

```sh
docker compose up -d --wait mysql
php artisan migrate
php artisan db:seed --class=PlanSeeder
```

Il database è esposto solo su loopback. Il container è di sviluppo e disabilita il binlog per permettere la creazione dei trigger senza privilegi SUPER; in produzione usare un utente di deployment autorizzato mantenendo replica/PITR. Il volume `zero-mysql-data` conserva i dati sul daemon Docker corrente: non è un backup e non è garantito che sia incluso negli snapshot cloud. Le variabili `MYSQL_*` inizializzano un volume vuoto; cambiarle non cambia gli utenti di un database già esistente. Non usare `down -v` per riavviare.

Con MySQL già fornito dall'infrastruttura, omettere Docker e usare le relative variabili `.env`. `composer setup` installa dipendenze, crea `.env` se assente, genera una chiave solo se manca, esegue migration e build; richiede comunque il database e le credenziali già configurati.

## Avvio

In due terminali dalla root:

```sh
php artisan serve --no-reload
npm run dev
```

La build `npm run build` consente di usare il server senza Vite in esecuzione. `composer dev` avvia il server PHP; Vite si avvia separatamente. Non sono necessari worker/scheduler per le pagine placeholder attuali.

Nella macchina cloud preparata, prima dei comandi PHP/Composer:

```sh
export PATH=/workspace/runtime/bin:$PATH
export COMPOSER_HOME=/workspace/runtime/composer
export COMPOSER_CACHE_DIR=/workspace/runtime/composer-cache
export npm_config_cache=/workspace/runtime/npm-cache
cd /workspace/zero
```

PHP e Composer sono installati fuori dal checkout. Il Composer locale usa un plugin di rete che scarica gli archivi GitHub dallo stesso commit attraverso `codeload.github.com`, perché `api.github.com` è bloccato. TLS e verifiche dei pacchetti restano attivi; nessuna dipendenza applicativa usa questo plugin. `--no-reload` conserva l'attivazione del runtime PHP nei processi figli.

## Account e autorizzazione

La registrazione pubblica crea esclusivamente utenti `retailer`, anche se il client invia un ruolo diverso. La registrazione aziendale e la verifica amministrativa arriveranno in una fase successiva. Le pagine sono ora accessibili dopo login, senza requisito di approvazione aziendale o verifica email; gli endpoint standard di verifica email sono presenti, ma non costituiscono il workflow di verifica azienda.

Non esistono account o password predefiniti. Per rendere amministratore un account già registrato, un operatore con accesso fidato al server può usare `php artisan tinker`:

```php
$user = App\Models\User::where('email', 'indirizzo-account@example.test')->firstOrFail();
$user->role = App\Enums\UserRole::Admin;
$user->save();
```

Sostituire l'indirizzo con quello dell'account da abilitare. Il ruolo non è mass assignable e non ha endpoint pubblico di modifica. La dashboard inoltra alla propria area; l'altra area restituisce 403.

## Email e sicurezza

In sviluppo `MAIL_MAILER=log`: le email, inclusi i link di recupero, sono scritte nel log locale ignorato da Git. Non condividere quei log. Prima di un rilascio configurare un trasporto email reale tramite `.env`; la consegna SMTP esterna non è stata verificata in questo bootstrap.

In produzione: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` HTTPS e `SESSION_SECURE_COOKIE=true`. Il default Secure è attivo in produzione; per HTTP locale impostare `SESSION_SECURE_COOKIE=false`. Cookie HttpOnly/SameSite, rigenerazione sessione, hashing password, rate limiting e middleware CSRF standard sono presenti. `.env`, log, dipendenze e asset generati sono esclusi da Git.

## Verifica

```sh
php artisan test
php vendor/bin/pint --test
composer validate --strict
npm run build
```

La suite rapida usa SQLite in memoria, sessioni/cache in memoria e notifiche simulate. Copre pagine pubbliche, tutte le route private, ruoli, escalation via registrazione, login/logout, rate limit, CSRF e password reset con token valido/errato.

Per eseguire la stessa suite su MySQL usare **un database dedicato e sacrificabile**, mai quello applicativo o di produzione. Provisionarlo con un utente autorizzato alle migration, poi impostare le variabili di connessione nel processo:

```sh
DB_CONNECTION=mysql DB_DATABASE=zeromagazzino_test php artisan test
```

Il comando sopra riusa host e credenziali locali di `.env`; fornire anche `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` tramite configurazione sicura se diversi. `RefreshDatabase` può ricreare lo schema del database di test.

Regole permanenti: [AGENTS.md](AGENTS.md). Specifiche: [requirements](docs/requirements.md), [architecture](docs/architecture.md), [database](docs/database.md), [UI/UX](docs/ui-ux.md), [integrations](docs/integrations.md).

## Domain model e piani

Dettagli in [docs/database.md](docs/database.md). Importi `_cents` interi, percentuali in basis point e quantità `quantity_milliunits` intere con accessor `quantity` a tre decimali. Le factory coprono tutte le entità; `DatabaseSeeder` esegue esclusivamente `PlanSeeder`, senza account demo. Il seed è ripetibile e preserva piani già personalizzati.

FREE: 5 articoli, 10.000 € di magazzino, niente scambio, commissione 2%. PRO: 69 €/mese o 499 €/anno, limiti NULL (illimitati), scambio e commissione 0,5%. I default sono in `config/plans.php`; il runtime legge le righe `plans`. Le quote non sono ancora applicate nei placeholder.

Il ledger e gli audit sono append-only anche a livello DB. Il deploy delle nuove migration richiede CHECK supportati (MySQL 8.0.16+) e privilegi per i trigger; non omettere la migration dei vincoli. I test su MySQL devono continuare a usare il database dedicato, mai quello applicativo.
