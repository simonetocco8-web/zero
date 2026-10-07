# Integrazioni

## Confine ecommerce

Il negozio è un sistema esterno: gestisce vetrina pubblica, carrello, checkout e pagamento cliente. ZeroMagazzino gestisce aziende, moderazione, giacenze e pubblicabilità, rappresentazione vendite, richieste e credito.

Introdurre un unico contratto applicativo `EcommerceGateway`, con adapter del provider scelto e fake per i test. Modelli e controller non dipendono da SDK o payload del provider. Aggiungere solo operazioni realmente richieste: pubblicare/aggiornare offerta approvata, ritirarla, sincronizzare disponibilità e recuperare vendite per riconciliazione quando supportato.

DTO semplici traducono dati locali in payload e risposte normalizzate. ID esterni, mapping e stato di sincronizzazione restano nella coppia `external_provider`/`external_product_id` di `inventory_items` nella versione attuale; il provider configurato si risolve tramite container Laravel. Nessuna implementazione adapter in questa fase.

## Fonte autorevole

| Informazione | Responsabilità proposta |
| --- | --- |
| Azienda, moderazione, offerta approvata | Gestionale |
| Ordine e pagamento cliente | Negozio/provider |
| Credito e bonifici rivenditore | Registro del gestionale, alimentato da eventi verificati |
| Disponibilità e prenotazioni durante checkout | Da concordare con il provider prima delle vendite reali |

L'aggiornamento stock asincrono non garantisce assenza di overselling. Confermare supporto del provider a prenotazioni, quantità, ordine degli eventi e aggiornamenti condizionali; documentare il comportamento quando tali capacità mancano.

## Pubblicazione e resilienza

Approvazione registra un'operazione persistente nella stessa transazione; dopo commit un job invoca l'adapter. Scheduler recupera operazioni pendenti, worker esegue retry limitati con backoff, timeout e tracciamento redatto. Errori permanenti richiedono intervento amministrativo.

Chiavi idempotenza stabili per risorsa/versione e ID remoti persistiti. Se la risposta è persa dopo un successo remoto, verificare l'esistenza tramite identificatore esterno prima di ricreare. Se il provider non consente deduplicazione o ricerca, segnalare esito incerto e riconciliare manualmente: non garantire effetti «exactly once» senza supporto.

## Webhook in ingresso

1. Limitare dimensione richiesta; verificare firma sul corpo raw e timestamp secondo specifica ufficiale del provider, prima di usare il payload.
2. Validare schema e identificativi; inserire receipt con vincolo unico `(provider, external_event_id)`. Duplicati già acquisiti ricevono risposta idempotente.
3. Rispondere positivamente solo dopo acquisizione durevole; elaborare tramite job. Receipt e scanner consentono recupero se dispatch o worker fallisce.
4. Applicare aggiornamenti vendita/stock/credito in transazione con chiavi univoche per gli effetti. Non marcare evento completato prima del commit.
5. Gestire eventi fuori ordine con versione/timestamp affidabile o recupero dello stato remoto; non fare regredire un ordine per un evento vecchio.
6. Riconciliare periodicamente ordini e pubblicazioni se API disponibili; rendere visibili eventi falliti e retry.

Firma, algoritmo, header, tolleranza temporale e rotazione dipendono dal provider; non inventare un protocollo universale. Una allowlist IP eventuale è difesa aggiuntiva, non sostituisce la firma. I job ritentati non devono produrre doppi crediti.

## Richieste disponibilità

Validare prodotto pubblicato, destinatario derivato dal prodotto e contatto. Rate limit e protezione antiabuso; non fidarsi di `retailer_id` inviato dal client. Se trasmesse dal negozio, autenticare il canale con firma/API secondo contratto. Confermare luogo del form e canale di notifica prima dell'implementazione.

## Configurazione e secrets

Configurare tramite `.env` e file `config/*`: provider, URL base, credenziali API, webhook secret, timeout e code. URL base controllato in configurazione, mai destinazione arbitraria fornita dal cliente. Verifica TLS sempre attiva.

Nessun secret reale nei documenti, log o `.env.example`. Stripe non è un requisito attuale: aggiungerlo solo se scelto per i piani o altro flusso esplicitamente definito. Bonifici inizialmente intesi come richieste/revisione amministrativa; non presumere API bancaria o esecuzione automatica.

## Decisioni e test prima del rilascio

Confermare provider e sandbox, limiti API, autenticazione, firma webhook, idempotenza, mapping prodotti/varianti, modello ordini multi-rivenditore, tasse/commissioni, rimborsi e politica stock. Testare adapter con fake, payload firmati, firma errata, duplicati, ordine eventi, timeout, risposta persa, rate limit e riconciliazione. Eseguire contract test nella sandbox del provider prima di dichiarare l'integrazione pronta.

## Primitive disponibili (Prompt 2)

`integration_events` contiene receipt univoche per provider/evento, con hash raw, payload cifrato opzionale e stato di elaborazione. `wallet_transactions.idempotency_key` impedisce effetti finanziari duplicati. Sono soltanto primitive dati: la firma webhook, l'intake transazionale e i worker non sono ancora implementati.

Nel vocabolario inventario richiesto, `published` deve rappresentare la pubblicazione completata; il futuro adapter deve distinguere approvazione locale, conferma remota e fallimento attraverso il workflow e lo stato dell'operazione di integrazione. La tabella outbox `integration_operations` proposta sopra non è ancora creata. Per modifiche `change_pending` resta da definire una versione approvata immutabile o il ritiro dell'offerta precedente.

## Gateway fittizio implementato (Prompt 5)

`StoreGatewayInterface` espone createProduct, updateProduct e publishProduct. Il container lega esclusivamente `FakeStoreGateway`: nessuna chiamata a provider reali. Il catalogo simulato vive in `fake_store_products`; ID deterministico per giacenza e revisione crescente rendono create/retry idempotenti e impediscono a una revisione vecchia di sovrascrivere la nuova.

L’approvazione scrive `store_publications` nella stessa transazione di promozione della giacenza, con snapshot dei dati/foto. `ProcessStorePublication` esegue il gateway dopo commit, registra risultato oppure codice d’errore generico, salva provider/ID e audit di pubblicazione. Attualmente dispatchSync è appropriato solo per il fake locale; un futuro provider reale richiederà dispatch asincrono, timeout/retry/firma e riconciliazione. Non inserire chiamate remote nelle transazioni di approvazione.

`php artisan store:sync` recupera operazioni pending/failed e processing ferme da almeno cinque minuti. È un comando operativo interno, senza endpoint pubblico. Un errore di sincronizzazione non annulla l’approvazione locale: lo stato della pubblicazione è separato e visibile in amministrazione. Snapshot e risultato non contengono IBAN, credenziali o payload cliente.

## Richieste di disponibilità (Prompt 6)

Form pubblico GET/POST `/products/{item}/request`, senza autenticazione ma con sessione e CSRF. Il negozio può collegare questa URL, inclusa nei nuovi snapshot gateway come availability_request_url; usare un link alla pagina, senza disabilitare CSRF per POST cross-origin. Nessuna API di raccolta pubblica o esposizione dei contatti.

Disponibile solo per published/change_pending con published_at e rivenditore approved; anche change_pending usa esclusivamente la versione approvata. POST ricontrolla prodotto/rivenditore sotto lock, ricava l’ownership dal database, forza stato new e data consenso server-side. Richiesta non equivale ad acquisto o prenotazione e può chiedere più della quantità esposta.

Campi aggiunti: customer_company, quantity_milliunits interi, privacy_accepted_at e privacy_policy_version. I record storici mantengono null per quantità/consenso, senza inventare evidenze retroattive. Quantità strettamente positiva, fino a tre decimali; nome/email obbligatori; campi testo senza markup e con limiti di lunghezza; Blade esegue escaping. Honeypot contact_website obbligatoriamente vuoto; 5 POST/minuto e 20/ora per IP, condivisi fra prodotti, includendo tentativi non validi. Nessun IP memorizzato nella richiesta. Configurare trusted proxy solo quando verificato dal deployment.

Privacy: consenso obbligatorio e versione dell’informativa configurabile tramite PRIVACY_POLICY_VERSION. PRIVACY_POLICY_URL sostituisce la pagina informativa interna: l’informativa definitiva deve indicare identità del titolare, recapiti, conservazione e diritti per il deployment effettivo. Dati personali nascosti dalla serializzazione generica, consultabili solo nella lista autenticata del proprietario; header private/no-store. Nessuna comunicazione o marketing automatico.

Pagina rivenditore paginata da 15 richieste, card mobile e tabella desktop; modifica consentita soltanto allo stato (new/contacted/closed) con Policy, middleware, CSRF e lock. Timestamp contatto conservato come storico; chiusura impostata entrando in closed e rimossa riaprendo. Dashboard conta richieste nuove e mostra le ultime cinque del solo proprietario.

## Contratto economico interno (Prompt 7)

Nessun webhook HTTP o provider reale aggiunto. Un adapter futuro deve verificare firma/timestamp sul corpo raw prima di chiamare il servizio; il servizio non autentica un payload proveniente dalla rete. La commissione deriva dal piano registrato in DB valido alla data vendita (subscription.starts_at/ends_at/cancelled_at), non da campi forniti dall’evento. Conservare la cronologia delle sottoscrizioni. Un evento senza un piano storico valido è rifiutato. Modifiche future ai prezzi/aliquote dei piani richiedono una politica di versionamento per eventi storici non ancora acquisiti; le vendite già acquisite mantengono sempre lo snapshot.

Esempio di chiamata interna, importi esclusivamente in centesimi interi, quantità stringa decimale fino a tre cifre:

```php
$sale = app(\App\Services\SaleAccounting::class)->recordSale([
    'provider' => 'fake',
    'external_order_id' => 'ordine-123',
    'external_event_id' => 'pagamento-123', // opzionale
    'timestamp' => '2026-10-07T10:00:00Z',
    'currency' => 'EUR', // unica valuta supportata, default EUR
    'items' => [[
        'external_line_id' => 'riga-1',
        'inventory_item_id' => $product->id,
        'retailer_id' => $product->retailer_id,
        'quantity' => '2.000',
        'unit_price_cents' => 10000,
        'discount_cents' => 1000, // opzionale, default 0
    ]],
]);
// FREE: lordo 19000, commissione 380, netto 18620 centesimi.

app(\App\Services\SaleAccounting::class)->refund([
    'provider' => 'fake',
    'external_order_id' => 'ordine-123',
    'external_refund_id' => 'rimborso-123',
    'external_event_id' => 'evento-rimborso-123', // opzionale
    'timestamp' => '2026-10-08T10:00:00Z',
    'items' => [['external_line_id' => 'riga-1', 'amount_cents' => 19000]],
]);
```

Ordini multi-rivenditore sono supportati con credito distinto per riga/proprietario. Il caller è un servizio interno fidato; proprietà prodotto/rivenditore verificata e vincolata dal DB. Gli identificativi ordine/riga/rimborso devono essere stabili e i replay conservare timestamp/dati originali; il riuso con contenuti diversi è errore. È accettato un alias external_event_id per lo stesso ordine/rimborso senza ripetere gli effetti. Rimborsi prima della vendita vengono rifiutati e devono essere ritentati dopo l’acquisizione della vendita dall’adapter futuro. Eventi/hash/payload conservati localmente, nessuna notifica esterna automatica.

Il rimborso riguarda importi lordi della riga e restituisce la commissione pro quota; trasporto/tasse/commissioni non rimborsabili non sono modellati. CREDIT_MATURATION_DAYS regola la disponibilità dalla data della vendita; il rimborso diventa effettivo non prima del credito originale. Decidere questi aspetti prima dell’uso con denaro reale.


## Infrastruttura implementata (Prompt 8)

### Stripe Checkout e configurazione

SDK ufficiale `stripe/stripe-php` 22, fissato nel lockfile (API richiesta dall’SDK: `2026-09-30.endive`). Nessun secret reale nel repository. Configurare nell’ambiente STRIPE_KEY, STRIPE_SECRET, STRIPE_WEBHOOK_SECRET, STRIPE_PRO_MONTHLY_PRICE_ID e STRIPE_PRO_YEARLY_PRICE_ID. STRIPE_KEY è predisposta per eventuali componenti client; l’attuale Checkout ospitato viene creato dal server e non richiede Stripe.js. Creare su Stripe due Price ricorrenti EUR: 6900 centesimi/mese e 49900 centesimi/anno, intervallo_count 1, quantità 1; usare inizialmente la modalità test e controllare corrispondenza con le righe `plans` locali.

Dashboard → “Piano e pagamenti” (`/retailer/billing`), POST autenticato/CSRF `/retailer/billing/checkout`. Policy: solo proprietario rivenditore pending/approved, mai amministratore o profilo rejected/suspended. Il client sceglie soltanto monthly/yearly. Prezzo, proprietario, metadata e URL di ritorno sono derivati dal server. L’SDK verifica importo/valuta/intervallo del Price prima di creare Checkout `mode=subscription`; nessuna free trial o promozione configurata. URL di ritorno derivano da APP_URL (HTTPS in produzione).

`billing_checkouts` conserva un intento server UUID, Price/importo/periodo, sessione e URL cifrata, scadenza dopo un’ora. Unicità dell’intento aperto per rivenditore + lock + idempotency key `zero-checkout:{uuid}` impediscono doppi Checkout anche dopo timeout o invii simultanei. Un intento aperto si riutilizza; cambio periodo richiede completamento o scadenza. Un abbonamento Stripe non terminato impedisce un nuovo acquisto. Un errore ambiguo mantiene l’intento per retry con la stessa chiave; non creare un nuovo intento per aggirare errori o doppie disposizioni. Il ritorno browser, anche con `result=success`, non prova il pagamento e non attiva PRO.

Configurare webhook Stripe POST `/webhooks/stripe` per:
- checkout.session.completed;
- customer.subscription.created/updated/deleted;
- invoice.payment_failed e invoice.paid.

Firma verificata dall’SDK sul corpo raw, tolleranza 300 secondi; firma/payload non validi restituiscono 400, schema non valido 422, corpo oltre 1 MiB 413. Limitare il corpo anche nel reverse proxy. Endpoint stateless nel gruppo api, senza middleware sessione/CSRF: questa scelta riguarda esclusivamente webhook autenticati dalla firma. CSRF browser resta attiva. Rate limit 120/minuto/IP. Clock del server sincronizzato. Schema evento minimizzato e payload cifrato nella receipt, hash raw per conflitti di replay, mai log del corpo/firma/secrets.

Il worker recupera **lo stato corrente** dell’abbonamento Stripe con latest_invoice espansa; cache lock distribuito serializza recupero e applicazione per subscription, senza chiamate remote nelle transazioni SQL. Timeout SDK connessione 5s/richiesta 15s, un retry di rete; job timeout 45s. Le subscription esterne senza metadata zero_checkout_id associata a un intento locale vengono ignorate: l’account Stripe può ospitare altri prodotti.

PRO richiede status Stripe active e ultima fattura paid. Trialing/incomplete/past_due/unpaid non concedono PRO; invoice.payment_failed registra lo stato remoto e, se non pagato, ripristina FREE. invoice.paid può riattivare PRO. cancel_at_period_end mantiene PRO fino al termine pagato; cancellazione effettiva ripristina FREE. `billing_subscriptions` descrive il provider; `subscriptions` conserva intervalli locali di entitlement e prezzi snapshot. Cambio periodo/piano chiude l’intervallo precedente e ne apre uno nuovo; commissioni delle vendite acquisite non vengono riscritte. La cronologia resta disponibile per vendite ritardate. Eventi più vecchi della versione locale e cancellazioni di un vecchio abbonamento non disattivano quello successivo.

**Pagamento e verifica aziendale sono indipendenti:** il worker non modifica retailers.status/approved_at/reviewed_by. PRO pagato ma pending rimane “Profilo in verifica” e non pubblica giacenze. Downgrade non elimina prodotti o foto: le nuove operazioni rispettano i limiti FREE e l’eventuale magazzino già oltre quota richiede gestione amministrativa.

Decisioni da definire prima di addebiti reali: IVA/fatturazione, grace period dopo insoluti (attualmente perdita immediata dei benefit PRO), proration/cambio prezzo, cancellazione richiesta dal cliente e Customer Portal. Gli aggiornamenti/cancellazioni provenienti da Stripe sono gestiti, ma nessun portale o pulsante di cancellazione remoto è implementato in questa fase. Non dichiarare collaudata una sandbox Stripe reale senza credenziali di test e prova degli eventi.

### Inbox, queue e recupero

Gli endpoint rispondono 202 dopo receipt durevole `(provider, external_event_id)`; stesso ID/contenuto non produce altri effetti, contenuto differente restituisce 409. Jobs duplicati sono consentiti: claim transazionale, stato processing e lease 120s impediscono esecuzioni sovrapposte. Gli effetti economici restano protetti dalle ricevute canoniche e dalle chiavi univoche del ledger; un crash tra effetto e completamento inbox viene ritentato senza duplicazione.

INTEGRATIONS_QUEUE_CONNECTION=database (default), coda `integrations`; usare una connessione durevole database/redis/sqs/beanstalkd, mai sync/deferred/null per webhook. QUEUE_CONNECTION del resto dell’app non forza il webhook ad essere sincrono. Se dispatch fallisce, receipt pending conserva integration_dispatch_failed e lo scheduler recupera. Worker e scheduler sono obbligatori:

```sh
php artisan queue:work database --queue=integrations --timeout=45 --tries=5
php artisan schedule:work
# In produzione: process supervisor e cron schedule:run ogni minuto.
```

Cinque tentativi applicativi, backoff 10/60/300/900s, codice errore redatto in integration_events, nessuna eccezione SDK sensibile in failed_jobs. Claim interrotti vengono recuperati dopo lease scaduta. `integrations:retry` ogni minuto recupera pending/failed dovuti e processing scaduti, fino a 1000 per ciclo; ignora eventi del ledger già processed e non riguarda le receipt canoniche `accounting.*`. Dopo cinque errori serve controllo operativo e retry esplicito:

```sh
php artisan integrations:retry --event=ID_LOCALE --force
php artisan billing:sync
```

billing:sync orario accoda una riconciliazione per abbonamento non terminato; nessuna chiamata Stripe nel comando. Cache database/Redis condivisa tra worker è necessaria per i lock distribuiti; cache array è solo per test. Monitorare backlog, receipt failed, failed_jobs e anzianità delle operazioni. Non cancellare receipt o ledger per consentire retry.

### Gateway negozio e protocollo fake

STORE_DRIVER=fake. Il binding risolve `config/store.php`, drivers.{driver}.gateway; un driver sconosciuto fallisce e **non** ripiega sul fake. StoreGatewayInterface comprende createProduct, updateProduct, publishProduct e archiveProduct. Il fake conserva ID/revisioni e flag archived: archive idempotente, pubblicazioni vecchie non riattivano il prodotto, nuova revisione approvata può pubblicarlo. Il metodo archive è pronto nel contratto; le regole locali che limitano l’archiviazione delle giacenze rimangono quelle esistenti.

Il fake esegue pubblicazioni dopo commit in modo sincrono. Un driver esplicitamente configurato diverso da fake accoda ProcessStorePublication sulla queue integrations; store:sync recupera e accoda operazioni durevoli. Un provider reale deve supportare chiavi stabili per revisione, mapping remoto, timeout e riconciliazione prima dell’uso.

Endpoint POST `/webhooks/store/{driver}`: solo driver esplicitamente registrati con StoreWebhookAdapter. Nessun ecommerce reale assunto. Adapter fake disabilitato finché STORE_WEBHOOK_SECRET è vuoto; il protocollo seguente è esclusivamente un contratto di test, non una firma universale di ecommerce:
- X-Zero-Timestamp: Unix timestamp in secondi, finestra ±300s;
- X-Zero-Signature: `v1=` + HMAC-SHA256(secret, timestamp + '.' + corpo raw);
- JSON: `event_id`, `type` order.paid/refund, `data` nel formato economico del Prompt 7.

```json
{
  "event_id": "shop-event-123",
  "type": "order.paid",
  "data": {
    "external_order_id": "ordine-123",
    "timestamp": "2026-10-07T10:00:00Z",
    "items": [{
      "external_line_id": "riga-1",
      "inventory_item_id": 123,
      "retailer_id": 45,
      "quantity": "2.000",
      "unit_price_cents": 10000,
      "discount_cents": 1000
    }]
  }
}
```

Per refund: data.external_refund_id stabile, external_order_id/timestamp e items con external_line_id/amount_cents. Importi interi, quantità stringa esatta; float rifiutati. Provider e identificativo evento sono derivati dall’adapter/envelope, mai sovrascrivibili dentro data. StoreEventData e FinancialEventData sono DTO readonly validati; il dominio SaleAccounting continua ad accettare i vecchi array interni ma usa la medesima normalizzazione. L’intake valida il formato; associazione prodotto/rivenditore e limiti del rimborso sono verificati dal worker in transazione. Refund prima della vendita fallisce in modo durevole e viene ritentato dopo l’acquisizione dell’ordine.

### Aggiungere un nuovo provider

1. Implementare App\Contracts\StoreGatewayInterface con SDK/HTTP del provider, senza chiamate da Models/controller e senza credenziali nei payload. Mantenerne stabile il contratto per dati/revisioni/ID e non restituire tokens o risposte personali complete.
2. Implementare App\Contracts\StoreWebhookAdapter::verifyAndNormalize: firma ufficiale sul corpo raw, controllo timestamp/destinazione secondo documentazione del provider, validazione schema, mapping degli ID remoti ai prodotti/retailer locali e traduzione in StoreEventData. Non fidarsi di un retailer_id arbitrario del negozio; derivarlo dal mapping remoto verificato. Vietato usare il namespace stripe.
3. Registrare un nome esplicito in config/store.php con gateway, webhook e riferimenti env per le proprie credenziali/secret. Impostare STORE_DRIVER soltanto quando l’uscita verso quel provider è pronta. Non cambiare le regole economiche per adattare nomi di campo del provider.
4. Registrare nel provider l’URL HTTPS /webhooks/store/{nome}; fare arrivare al dominio solo order.paid confermato e refund con ID stabili, stessa valuta e timestamp originali ai replay. Esporre API separate soltanto se richieste e autenticate.
5. Testare gateway con fake HTTP, signature valida/errata/scaduta, payload modificato, duplicati/concorrenza, evento fuori ordine, timeout e risposta persa; quindi fare contract test nella sandbox reale. Concordare stock, tasse, spedizione e rimborsi con il provider prima della produzione.

I test attuali simulano trasporto HTTP dell’SDK Stripe, webhook firmati, queue e provider; non effettuano pagamenti reali o chiamate a negozi esterni.
