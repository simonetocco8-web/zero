# Tabelle amministrative, ripristino password e logo — 22 ottobre 2026

Base: `main` dopo PR #14, commit `4471b8b54c7034693df8f51a0f28c6fbdea1e216`.

## Interfaccia

Audit recente mantiene i dieci record del controller e usa una tabella semantica.
Giacenze mantiene filtri, paginazione e verifiche esistenti; i dettagli leggono
`proposed_data ?? getAttributes()` e mostrano soltanto campi commerciali espliciti.
Foto, percorsi e payload di integrazione non vengono renderizzati. Conteggio foto,
gestione foto e conferma eliminazione sono nel dettaglio, con le policy esistenti.
Il form di conferma condiviso è riutilizzato da modal fratelli, per evitare dialog
annidati e mantenere la conferma senza cambiare il lifecycle di cancellazione.
I dialog nativi conservano focus, ESC e scorrimento interno.

Il login risolve esplicitamente le traduzioni italiane e usa fallback leggibili
per credenziali e throttle se il traduttore restituisce la chiave. Il default
applicativo è italiano, come `.env.example`; nessun `.env` viene modificato.

I due PNG trasparenti derivano dal logo originale, che era bianco/turquoise su
navy: sottrazione tecnica del fondo e lettering scuro per light. Geometria,
proporzioni e simbolo conservati. L'originale rimane disponibile. CSS dipende da
`data-theme`; nessun JavaScript aggiuntivo. La sidebar segue la superficie del
tema per garantire leggibilità anche al lettering scuro in light.

## Vincolo DB riscontrato e autorizzazione

La migration originale `2026_10_06_000004_create_sales_wallet_and_integration_records`
definisce `audit_logs.action` come ENUM, non VARCHAR. L'estensione è stata fermata
e segnalata prima dell'intervento. L'utente ha autorizzato esplicitamente
l'estensione conservativa dell'ENUM il 22 ottobre 2026.

La nuova migration aggiunge esclusivamente `retailer_password_reset`, conservando
valori, record, indici e protezioni append-only. MySQL estende l'ENUM; SQLite deve
ricostruire la tabella per estendere il CHECK generato da Laravel e ripristina
nella stessa migration tutti i trigger preesistenti. `down()` non restringe
l'ENUM, per non invalidare audit già scritti. Nessuna colonna nuova. L'allowlist
per gli aggiornamenti cPanel comprende la nuova migration. Non sono state
eseguite migration sui dati applicativi esistenti: solo database isolati di test.

## Ripristino password

POST `/admin/retailers/{retailer}/reset-password`: auth, gate amministrativo,
policy `resetPassword`, CSRF e cinque tentativi al minuto per amministratore.
La conferma espone l'indirizzo account destinatario. Il browser non sceglie la
password. L'Action genera 24 caratteri con `Str::password`, richiede entrambe le
casse e mantiene numeri/simboli garantiti dall'API. Usa il cast `hashed` di User
e azzera `remember_token`. Lock su retailer e user; audit e aggiornamento sono
nella medesima transazione, senza retry automatici della consegna esterna.

La Mailable dedicata è inviata in modo sincrono dopo la scrittura dell'audit ma
prima del commit. Se audit o trasporto falliscono, la transazione ripristina
password/token e annulla l'audit. Nessun dettaglio dell'eccezione viene riportato,
registrato o esposto. Audit before/after contiene solo `password_reset` booleano;
la password non viene restituita, salvata in sessione o messa in coda.

Sono rifiutati mailer log, trasporti sconosciuti, composizioni cicliche e
failover/roundrobin che comprendono log. Il mailer array è consentito soltanto
nei test. In produzione occorre un mailer di consegna configurato; il default
log dell'applicazione produce un errore sicuro senza cambiare password.

SMTP e database non supportano una transazione distribuita: accettazione SMTP
non garantisce recapito e un errore dopo accettazione ma prima del commit può
lasciare una mail con password inutilizzabile. Non viene promessa atomicità di
consegna. La password è definita temporanea e l'email consiglia di cambiarla;
non è introdotto un obbligo di cambio password o un nuovo lifecycle account.

Pagamenti, Stripe, wallet, commissioni, ordini, scambi, prenotazioni e regole di
pubblicazione non sono modificati. APP_KEY e MARKETPLACE_EXECUTION_MODE restano
invariati. La variabile numerica runtime marketplace non valida è stata
sostituita solo nei processi di test con una fixture numerica, senza modificare
file d'ambiente o configurazione applicativa marketplace.

## Verifiche eseguite

- `composer validate --strict`: riuscito.
- `vendor/bin/pint --test`: riuscito.
- `php artisan test` (fixture numerica di processo): SQLite, 420 test passati,
  27 saltati specifici MySQL, 2662 asserzioni.
- Suite mirata MySQL 8.4.11 (`AdminRetailerPasswordResetTest`,
  `AdminTablesAndThemeTest`, `LoginMessagesTest`, `AdminPanelTest`,
  `DomainModelTest`): 97 passati, 443 asserzioni.
- Upgrade MySQL su DB nuovo con schema precedente: valore nuovo inizialmente
  non ammesso, ENUM esteso, audit preesistenti e trigger invariati, INSERT reset
  ammesso, UPDATE/DELETE ancora bloccati.
- `npm run build`: riuscito.
- `git diff --check`: riuscito.
- Chromium + axe WCAG 2 A/AA e 2.1 AA: 60 combinazioni sulle sei pagine richieste,
  alle larghezze 375, 430, 768, 1024 e 1440, in light e dark. Nessun overflow pagina,
  errore JS o violazione axe rilevata. Tabelle con scroll interno su mobile;
  dettaglio stock con tastiera, focus, ESC, chiusura e conferma eliminazione;
  conferma reset con destinatario e annullamento; login errato e cambio tema/logo
  verificati. Controlli axe aggiuntivi eseguiti anche nei modal e dopo errore login.

I primi tentativi della suite completa erano bloccati dai socket locali nella
sandbox; la riesecuzione con accesso locale autorizzato è riuscita. MySQL è stato
avviato in un'istanza dedicata con directory dati nuova, separata da quella
preesistente. Nessuna verifica SMTP o consegna email reale dichiarata: test con
mailer fake e simulazione errore. Nessun merge o deployment effettuato.

## File modificati

- `app/Actions/RecordAdministrativeAction.php`
- `app/Enums/AdministrativeAction.php`
- `app/Http/Controllers/AdminRetailerController.php`
- `app/Http/Requests/Auth/LoginRequest.php`
- `app/Policies/RetailerPolicy.php`
- `config/app.php`
- `deployment/additive-migrations.json`
- `resources/css/app.css`
- `resources/views/admin/dashboard.blade.php`
- `resources/views/admin/retailers.blade.php`
- `resources/views/auth/login.blade.php`
- `resources/views/components/admin-confirm.blade.php`
- `resources/views/components/area-shell.blade.php`
- `resources/views/components/brand.blade.php`
- `resources/views/components/field.blade.php`
- `resources/views/components/icon.blade.php`
- `resources/views/inventory/admin.blade.php`
- `routes/web.php`

## Nuovi file

- `app/Actions/ResetRetailerPassword.php`
- `app/Mail/RetailerTemporaryPasswordMail.php`
- `database/migrations/2026_10_22_000001_allow_retailer_password_reset_audit.php`
- `docs/admin-tables-password-reset-theme.md`
- `public/brand/zero-magazzino-logo-dark.png`
- `public/brand/zero-magazzino-logo-light.png`
- `resources/views/components/admin-confirm-form.blade.php`
- `resources/views/inventory/partials/admin-detail.blade.php`
- `resources/views/mail/retailer-temporary-password.blade.php`
- `tests/Feature/AdminRetailerPasswordResetTest.php`
- `tests/Feature/AdminTablesAndThemeTest.php`
- `tests/Feature/Auth/LoginMessagesTest.php`
