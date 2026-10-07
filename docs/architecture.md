# Architettura proposta

## Impostazione

Monolite Laravel standard con rendering Blade. Due aree autenticate, rivenditore e amministratore, condividono dominio e database; il cliente usa il negozio esterno. Nessun microservizio o frontend SPA previsto.

Il requisito PHP effettivo del lockfile corrente è >= 8.4.1 (Symfony 8.1), dichiarato anche in composer.json; Laravel è fissato a 13.35.0. MySQL >= 8.0.16 per CHECK applicati, consigliato 8.4. Node 24 LTS per gli asset Vite 8. Versioni e requisiti di deployment in README.md e docs/deployment.md.

## Organizzazione prevista

| Posizione Laravel | Responsabilità |
| --- | --- |
| `app/Models` | Persistenza, relazioni, cast; niente chiamate al negozio |
| `app/Enums` | Stati e valori di dominio chiusi |
| `app/Policies` | Ruolo, ownership e permessi sulle risorse |
| `app/Http/Requests` | Validazione e autorizzazione dell'input |
| `app/Http/Controllers` | Coordinare richiesta, Action e risposta |
| `app/Actions` | Casi d'uso transazionali: approvare, inviare, riservare credito |
| `app/Services` | Calcoli condivisi e adapter ecommerce |
| `app/Events`, `app/Listeners`, `app/Jobs` | Effetti asincroni, sincronizzazione e notifiche |
| `resources/views` | Blade e componenti UI condivisi |
| `routes/web.php` | Browser con sessione e CSRF |
| Route API dedicate | Webhook firmati e integrazioni; niente sessioni browser implicite |

Le directory sono una proposta, non file già implementati. Crearle solo quando necessarie. Binding del contratto ecommerce tramite container e configurazione Laravel.

## Stati e invarianti

- Rivenditore: `pending → approved/rejected`; sospensione amministrativa prevista separatamente. Reinvio dopo rifiuto soggetto a nuova verifica.
- Giacenza: `draft → pending → published/rejected`, con `change_pending` e `archived`; una modifica commerciale dopo approvazione richiede nuova verifica. Prima della modifica definire se ritirare immediatamente l'offerta precedente o mantenerne una versione approvata immutabile.
- Pubblicazione: `not_published → pending → published/failed`, con ritiro e sincronizzazione trattati separatamente dalla moderazione.
- Bonifico: `pending → paid/rejected`; riserva del credito all'invio, rilascio al rifiuto e consumo alla conferma del pagamento. Annullamenti, errori bancari e rimborsi richiedono transizioni esplicite prima dell'implementazione.
- Stato vendita derivato dal provider e normalizzato; non equiparare creazione ordine, incasso e maturazione credito.

Ogni transizione passa da una Action autorizzata, con transazione e audit. Vincoli e lock difendono gli invarianti anche con richieste concorrenti. I controller non accettano dal client proprietario, saldo o stato approvato come dati fidati.

## Esecuzione e consistenza

HTTP applicativo, worker queue e scheduler sono processi separati. Per il primo deployment queue database Laravel, senza obbligo Redis; rivalutare Redis su misure operative. I job vengono dispatchati dopo commit.

Per la pubblicazione usare una semplice tabella di operazioni di integrazione: record scritto nella transazione di approvazione, job asincrono e scheduler che recupera operazioni rimaste in attesa. Evita perdere una pubblicazione se il processo termina tra commit e invio del job. Nessun bus distribuito necessario.

Non eseguire chiamate remote dentro transazioni con lock. Timeout, retry limitati, idempotenza e riconciliazione gestiscono il fallimento parziale. Disponibilità e overselling richiedono un accordo con il provider: un lock locale non protegge il checkout remoto.

## Sicurezza e operatività

Policies per ogni risorsa, middleware di autenticazione e stato aziendale, query limitate al proprietario. Configurare HTTPS, sessioni sicure, rate limit e storage immagini privato; pubblicazione delle immagini approvate tramite un meccanismo controllato compatibile con il provider.

Audit strutturato per moderazione, modifiche commerciali, credito e bonifici; log tecnici con correlation ID senza secrets. Backup MySQL e storage con prova di ripristino, monitoraggio failed jobs e ritardi di sincronizzazione sono requisiti del deployment.

## Verifica futura

PHPUnit: unit test calcoli e transizioni; feature test accessi e Policies; test MySQL per vincoli/concorrenza; test adapter/webhook con fake, firma e duplicati. Build Vite e smoke test HTTP per il workflow web. Nessuna suite esiste ancora e nessun comportamento applicativo è dichiarato funzionante.

## Bootstrap implementato (Prompt 1)

La root ora contiene Laravel 13 con Breeze Blade; il runtime verificato è PHP 8.4, con requisito PHP >= 8.3. Lockfile Composer/npm inclusi. Sono implementati solo autenticazione e struttura UI: nessuna Action di giacenze, pubblicazione, credito o bonifico.

`UserRole` e `UserPolicy` separano le due aree. `RegisterRequest` e le altre Form Requests di autenticazione validano input; Actions dedicate gestiscono creazione account e scrittura password. Il cast `hashed` di `User` applica l'hashing Laravel. Il ruolo non è assegnabile tramite input pubblico.

Sessioni, cache e queue sono configurate su database; migration standard Laravel più ruolo utente. La suite rapida usa SQLite e viene verificata anche su un database MySQL dedicato. Dettagli di avvio, email locali e bootstrap amministratore in `../README.md`.

## Domain model implementato (Prompt 2)

Lo schema effettivo è descritto in `database.md` e sostituisce i nomi e gli stati della proposta iniziale quando divergenti. Models/Enums/Policies/Factories sono presenti; `MoneyMath` e `WalletBalance` offrono calcoli esatti e letture del ledger. `RecordAdministrativeAction` è la primitiva di audit per le future Actions, che dovranno chiamarla nella propria transazione.

L'associazione aziendale attuale è `User hasOne Retailer`, con `retailers.user_id` univoco. Una subscription attiva per rivenditore, con storico mantenuto. Nessun controller commerciale, quota, bonifico o webhook è ancora implementato. I limiti dei piani sono dati centralizzati, non condizioni sparse nei controller.

## Flusso rivenditore implementato (Prompt 3)

Registrazione atomica di account, azienda e subscription con scelta FREE/PRO. Partita IVA italiana a 11 cifre univoca; la verifica fiscale effettiva resta amministrativa. FREE è attivo immediatamente; PRO è richiesto/pending, senza addebiti o benefit attivi finché il futuro flusso pagamento non lo attiva. Approvazione aziendale indipendente dal piano: pending, rejected e suspended mantengono accesso a dashboard/profilo, mentre stock, vendita, richieste e credito richiedono approvazione tramite middleware e Policy. Nessuna gestione delle giacenze è introdotta in questa fase.

Il profilo modifica esclusivamente i dati aziendali validati del proprietario, senza accettare stato, ownership o piano. IBAN cifrato, validazione checksum mod97 e visualizzazione limitata alle ultime quattro cifre: campo vuoto conserva il valore, nuovo valore lo sostituisce; escluso dai dati riproposti dopo errori. Le modifiche aziendali conservano lo stato di verifica attuale; una politica di nuova verifica per cambi di ragione sociale/IVA potrà essere introdotta esplicitamente.

Dashboard con dati del solo proprietario: piano attivo/richiesto, commissione del piano attivo, numero/valore delle giacenze EUR non archiviate, ultime cinque richieste e credito disponibile dal ledger. Somme e formattazione monetaria senza float.

Amministratore: elenco a card paginato, approvazione/rifiuto dei soli profili pending; rifiuto con motivazione obbligatoria. Action con lock, transazione, Policy e audit append-only; revisione duplicata rifiutata. Nessuna modifica al pagamento/attivazione subscription. Reinvio di iscrizioni rifiutate e sospensione sono fasi successive.

## Gestione giacenze (Prompt 4)

`InventoryRequest` valida il form; `InventoryRules` converte quantità/prezzi con Brick Math e applica quote/scambio/prezzi; `SaveInventoryItem` e `ReviewInventoryItem` gestiscono transazioni, ownership e transizioni. `InventoryPhotos` usa Symfony Process con argomenti separati e ImageMagick (binario configurabile), timeout e limiti di risorse. Policy copre creazione, modifica, archivio, lettura e revisione; il middleware aziendale blocca le operazioni non approvate. UI Blade/Alpine con preview locale, tabella desktop e card mobile. Nessun adapter ecommerce attivato. Dettagli e vincoli in database.md.

## Pannello amministratore (Prompt 5)

Accesso tramite auth + UserPolicy/accessAdministration, con Policy anche sulle azioni. Dashboard con conteggi e audit recente. Elenchi rivenditori/giacenze/bonifici a card responsive, paginazione server-side da 15 record e filtri validati per stato/azienda/intervallo data. Filtri conservati nella paginazione; nessun per_page arbitrario. Conferma tramite dialog nativo/Alpine, motivazioni obbligatorie per rifiuto/sospensione.

ReviewRetailer gestisce anche approved→suspended; ReviewPayout registra esclusivamente esito di operazioni bancarie esterne e ledger. ReviewInventoryItem produce un’operazione durevole verso il gateway fittizio, elaborata fuori dalla transazione; dettagli in integrations.md. Audit include attore, tipo/ID entità, timestamp e snapshot essenziali, escludendo dati bancari e secrets.

## Servizi economici (Prompt 7)

SaleAccounting::recordSale e ::refund sono ingressi interni per adapter fidati. Validano e normalizzano importi/quantità/identificativi, acquisiscono receipt idempotenti, applicano transazioni e lock e scrivono snapshot/ledger. Non sono endpoint pubblici e non chiamano provider. Firma webhook e mapping saranno responsabilità del futuro adapter prima dell’invocazione.

RequestPayout applica Policy, controllo stato sotto lock, validazione IBAN, unicità pending e saldo corrente; crea richiesta e riserva nello stesso commit. ReviewPayout conserva audit amministrativo, rilascia riserve e registra il pagamento esterno solo con copertura sufficiente. WalletBalance separa disponibile, netto maturato e riservato. CreditController espone esclusivamente dati derivati dal rivenditore autenticato; Form Request rifiuta selezione client di proprietario, importo, valuta o IBAN.

Le operazioni economiche non modificano le giacenze: la fonte autorevole dello stock e il comportamento dei resi devono essere concordati col negozio. Prima di integrare provider reali, definire maturazione, tasse/commissioni, dati fiscali, riconciliazione, eventi fuori ordine e gestione debiti.

## Infrastruttura integrazioni (Prompt 8)

StripeSdkGateway usa il solo SDK ufficiale tramite StripeBillingGateway, senza Cashier o nuovo framework. StartStripeCheckout prepara intento transazionale, invoca Stripe fuori dal lock e salva la sessione idempotente. SyncStripeSubscription recupera dati correnti sotto cache lock distribuito e applica entitlement locale in transazione, senza modificare l’approvazione aziendale. BillingSubscription è separata dagli intervalli storici Subscription.

WebhookController verifica firma raw e delega WebhookInbox: receipt durevole, payload cifrato e queue integrations. ProcessIntegrationEvent acquisisce lease, applica Stripe oppure FinancialEventData al dominio economico e registra completamento/errori redatti. Scheduler recupera dispatch mancati e riconcilia Stripe. StoreGatewayInterface e StoreWebhookAdapter vengono registrati esplicitamente in config/store.php; driver sconosciuti falliscono senza simulazioni implicite. Routes webhook stateless separate in routes/integrations.php; routes browser con CSRF e Policy conservate.

## Revisione sicurezza e performance (Prompt 9)

PrivatePageHeaders centralizza no-store per pagine autenticate e form auth, nosniff e Referrer-Policy. Foto servite/cancellate solo da riferimenti local/inventory/UUID.jpg; InventoryPhotos ripete il limite sorgente di 15 MB anche fuori dai Form Requests. Conferma/cambio password limitati a 6 richieste al minuto; reset token escluso dai dati flash; errori SQL di produzione registrati con soli codici e nome connessione, senza messaggi o bindings. Elenchi amministrativi eager loaded con latestOfMany, dashboard con cursor di sole quantità/prezzi e conteggio nello stesso passaggio, filtri date con bound inclusivi senza funzioni sulle colonne. Report in [audit.md](audit.md).

## Verifica e distribuzione (Prompt 10)

GitHub Actions valida dipendenze/installazione congelata, piattaforma PHP 8.4, formatter completo, build Node 24, suite MySQL con concorrenza e SQLite, cache config/route/view e scheduler. Production document root sul solo public, storage privato persistente, queue durevole e cron ogni minuto. Nessun deployment automatico. Procedure, variabili, copertura e checklist in deployment.md, environment.md, testing.md e pre-production-checklist.md.
