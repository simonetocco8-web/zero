# Verifica bancaria OpenAPI Trust

## Contratto ufficiale consultato

Fonte tecnica, consultata il 25 ottobre 2026: [OAS Trust corrente](https://console.openapi.com/oas/en/trust.openapi.json), `info.version: 3.0.3`; [documentazione](https://console.openapi.com/apis/trust/documentation). SHA-256 dello schema consultato: `8aa2f1a62215c2c6268ccfae0592bb0eedbea433794b88a7b49d60cdbc336b11`. Le pagine commerciali non determinano payload o validità.

- Advanced: `POST /iban-advanced/{iban}`, poi `GET /iban-advanced/{id}`.
- VoP: `POST /iban-vop`, JSON `{iban, name}`, poi `GET /iban-vop/{id}`.
- Autenticazione Bearer, Accept JSON, nessun redirect HTTP, timeout configurabile limitato a 20 secondi e connect timeout 5 secondi. Nessuna chiamata live è stata usata nei test.
- `success=true` è solo esito di trasporto applicativo. Per un risultato `DONE` Advanced richiediamo tutti i sei booleani `validations.{chars,account,iban,structure,length,countrySupport}.valid`; uno falso rende l'IBAN invalido. Campi mancanti/tipi errati sono errori di contratto: nessuna attivazione. Non usiamo la stringa `status` per decidere.
- Banca: soli `bank`, `branch`, `bic`, `address`, `city`, `state`, **`zipCode`**, `country`, `countryCode`. Nessun `account` o payload raw salvato.
- SEPA: booleani documentati `creditTransfer`, `directDebit`, `cor1`, `b2b`, `cardClearing`, `creditInstantTransferRT1`, `creditInstantTransferTIPS`. Non inventiamo campi `sctInst`/`zip`.
- VoP: `vopResult`, `details.matchType`, `details.ownerName`. Verifichiamo ID, IBAN restituito e nome richiesto se presente; eventuale `matchType` deve concordare con l'esito. Campi non documentati non diventano prove di validità.

## Configurazione

In `.env.example` sono documentati solo placeholder:

```dotenv
OPENAPI_TRUST_TOKEN=
OPENAPI_TRUST_BASE_URL=https://test.trust.openapi.com
OPENAPI_TRUST_TIMEOUT=10
OPENAPI_TRUST_MAX_ATTEMPTS=8
OPENAPI_TRUST_POLL_SECONDS=30
OPENAPI_TRUST_MAX_MINUTES=30
```

Configurazione centralizzata in `services.openapi_trust`; `.env` e APP_KEY restano invariati. Sono ammessi esclusivamente i due host ufficiali HTTPS. Produzione solo con `OPENAPI_TRUST_BASE_URL=https://trust.openapi.com` e token configurati esplicitamente sul server. La pagina amministrativa Impostazioni mostra la disponibilità della configurazione senza esporre credenziali. Token mancante/URL non ammessa produce uno stato leggibile, senza fatal error e senza sostituzione dell'IBAN.

## Persistenza e flusso

Migration additiva `2026_10_25_000001_create_retailer_bank_verifications`, aggiunta al manifest del deploy updater. Crea due tabelle, senza alterare dati/schema di `retailers`, né backfill o chiamate remote. Gli IBAN legacy restano «Non ancora verificato» e richiedono un'azione esplicita. Nessun `migrate:fresh`/refresh.

`RetailerBankVerification` contiene candidato cifrato, fingerprint HMAC-SHA256 con APP_KEY server, ultime quattro cifre, ID/stati provider, contatori e scadenza, dati banca/SEPA selezionati ed esito VoP. Anche nome controllato e intestatario sono cifrati; tutti questi dati sensibili e il fingerprint sono nascosti nella serializzazione. La rotazione di APP_KEY richiede le procedure Laravel di conservazione/rotazione delle chiavi; il fingerprint cambia e non va ricreato automaticamente come prova di verifica.

Il salvataggio profilo mantiene la regola locale `Iban`, uppercase/rimozione spazi e il significato di campo vuoto. `UpdateRetailerProfile` salva i dati aziendali sotto lock del rivenditore e richiede il controllo senza assegnare direttamente il candidato a `retailers.iban`. Solo Advanced valido lo attiva, sempre cifrato, e accoda VoP. Un esito VoP negativo non rende invalido l'IBAN né cambia la Ragione Sociale.

Stesso IBAN/nome e controllo corrente pendente o concluso: riuso, nessun costo duplicato ad ogni salvataggio. Nome cambiato su IBAN validato: nuova sola VoP, con riuso dei dati Advanced. Un nome cambiato su candidato ancora in Advanced aggiorna il nome della futura VoP senza duplicare Advanced. Un semplice cambio nome su un conto legacy non avvia verifiche bancarie automatiche.

L'intenzione corrente è il record con ID più recente del rivenditore. Tutte le decisioni sono serializzate dal lock del rivenditore; la risposta HTTP viene applicata solo se il record è ancora corrente. I job superati diventano `superseded`. Controlliamo nuovamente IBAN attivo e Ragione Sociale prima di VoP. Nessuna transazione resta aperta durante le richieste HTTP.

## Asincronia, costi e recovery

Un unico job `ProcessRetailerBankVerification`, contenente soltanto l'ID locale, orchestra Advanced e VoP sulla queue **integrations** e sulla connessione esistente `integrations.queue_connection` (database per default). Non usa la inbox dei webhook Stripe/store, che ha altri contratti. La connessione deve avere un driver durevole database/redis/sqs/beanstalkd: sync/null sono rifiutati in modo controllato.

`NEW`/`WAIT`: persiste ID provider e accoda GET con ritardo crescente (base configurata, minimo 5 secondi, massimo 300). Massimo 8 chiamate per fase per default (limite configurabile 1–30), deadline complessiva 30 minuti per default. Le GET 429/5xx/connessione fallita sono ritentate entro tali limiti. Al termine compare errore recuperabile, senza loop infinito.

**Nessun retry automatico delle POST**, neppure su 429/5xx/timeout. Prima della POST viene persistito `SUBMITTING`; lease 90 secondi. Un worker interrotto senza ID restituito non può ripetere la POST: dopo la lease fallisce con `submission_uncertain`. Un ID strutturalmente valido viene conservato anche quando il risultato finale è malformato, per consentire il recupero manuale via GET. Non è stato trovato nell'OAS un meccanismo documentato di idempotenza per queste POST.

«Riprova verifica» è volontario e sottoposto a throttle: se c'è un ID ancora interrogabile riprende GET; per esito definitivo ERROR o invio ambiguo senza ID può creare una nuova verifica a consumo, con avviso in UI. Un esito concluso positivo/non corrispondente non viene ripetuto da normali salvataggi. Il candidato e l'IBAN attivo restano distinti in UI.

Worker e scheduler richiesti:

```sh
php artisan queue:work database --queue=integrations --tries=1 --timeout=60
php artisan schedule:run
# Recupero manuale delle sole operazioni pendenti dovute:
php artisan bank-verifications:resume
```

Job timeout 45 secondi; mantenere `retry_after`/visibility timeout del backend superiore al timeout worker (database normalmente 90 secondi). Il comando schedulato ogni minuto riaccoda operazioni dovute e lease scadute; non reinvia POST. I contatori provider sono persistenti, indipendenti dai retry del worker. La tabella durevole rende recuperabile anche un errore di accodamento dopo il commit.

## Esiti e UX

Card Coordinate bancarie: IBAN attivo mascherato, eventuale candidato distinto, dati bancari disponibili e SEPA. `NEW`/`WAIT` mostrano «Verifica in corso» e «Aggiorna esito», che ricarica solo il profilo locale.

| VoP | Presentazione |
| --- | --- |
| MATCH | Verde, Corrispondenza confermata, intestatario se disponibile |
| CLOSE_MATCH | Corrispondenza parziale, confronto dei nomi e suggerimento facoltativo |
| NO_MATCH | Intestatario non corrispondente; IBAN comunque valido |
| NOT_APPLICABLE | Verifica non disponibile; nessuna falsa mancata corrispondenza o suggerimento |
| ERROR | Verifica non completata; IBAN validato conservato, retry volontario |
| UNKNOWN | Stato documentato aggiuntivo: se terminale DONE resta non confermato ed è trattato come errore recuperabile |

Il suggerimento usa solo `details.ownerName` documentato per MATCH/CLOSE_MATCH/NO_MATCH; l'azione è offerta per CLOSE_MATCH/NO_MATCH. Il bottone valorizza il campo aziendale e richiede Salva profilo, senza modifiche invisibili o richieste HTTP automatiche. Dopo il salvataggio serve una nuova VoP: soltanto MATCH conferma l'intestazione.

## Errori, audit e accesso

401/403 → configurazione/autenticazione; 400 → richiesta non valida; 404 → ID non trovato; 406 → errore funzionale; 429 → limite; 5xx → indisponibilità; connessione/timeout → invio non confermato. Messaggi provider, response complete e token non sono salvati o mostrati. Nessuna eccezione HTTP grezza finisce in failed_jobs/log applicativi; errori interni hanno codici fissi.

`retailer_bank_verification_events` riusa il trait `AppendOnly`: eventi di avvio, validazione, fallimento e singoli esiti VoP, con soli ID, last4, stato/codice errore. Audit amministrativo esistente non riutilizzabile direttamente: `RecordAdministrativeAction` richiede un admin e azioni/soggetti vincolati. Non ne allarghiamo le autorizzazioni né gli enum storici. Nessun IBAN completo, ownerName o risposta raw negli eventi. Anche i job serializzati hanno solo ID. Eventuali strumenti esterni di tracing HTTP/access log devono oscurare token e path IBAN dell'endpoint imposto dal provider.

Nuova route: POST `retailer/profile/bank-verification`, nome `retailer.bank-verification.store`, auth + policy update sul retailer corrente, CSRF e throttle 5/minuto. Non accetta retailer_id/verification_id/IBAN arbitrari; nessuna route di lettura per ID. Il profilo resta privato e autorizzato. Salvataggio del profilo mantiene il proprio throttle esistente.

Payout/credito/Stripe/ordini/scambi/stock/IVA non cambiano. I payout continuano a usare lo snapshot cifrato dell'IBAN attivo: non è introdotto un nuovo vincolo VoP sui bonifici né sono modificati snapshot storici.

## Verifica manuale

1. Configurare **sandbox** e token esternamente; avviare worker integrations e scheduler.
2. Accedere come retailer proprietario. Per IBAN legacy verificare maschera, stato non verificato e bottone esplicito.
3. Inserire un IBAN di test documentato dal provider: un checksum locale errato non deve avviare job/API.
4. Salvare il candidato; verificare che il vecchio IBAN resti attivo durante Advanced NEW/WAIT, poi controllare banca e attivazione dopo DONE valido.
5. Controllare VoP e tutti gli esiti previsti dal sandbox. Accettare eventualmente il suggerimento, verificare che cambi solo il campo e salvare prima della nuova VoP.
6. Ripetere il salvataggio invariato: nessuna nuova chiamata. Modificare soltanto nome: nessuna nuova Advanced.
7. Provare indisponibilità e interruzione worker su un DB isolato: il recovery non deve duplicare POST.

Test automatici esclusivamente con `Http::fake()` e prevenzione delle richieste non simulate. Copertura di validazione locale/strutturata, asincronia Advanced/VoP, dati selezionati, cifratura, serializzazione, audit, riuso, concorrenza con risposta tardiva, errori, recovery, autorizzazioni, CSRF, throttle e configurazione.

## File della feature

Modificati: `.env.example`, `app/Actions/UpdateRetailerProfile.php`, `app/Http/Controllers/RetailerController.php`, `config/services.php`, `deployment/additive-migrations.json`, `resources/views/retailer/profile.blade.php`, `routes/web.php`, `routes/console.php`.

Nuovi: `app/Services/BankVerification/{OpenApiTrustClient,RetailerBankVerificationService,TrustFailure}.php`, `app/Jobs/ProcessRetailerBankVerification.php`, `app/Models/{RetailerBankVerification,RetailerBankVerificationEvent}.php`, `app/Http/Controllers/RetailerBankVerificationController.php`, `resources/views/components/retailer-bank-details.blade.php`, `resources/views/admin/settings.blade.php`, `database/migrations/2026_10_25_000001_create_retailer_bank_verifications.php`, `tests/Feature/RetailerBankVerificationTest.php` e questa documentazione.

## Esito verifiche finali

PHP 8.4 / Node 24 / MySQL 8.4, solo fixture HTTP fake:

- Mirati bancari SQLite: 42/42; mirati bancari MySQL: 42/42.
- SQLite completa: 594 passati, 28 skip già previsti, nessun fallimento.
- MySQL completa su nuovo DB isolato: 622 passati, nessuno skip/fallimento.
- ReleaseUpdateTest su MySQL: 5/5, migration e manifest verificati.
- Pint, Vite e `php artisan view:cache`: superati.
- Browser: nove stati a 320/375/768/1280/1536 px, anche tema scuro a 375/1536; nessun overflow, target almeno 44 px, nessun IBAN completo in pagina. Suggerimento da tastiera senza richieste HTTP o salvataggi automatici.
- `.env` invariato, nessuna chiamata reale OpenAPI, nessun deploy.
