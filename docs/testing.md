# Test, CI e inventario delle migration

## Esecuzione

PHP 8.4.1+, Composer installato dal lockfile, ImageMagick JPEG/PNG/WebP e asset Vite disponibili. La suite usa PHPUnit, non richiede Stripe/ecommerce reali e genera credenziali/firme solo per i test.

```sh
composer install --prefer-dist --no-interaction
composer validate --strict
composer check-platform-reqs
vendor/bin/pint --test
npm ci
npm run build
php artisan test --compact --no-ansi
DB_CONNECTION=mysql DB_DATABASE=zeromagazzino_test php artisan test --compact --no-ansi
```

MySQL deve essere un database **dedicato e sacrificabile**, provisionato prima, con credenziali/host configurati nel processo o nel .env privato. I test ricreano lo schema, inclusi trigger. Mai puntare al database applicativo/produzione. Non lanciare le due suite contemporaneamente sullo stesso checkout: oltre al DB, alcuni test usano file/storage temporanei.

SQLite è una verifica rapida; 6 casi MySQL (2 inventario, 3 finanza, 1 Checkout) sono saltati. MySQL esegue anche quei casi con due processi reali e lock; una sola suite SQLite non garantisce la prevenzione dei payout simultanei.

## Matrice di copertura feature

| Requisito | File / verifiche |
| --- | --- |
| Registrazione/login/logout | Auth/RegistrationTest, Auth/AuthenticationTest; registrazione aziendale e ruolo forzato retailer in RetailerFlowTest e SmokeTest |
| Password reset/aggiornamento/verifica | Auth/PasswordResetTest, PasswordUpdateTest, PasswordConfirmationTest, EmailVerificationTest; token validi/errati, password e firma |
| Route non autorizzate | SmokeTest e AdminPanelTest su letture/azioni; AvailabilityFlowTest e InventoryFlowTest su ownership |
| Pending/approved/rejected, profilo ownership | RetailerFlowTest: stati su tutte le route operative, dati filtrati per proprietario, IBAN valido/mascherato/non flash |
| FREE max 5/max 10.000 €, modifica oltre quota | InventoryFlowTest; InventoryConcurrencyTest con richieste simultanee su MySQL |
| PRO oltre limiti FREE, scambio solo PRO | InventoryFlowTest::test_pro_has_no_limits_and_allows_exchange e richiesta FREE manipolata rifiutata |
| Giacenza create/edit/approval/rejection | InventoryFlowTest e AdminPanelTest: draft/pending/published/rejected, review idempotente e azienda approved |
| Modifica pubblicata | InventoryFlowTest: dati/foto approvati invariati fino a review, proposta rifiutata, quote riservate |
| Foto | InventoryFlowTest: MIME reale, dimensione, resize 1800, JPEG, EXIF, WebP, ownership/cleanup; SecurityUxAuditTest per percorso e limite nel servizio |
| Richieste: create/privacy/status/ownership | AvailabilityFlowTest: consenso, sanitizzazione, honeypot/rate/CSRF, solo prodotti pubblici, cambio del solo stato, paginazione e dashboard scoped |
| Vendite FREE/PRO e commissione snapshot | SaleAccountingTest: lordo/sconto/commissione/netto, arrotondamenti e cambio piano senza ricalcolo storico |
| Rimborsi/idempotenza | SaleAccountingTest, ExternalIntegrationsTest e FinanceConcurrencyTest: rimborso parziale/cumulativo, duplicati, collisioni e concorrenza |
| Wallet credito/payout/rigetto | SaleAccountingTest e AdminPanelTest: saldo/maturazione/riserva, pagamento esterno singolo, rigetto/rilascio, credito insufficiente, ownership |
| Payout concorrente | FinanceConcurrencyTest, dataset payout, due processi MySQL; uno solo crea richiesta/riserva |
| Admin accesso/approvazioni | AdminPanelTest e RetailerFlowTest: auth/ruolo, approvazione/rifiuto/sospensione, conferme, audit, filtri, IBAN mascherato |
| Stripe mocked webhook/SDK | ExternalIntegrationsTest, StripeSdkGatewayTest: firma/tempo, Checkout ricorrente, active/updated/cancelled/payment failed, replay, queue/retry e indipendenza dall’approvazione |
| Checkout concorrente | BillingConcurrencyTest: stesso intento e chiave idempotenza per invii simultanei |
| FakeStoreGateway | AdminPanelTest e ExternalIntegrationsTest: create/update/publish/archive, ID/revisioni, failure/retry e blocco di provider sconosciuto |
| Schema/enum/constraint | DomainModelTest: relazioni, foreign key, unique, valori esatti, ledger/audit append-only e vincoli dominio |
| Configurazione di distribuzione | DeploymentReadinessTest: bootstrap del template, null/default queue, retry superiore al lease, mailer log inizializzabile, storage privato, cifratura valida senza servizi esterni e comandi recovery registrati nello scheduler |
| Design system e regressioni audit | DesignSystemTest e SecurityUxAuditTest: pagine private, XSS, modal con errore, checkbox old input, throttle, date inclusive, latest relation e log redatti |

Tutti i test applicativi sono in `tests/Feature`. I 23 file di test sono: i 6 Auth sopra indicati, AdminPanelTest, AvailabilityFlowTest, BillingConcurrencyTest, DeploymentReadinessTest, DeployPackageTest, ManualDeploymentTest, DesignSystemTest, DomainModelTest, ExternalIntegrationsTest, FinanceConcurrencyTest, InventoryConcurrencyTest, InventoryFlowTest, RetailerFlowTest, SaleAccountingTest, SecurityUxAuditTest, SmokeTest e StripeSdkGatewayTest. Tests/TestCase.php è la base, non un test aggiuntivo.

## Pacchetto manuale e installer temporaneo

`DeployPackageTest` verifica file necessari e rimozione di secrets/cache/upload/test dalla release. `ManualDeploymentTest` esercita il vero script via server HTTP isolato: token assente/vuoto/errato, GET, HTTPS, lock concorrente, marker, APP_KEY invalida, errore DB senza dettagli, preservazione DB esistente e installazione completa con PlanSeeder/cache. Testa anche il generatore locale di chiave/token. Richiesti `bash`, `tar`, `zip`, `unzip` per il test del pacchetto (disponibili nel runner Ubuntu).

Il workflow manuale `.github/workflows/build-deploy-package.yml` installa solo dipendenze di produzione e pubblica lo ZIP come artifact; non esegue deployment. Procedura in [cpanel-no-ssh-deployment.md](cpanel-no-ssh-deployment.md).

## CI GitHub Actions

`.github/workflows/ci.yml`: push, pull_request e workflow_dispatch. Ubuntu 24.04, PHP 8.4, Node 24, MySQL 8.4 con digest fissato, ImageMagick. Azioni third party fissate ai commit, token checkout non persistito e soli permessi contents:read.

Il database effimero del runner permette root senza password solo per i test, senza valori di produzione. APP_KEY nasce nel job, i secret Stripe/store sono vuoti e i test impostano fake propri. Nessun accesso al DB reale o deploy. Composer validate/install/check-platform, formatter su **tutti** i file, npm ci/build, entrambe le suite e cache config/route/view/scheduler sono gate del job. Gli install non aggiornano i lockfile.

La definizione è validata localmente con actionlint 1.7.7 (binario verificato tramite checksum ufficiale) e i comandi equivalenti sono eseguiti in cloud; un run GitHub remoto richiede pubblicazione del commit e Actions abilitato nel repository. Non chiamare verde il workflow remoto finché GitHub non lo ha eseguito. PHPStan/Psalm non sono configurati; questo progetto non presenta una verifica statica PHP come eseguita.

## Migration in ordine

| File | Responsabilità |
| --- | --- |
| 0001_01_01_000000_create_users_table.php | Users, password_reset_tokens e sessions |
| 0001_01_01_000001_create_cache_table.php | Cache e cache_locks |
| 0001_01_01_000002_create_jobs_table.php | Jobs, batch e failed_jobs |
| 2026_10_06_000001_add_role_to_users_table.php | Ruolo retailer/admin |
| 2026_10_06_000002_create_retailers_plans_and_subscriptions.php | Aziende, piani e intervalli subscription |
| 2026_10_06_000003_create_inventory_and_availability_requests.php | Giacenze, immagini e richieste |
| 2026_10_06_000004_create_sales_wallet_and_integration_records.php | Vendite/righe, ledger, payout, receipt e audit |
| 2026_10_06_000005_add_domain_constraints.php | CHECK, trigger append-only e vincoli di dominio |
| 2026_10_07_000001_add_inventory_proposals.php | Proposte dati/foto, quantità esatte e unicità piano attivo |
| 2026_10_07_000002_create_store_publications.php | Outbox pubblicazioni, catalogo fake e submitted_at |
| 2026_10_07_000003_extend_availability_requests.php | Quantità richiesta esatta, stato, consenso/versione privacy |
| 2026_10_07_000004_add_sale_financial_snapshots.php | Sconto/netto snapshot e relativi vincoli |
| 2026_10_07_000005_create_billing_and_integration_infrastructure.php | Checkout/subscription Stripe, lease receipt e archivio catalogo fake |

13 migration presenti, nessuna migration aggiunta dal Prompt 10. Deploy richiede `php artisan migrate --force`, `PlanSeeder` e privilegi per tutti i vincoli. Ledger/audit non devono essere svuotati o modificati per effettuare un retry.

## Limiti del collaudo

Copertura e pass dei test non provano SMTP reale, sandbox Stripe, ecommerce esterno, processi cPanel, backup/restore di produzione o conformità WCAG formale. Per UI usare evidenze e limiti di [audit.md](audit.md); per server completare [pre-production-checklist.md](pre-production-checklist.md). Risultati della sessione finale in [release-readiness.md](release-readiness.md).
