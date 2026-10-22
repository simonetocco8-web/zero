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
password. L'Action genera 24 caratteri con `Str::password` e verifica
esplicitamente almeno una minuscola, una maiuscola, un numero e un simbolo.
Se una categoria manca, scarta il candidato e ripete la generazione. Usa il cast `hashed` di User
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
- `php artisan test` (fixture numerica di processo): SQLite, 421 test passati,
  28 saltati specifici dei database reali, 2678 asserzioni.
- Suite mirata MySQL 8.4.11 (`AdminRetailerPasswordResetTest`,
  `AdminTablesAndThemeTest`, `LoginMessagesTest`, `AdminPanelTest`,
  `DomainModelTest`, `AdminAuditMigrationUpgradeTest`): 99 passati, 475 asserzioni.
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

## Revisione successiva — password e MariaDB 10.11.19

Il controllo password verifica esplicitamente tutti e quattro i pattern, mantenendo
24 caratteri. Il test deterministico sostituisce soltanto la sorgente dei candidati:
fornisce quattro password da 24 caratteri prive rispettivamente di minuscole,
maiuscole, numeri e simboli, poi una completa. Richiede esattamente cinque
estrazioni e verifica che solo la quinta venga salvata (hashata) e inviata.
Il test con generazione reale controlla inoltre la lunghezza esatta di 24 caratteri.

Test eseguito su `10.11.19-MariaDB-ubu2204`, immagine ufficiale
`mariadb:10.11.19`, digest
`sha256:0d0945c57f75b3a3b9ebe71aedaaad005fa5305054e8fca3d2e42a8c92b43b5e`.
Connessione Laravel `mysql` a un server MariaDB reale, con volume e database nuovi.
Nessun dato applicativo esistente utilizzato o modificato.

`AdminAuditMigrationUpgradeTest` ricrea le migration originali fino a
`2026_10_06_000005_add_domain_constraints`: definiscono esattamente la tabella
`audit_logs`, le dipendenze e i trigger presenti prima della nuova migration.
Nessuna migration intermedia modifica tale schema audit. Non è una simulazione
SQLite: il test viene eseguito su entrambi i motori reali, MySQL e MariaDB.

Verifiche MariaDB riuscite (test upgrade dedicato: 1 test, 16 asserzioni):

- tre audit preesistenti, confrontati integralmente prima/dopo;
- ENUM originale esatto, senza `retailer_password_reset`, con inserimento nuovo
  inizialmente rifiutato;
- presenza dei trigger UPDATE e DELETE prima dell'upgrade;
- esecuzione della migration originale del 22 ottobre, senza modificarla;
- tutti gli indici conservati (nomi, colonne, unicità, tipo e proprietà);
- trigger conservati (nomi, evento, timing, corpo e definer);
- ENUM finale uguale all'originale più il solo valore aggiunto, nello stesso ordine;
- inserimento del reset ammesso; UPDATE e DELETE raw ancora rifiutati;
- nessuna rimozione o riscrittura dello storico.

È emersa una limitazione preesistente della **catena completa da database vuoto**
su MariaDB: `2026_10_16_000005_add_retailer_credit_funding` usa `DROP CHECK`,
sintassi MySQL che MariaDB 10.11 non accetta. I primi tentativi della suite ampia
si sono fermati durante quel setup, prima di testare la migration audit.
Quella migration economica non è stata modificata in questa revisione.

Per completare anche i test applicativi MariaDB, è stato ripristinato in un altro
DB nuovo uno snapshot SQL delle sole fixture MySQL (schema, trigger e dati di
bootstrap, inclusa la cronologia migration), senza modifiche al dump. Su questo
schema la suite mirata composta da `AdminAuditMigrationUpgradeTest`,
`AdminRetailerPasswordResetTest`, `AdminTablesAndThemeTest`, `LoginMessagesTest`,
`AdminPanelTest` e `DomainModelTest` passa: **99 test, 475 asserzioni**.
Il test upgrade della stessa suite usa comunque un ulteriore DB separato e
ricostruisce da zero lo schema audit originale sopra descritto. Questi risultati
non certificano che l'intera catena di migration storiche sia compatibile MariaDB.

Sono stati rieseguiti con successo Composer strict, Pint, suite completa SQLite
(421 passati, 28 saltati, 2678 asserzioni), suite mirata MySQL 8.4.11 (99 passati,
475 asserzioni), build Vite e `git diff --check`.

Il finding che propone card mobile è stato discusso e risolto nella PR #15:
il requisito funzionale esplicito impone tabelle anche su mobile, con scroll
orizzontale interno e nessun overflow pagina, e prevale sulla raccomandazione
generica in AGENTS.md. Nessuna view, tabella o regola CSS è stata modificata qui.

All'inizio della revisione la PR #15 risultava già merged da GitHub, con merge
commit `a7faf4c64696dcab774445cbe297919a46979b09`. L'utente ha autorizzato una nuova
PR dallo stesso branch per queste correzioni. Questa revisione non esegue merge.

File di questa revisione: `app/Actions/ResetRetailerPassword.php`,
`tests/Feature/AdminRetailerPasswordResetTest.php`,
`tests/Feature/AdminAuditMigrationUpgradeTest.php` (nuovo),
`docs/admin-tables-password-reset-theme.md`.
