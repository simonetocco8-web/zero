# Integrazioni — billing, marketplace e store opzionale

## Aggiornamento approvato — 18 ottobre 2026

Marketplace riservato agli utenti autenticati (rivendite approved), homepage ospiti con accesso/registrazione; eliminazione recuperabile articoli owner/admin, gestione foto con moderazione e quantità senza zeri decimali superflui. Questo aggiornamento supera le descrizioni storiche del catalogo pubblico. Contratti e migration in [inventory-access.md](inventory-access.md).

## EXISTING — marketplace Connect distinto dal billing PRO

Adapter `StripeConnectMarketplaceGateway` con client e variabili `MARKETPLACE_STRIPE_*` dedicati. Webhook firmato `/webhooks/marketplace/stripe`, receipt SQL, nessuna conferma da redirect. Non usa `StripeBillingGateway` o le variabili `STRIPE_*` PRO. Chiavi outbound opache proxy supportate tramite modalità esplicita test/live; il signing secret HMAC deve essere disponibile localmente al server, non un placeholder destinato alla sostituzione su richieste outbound. Il runtime di sviluppo non ha credenziali marketplace configurate; requisiti e `api.stripe.com` salvati nella bozza ambiente da rivedere/pubblicare, non applicati automaticamente. Dettagli e blocchi al lancio in [marketplace-payments.md](marketplace-payments.md).

## EXISTING — aree riservate marketplace e notifiche

Aree cliente, vendite retailer e registri admin integrate con ownership e paginazione server-side. Consegne con transizioni serializzate, rimborsi integrali solo fake e notifiche Laravel persistenti con email accodate dopo commit. Credito/bonifici riutilizzano il dominio esistente; nessun nuovo incasso o movimento automatico. Stato aggiornato, contratti e operatività in [marketplace-private-areas.md](marketplace-private-areas.md); le indicazioni storiche PLANNED sulle aree sono superate da questa implementazione.

## EXISTING — scambi PRO versionati

Exchange, versioni/righe immutabili e sigillate, doppia acceptance sulla versione corrente, conguaglio merci separato da trasporto/IVA/commissioni, stock condiviso con gli acquisti, gateway fake, scadenza, completamento reciproco e area Scambi sono ora implementati. Solo retailer PRO attivi e approved negoziano; fiscalità irrisolta impedisce l'incasso simulato. Migration additiva, nessun pagamento reale o Stripe Connect. Le sezioni storiche che indicano Exchange PLANNED sono superate da questo stato: dettagli e limiti in [exchanges.md](exchanges.md).


## EXISTING — prenotazioni stock, stato aggiornato

Checkout e pagamento fake utilizzano ora StockReservation nel database: disponibilità fisica meno prenotazioni attive non scadute, lock ordinati, consumo/rilascio atomici, idempotenza checkout/intent/eventi e scadenza schedulabile. IVA indefinita continua a impedire l'incasso simulato. Le precedenti sezioni che indicano reservation PLANNED o assente descrivono lo stato storico, superato da questa implementazione. Contratti, TTL di sviluppo, limiti e verifica in [stock-reservations.md](stock-reservations.md).


## EXISTING — carrello e ordini simulati

Carrello persistente, checkout con riepilogo/riconferma e ordini padre/sottoordini/righe sono ora implementati, con snapshot server-side e ownership cliente. MarketplacePaymentGatewayInterface/FakeMarketplacePaymentGateway sono separati dal billing PRO e creano solo intenti pendenti, senza incasso. IVA non definita rimane nullable/provvisoria per scelta dell’utente; nessuna reservation o evasione/pagamento reale. Regole di trasporto, stati, limiti e risultati aggiornati in [marketplace-cart-orders.md](marketplace-cart-orders.md). Le sezioni PLANNED successive descrivono il disegno complessivo e vanno lette con questo stato aggiornato.

## EXISTING — marketplace pubblico

Homepage, catalogo filtrato/paginato, categorie e schede prodotto sono ora implementati nel monolite con immagini pubbliche autorizzate. Le query leggono soltanto la versione approvata di offerte published/change_pending appartenenti a retailer approved, senza dipendere dal gateway store. Ricerca e filtri server-side, SEO e bottom sheet mobile sono collaudati; acquisto e proposta scambio sono solo placeholder autorizzati, senza scritture economiche. Dettagli, limiti e verifiche in [public-marketplace.md](public-marketplace.md). Le sezioni PLANNED successive restano il disegno complessivo; carrello/pagamenti non sono introdotti.

## EXISTING — impatto delle fondamenta marketplace

Category/UserRole/customer_addresses e metadati di consegna/IVA sono locali e non introducono un gateway pagamento o un nuovo evento finanziario. SubscriptionBilling e StoreGatewayInterface/FakeStoreGateway/StorePublication restano conservati; la pubblicazione interna indipendente è ancora PLANNED. Il form persiste nuove condizioni commerciali attraverso la proposta esistente, senza calcoli IVA, incassi, ordini o decrementi stock. Le anagrafiche cliente non sono create dai webhook esterni. Dettagli in [marketplace-foundations.md](marketplace-foundations.md).

## Confini e stato

Revisione del 13 ottobre 2026 sul codice effettivo: **EXISTING** è implementato oggi, **PLANNED** è il futuro monolite marketplace, **OPEN BUSINESS DECISION** richiede approvazione commerciale. Solo documentazione: nessuna modifica dei binding, endpoint, migration, processi applicativi o pagamento in questa fase.

| Dominio | Stato | Confine |
| --- | --- | --- |
| SubscriptionBilling | **EXISTING** | Abbonamento PRO ricorrente Stripe; Services/Billing, StripeBillingGateway, BillingCheckout/BillingSubscription e storico Subscription |
| MarketplacePayments | **PLANNED** | Pagamenti di Order e conguagli Exchange, con MarketplacePayment separato; modello/provider non scelto |
| Store export/import | **EXISTING**, destinazione **OPTIONAL INTEGRATION** | StoreGatewayInterface/FakeStoreGateway, outbox e webhook di vendite esterne; conservarli, non usarli come catalogo interno |
| MarketplaceCatalog | **PLANNED** | Legge dati approvati direttamente da InventoryItem/Retailer/InventoryImage/piani; non è un adapter ecommerce |

PUBLIC MARKETPLACE, CUSTOMER AREA, RETAILER AREA e ADMIN AREA condividono Laravel, database e dominio. Nessun microservizio o chiamata HTTP interna per leggere il catalogo. Non esiste un nuovo contratto EcommerceGateway da sostituire automaticamente a StoreGatewayInterface: il contratto reale esistente va conservato.

## EXISTING — SubscriptionBilling

SDK ufficiale `stripe/stripe-php` v22.0.0, `StripeSdkGateway` tramite `StripeBillingGateway`; nessun Cashier/Connect. `config/stripe.php` legge STRIPE_SECRET, STRIPE_WEBHOOK_SECRET, STRIPE_PRO_MONTHLY_PRICE_ID e STRIPE_PRO_YEARLY_PRICE_ID; STRIPE_KEY è prevista ma il Checkout server-side attuale non usa Stripe.js. `config/services.php` contiene Postmark/Resend/SES/Slack generici, non un payment gateway marketplace.

POST browser `/retailer/billing/checkout`, auth/CSRF/SubscriptionPolicy: proprietario retailer pending/approved, periodo monthly/yearly scelto dal client; prezzo EUR e ownership derivati dal server. StartStripeCheckout salva un intento UUID con unique apertura per retailer e scadenza di un'ora, poi chiama Stripe fuori dal lock. `mode=subscription`, importo/currency/recurrence del Price verificati; chiave `zero-checkout:{uuid}` stabile anche dopo timeout. BillingCheckout conserva sessione e URL cifrata; un abbonamento non terminato impedisce un secondo acquisto. Il ritorno browser non prova il pagamento.

Webhook POST `/webhooks/stripe`, eventi supportati:

- checkout.session.completed;
- customer.subscription.created/updated/deleted;
- invoice.payment_failed e invoice.paid.

Firma SDK sul corpo raw, tolleranza configurata 300 secondi; body max 1 MiB, throttle 120/minuto/IP. Endpoint gruppo api stateless da routes/integrations.php, senza sessione/CSRF browser. La CSRF dei form web rimane obbligatoria. Intake minimizza/cifra payload e salva hash del raw, restituisce 202 dopo receipt durevole, non dopo concessione del piano.

SyncStripeSubscription recupera lo stato remoto corrente/latest invoice sotto cache lock distribuito, poi applica in transazione con lock Retailer/checkout/billing/entitlement. Solo subscription collegata a un checkout locale con metadata coerenti; controllo prezzo/owner e versione evento. PRO richiede active e invoice paid; insoluto non pagato ripristina FREE, invoice paid può riattivarlo, cancel_at_period_end conserva benefit nel periodo pagato e cancellazione effettiva ripristina FREE. Conserva intervalli Subscription per vendite ritardate; non approva Retailer e non elimina giacenze al downgrade. Un evento vecchio non deve spegnere l'abbonamento successivo.

SDK timeout connessione 5s/richiesta 15s e un retry, job timeout 45s. Cache condivisa database/Redis richiesta; array solo test. Nessuna sandbox reale dichiarata collaudata dai test fake. Grace period, fiscalità/proration, Customer Portal e cancellazione richiesta dal cliente sono aspetti operativi aperti; il codice gestisce gli eventi remoti ma non offre il portale.

## EXISTING — infrastruttura di eventi e recupero

WebhookInbox salva `(provider, external_event_id)` unique, hash e payload encrypted:array. Replay uguale non duplica effetti, conflitto hash restituisce 409. IntegrationQueue accoda su connessione durevole (database/redis/sqs/beanstalkd), mai sync/deferred/null. Un guasto di dispatch lascia receipt recuperabile. ProcessIntegrationEvent ha claim/lease 120s, cinque tentativi, backoff 10/60/300/900s e errori redatti; replay dopo crash tra effetto e completamento è protetto dall'idempotenza del dominio.

Il worker instrada `stripe.*` a SubscriptionBilling e `store.*` a SaleAccounting. **Non esiste handler MarketplacePayment.** `accounting.*` sono receipt canoniche interne, non eventi da rielaborare come webhook generici.

```sh
php artisan queue:work database --queue=integrations,default --sleep=3 --timeout=45 --tries=5
php artisan schedule:work
# In produzione: processi supervisionati e cron schedule:run ogni minuto.
php artisan integrations:retry
php artisan billing:sync
php artisan store:sync
# Solo dopo diagnosi, su receipt locale recuperabile:
php artisan integrations:retry --event=ID_LOCALE --force
```

Scheduler: integrations:retry e store:sync ogni minuto, billing:sync orario. Retry recupera pending/failed dovuti e processing scaduti, ignora processed/esauriti e accounting; non cancellare receipt o ledger per ricreare un effetto. DB_QUEUE_RETRY_AFTER=150 deve superare timeout/lease. Queue, cache, log e chiave di cifratura sono condivisi tra HTTP e worker. Log tecnici riportano ID/codici, mai corpi raw, firma, IBAN o secrets.

## EXISTING — store esterno e dipendenze da conservare

`config/store.php` default STORE_DRIVER=fake e binding nel container; driver sconosciuto fallisce, nessun fallback implicito. StoreGatewayInterface espone createProduct, updateProduct, publishProduct e archiveProduct. FakeStoreGateway salva fake_store_products con ID deterministico, revisione e published/archived; retry non duplica prodotti, vecchie revisioni non sovrascrivono o ripubblicano un prodotto archiviato.

Oggi ReviewInventoryItem approva in locale, crea **sempre** StorePublication con snapshot commerciale/foto e availability_request_url nella stessa transazione, poi esegue ProcessStorePublication: fake sync dopo commit, altro driver su queue dopo commit. Il job claim dura cinque minuti, invoca create/update/publish, salva external_provider/external_product_id e audit, oppure store_sync_failed; store:sync recupera le operazioni. Fallimento export non annulla lo stato locale published. Il contratto archive esiste ma la Policy locale vieta archive per articoli già pubblicati.

Dipendenze reali: InventoryItem publications/latestPublication, ReviewInventoryItem, ProcessStorePublication, comando/scheduler store:sync, viste/controller amministrativi, config/container, AdminPanelTest ed ExternalIntegrationsTest. StorePublication, mapping e FakeStoreProduct non vanno eliminati. **DEPRECATE, solo in una fase futura**, l'obbligo di export nel percorso di moderazione; conservare dati e adapter come integrazione opzionale separata.

### Ingresso delle vendite esterne

POST `/webhooks/store/{driver}` solo con adapter registrato StoreWebhookAdapter; namespace `stripe` vietato. Fake disabilitato con secret vuoto. Il protocollo fake è solo test, non uno standard provider:

- `X-Zero-Timestamp`: Unix secondi, finestra ±300s;
- `X-Zero-Signature`: `v1=` + HMAC-SHA256(secret, timestamp + '.' + corpo raw);
- JSON `event_id`, `type` order.paid/refund e `data` con ID esterni/timestamp/righe.

StoreEventData/FinancialEventData normalizzano provider ed event ID dall'envelope e importi interi/quantità stringa fino a tre decimali. Per order.paid: external_order_id, timestamp, items con external_line_id, inventory_item_id, retailer_id, quantity, unit_price_cents e discount_cents opzionale. Per refund: external_order_id, external_refund_id stabile, timestamp e items con external_line_id/amount_cents. La firma fake è sul raw, **l'hash receipt store attuale è sul DTO normalizzato**; quello Stripe è sul raw. Non confondere le due prove o modificare la deduplicazione dei replay senza migrazione esplicita.

SaleAccounting verifica sotto lock la corrispondenza prodotto/rivenditore, piano valido al timestamp, importi e limiti rimborso, poi scrive Sale/SaleItem e ledger con receipt canoniche. Rimborsi prima della vendita falliscono durevolmente nell'inbox e sono ritentabili. Le righe congelano aliquota/commissione/netto; rimborsi restituiscono commissione cumulativamente. CREDIT_MATURATION_DAYS regola disponibilità (default 0); rimborso non matura prima del credito. Nessuna gestione fiscale/trasporto/reso stock, nessun decremento stock e nessun ordine customer creato dal webhook. Un adapter reale deve derivare ownership da mapping remoto verificato, non fidarsi del retailer_id ricevuto.

### Aggiungere eventualmente un provider esterno

1. Implementare StoreGatewayInterface con SDK/HTTP e mapping/chiavi revisione stabili; non introdurre SDK nei Models/controller o export per rendere visibile il catalogo interno.
2. Implementare StoreWebhookAdapter con firma ufficiale raw, timestamp/destinazione, schema e mapping verificato verso StoreEventData; namespace distinto da billing/payment marketplace.
3. Registrare nome esplicito in config/store.php e credenziali soltanto nell'ambiente. TLS attivo, destinazioni configurate, niente URL arbitrary client.
4. Concordare stock/multi-canale, taxes/spedizioni/refunds e riconciliazione; contratto outbound attuale non offre automaticamente reservation/retrieval ordini.
5. Contract test con fake e sandbox: replay/conflitti, ordine eventi, timeout/risposta persa, revisioni/ritiro e recovery. Nessuna promessa di exactly-once remota senza supporto provider.

## PLANNED — pubblicazione interna indipendente

MarketplaceCatalog deve leggere InventoryItem/InventoryImage approvati, Retailer e benefit piani dal DB comune. Moderazione locale e visibilità non devono risolvere gateway, attendere ID esterno, leggere fake_store_products o successo StorePublication. Export opt-in dopo commit conserva una outbox separata e stato di sincronizzazione proprio; l'errore opzionale non impedisce catalogo/checkout interno.

AvailabilityRequest rimane un lead, non ordine/stock lock. GET/POST oggi usa sessione/CSRF, ownership server, consenso/versione, honeypot e throttle; può ricevere un link da qualsiasi canale. Non convertirlo automaticamente in Customer/Order. Le foto approvate richiedono una lettura pubblica dedicata e filtrata: non riutilizzare la route auth allargando il permesso su tutta la risorsa privata.

Con doppio canale di vendita, stock è un unico dominio locale da progettare con StockReservation; il contratto con il canale esterno deve evitare vendite contemporanee dello stesso residuo. Se il provider non prenota/coordinata, definire allocazioni o limiti del canale prima di attivarlo. Nessun doppio decremento o doppio credito per un ordine locale riesportato e poi notificato dal provider.

## PLANNED — MarketplacePayments, senza implementazione

MarketplacePayment registra tentativi/esiti di Order o conguaglio Exchange, con identificativi e chiavi proprie. BillingCheckout, BillingSubscription, Stripe Price PRO e `zero-checkout:{uuid}` conservano la loro semantica: non fungono da pagamento marketplace. Il pagamento non approva l'azienda, l'abbonamento non paga un Order e una notifica browser non prova un incasso.

Handler, configurazione/credenziali e namespace degli eventi dovranno essere distinti da SubscriptionBilling anche se in futuro si scegliesse lo stesso provider. L'attuale controller/worker Stripe è subscription-specifico: non collegare genericamente checkout.session.completed o invoice.paid a wallet/order. La receipt infrastrutturale può essere riutilizzata con discriminante di dominio e handler esplicito; nuovi effetti economici richiedono propri vincoli/chiavi/sorgenti. Non usare SaleAccounting con provider inventato per trasformare un'intenzione di checkout in credito.

Il futuro flusso prepara Order/reservation in transazione, invoca eventualmente il provider fuori dai lock e applica solo eventi autenticati/idempotenti a payment, ordine, consumo e accounting. Retry di invio non crea un nuovo incasso; callback tardiva dopo scadenza/annullamento richiede una regola approvata e riconciliazione, non riapertura automatica o stock negativo. Refund/settlement/wallet devono evitare duplicazione cross-channel e conservare snapshot e compensazioni append-only.

**Nessuna scelta di Stripe Connect o modello alternativo definitivo, nessun nuovo endpoint, secret o pagamento marketplace è implementato qui.**

## OPEN BUSINESS DECISION — prima di denaro reale

Chi incassa e titolarità della vendita; modalità di trasferimento al rivenditore; commissioni sui conguagli e relazione con 200/50 bps attuali; IVA/fatture B2B/B2C; regole rimborso/chargeback/reso e debito dopo payout; durata/rinnovo reservation e pagamento oltre scadenza. Inoltre stock multi-canale, logistica scambi, maturazione e uso wallet per acquisti. Le decisioni in [requirements.md](requirements.md) precedono provider/contratti/migration interessati.

## Deployment cPanel e verifiche

Conservare APP_KEY, `.env`, mapping, receipt/ledger e storage privato. Runtime PHP >= 8.4.1, CHECK/TRIGGER MySQL, ImageMagick/proc_open, HTTPS, mail, queue/scheduler sono necessari anche nel futuro monolite. Installer temporaneo cPanel solo per prima installazione: non è un upgrade automatico o un endpoint per reservation/job. La latenza di worker bounded/cron va collaudata prima di fissare TTL e notifiche. Procedure in [deployment.md](deployment.md) e [cpanel-no-ssh-deployment.md](cpanel-no-ssh-deployment.md).

EXISTING: ExternalIntegrationsTest/StripeSdkGatewayTest verificano firma/tempo/queue/replay, billing pagato vs pending, insolvency/cancel/downgrade, retry e gateway fake; BillingConcurrencyTest e FinanceConcurrencyTest provano concorrenza MySQL. Risultati della riesecuzione in [database.md](database.md#verifica-della-revisione). Nessun pagamento, SMTP o negozio reale è collaudato dalla suite.

PLANNED: test catalogo/approvazione con gateway assente o in errore, nuovi namespace separati, callback ordine che non modifica PRO e callback PRO che non paga Order, reservation vs payment/expiry/scambio, settlement/refund singoli, notifiche dopo commit e regressione delle integrazioni opzionali conservate.

## Audit finale: checkout PRO scaduto

Il billing PRO resta separato dai pagamenti marketplace. Un intento open/creating
oltre TTL non viene più chiuso automaticamente per aprirne un altro: la scadenza
locale non dimostra che Stripe non abbia incassato. Occorre riconciliazione del
provider prima di segnare esplicitamente expired e consentire un nuovo checkout.
Riconciliare anche i record storici expired delle release precedenti.
