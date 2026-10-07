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
