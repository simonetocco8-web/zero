# Requisiti di ZeroMagazzino

## APPROVED — decisioni pagamenti v1.0 del 14 ottobre 2026

Fonte: PDF del committente `ZeroMagazzino_Decisioni_Pagamenti_Credito.pdf`, ricevuto il 16 ottobre 2026. Sostituisce i precedenti DA DEFINIRE per i temi trattati. Il documento fornisce decisioni commerciali; i suoi riferimenti normativi non costituiscono una validazione legale o fiscale.

- Stripe Connect, **separate charges and transfers**: un pagamento piattaforma e allocazioni per sottoordine. Il retailer resta venditore; ZeroMagazzino fattura il servizio. Billing PRO separato.
- Vendite FREE 200 / PRO 50 bp sul valore merce scontato al netto IVA, trasporto escluso, snapshot. Conguaglio PRO 50 bp della sola differenza monetaria regolata, a carico del ricevente; nessuna fee a differenza zero.
- Costi PSP separati, a carico del beneficiario, proporzionali agli importi incassati nei carrelli multipli. IVA servizio eventualmente aggiuntiva; aliquote e regimi configurabili, da validare con il commercialista. Nessun 22% implicito.
- Conguaglio sui totali merce IVA inclusa dopo sconti; trasporti e commissioni separati. Due cessioni documentate nello scambio.
- Trasporto vendite al compratore salvo offerta; negli scambi al destinatario, deroghe versionate e accettate da entrambi.
- Checkout: 15 minuti; carrello senza riserva. Proposta scambio: 48 ore senza riserva. Dopo doppia accettazione: conguaglio 1440 minuti; senza differenza impegno immediato. Scadenza libera stock, pagamento tardivo in riconciliazione.
- Prima del corriere: rimborso integrale, storno commissione, costi PSP non restituiti al venditore. Dopo spedizione: workflow distinto B2B/B2C, recesso consumatore 14 giorni salvo eccezioni; nessun automatismo che escluda diritti. Scambio già consegnato anche solo da un lato: gestione assistita coordinata.
- Saldo retailer append-only alimentato da vendite/conguagli, liquidabile ma **non spendibile** al lancio. Pending → liquidabile dopo consegna documentata o ritiro OTP +72 ore, senza contestazioni e con fondi PSP disponibili. Liquidazioni lunedì; anticipi entro limiti provider. Recovery separata, debito visibile anche negativo.
- Bonus customer separato e finanziato dalla piattaforma: personale, non trasferibile/prelevabile/ricaricabile; validità 12 mesi, massimo 10% merce, esclusi trasporto e conguaglio. Il venditore riceve il prezzo concordato; rimborso monetario sul metodo originario e ripristino bonus.

**Attivazione distinta dall'approvazione commerciale:** verifica disponibilità/configurazione Connect con Stripe e validazione fiscale restano prerequisiti. Nessuna chiamata live o liquidazione autorizzata dall'inserimento delle regole. Stato implementativo e limiti in [marketplace-payments.md](marketplace-payments.md) e [ADR](adr/marketplace-funds-flow.md). Le sezioni storiche sotto non prevalgono su queste decisioni.

## STORICO — riallineamento economico precedente

Il retailer vende/documenta la merce; ZeroMagazzino fattura il servizio. Funds flow futuro con PSP marketplace come Stripe Connect, multi-vendor; nessuna operazione reale in questa fase. Vendite FREE 200 bp e PRO 50 bp snapshot; scambi PRO 50 bp della sola differenza pagata, a carico del ricevente. Due cessioni fiscali distinte nello scambio, trasporti concordati/versionati, riserve checkout iniziali 30m e conguaglio 24h configurabili. Resi/rimborsi con audit e ledger append-only separano rimborso cliente da recovery seller. Audit dei componenti, migration e limiti in [marketplace-payments.md](marketplace-payments.md); decisioni in [ADR funds flow](adr/marketplace-funds-flow.md). Questo stato prevale sulle sezioni storiche che indicano fee scambio, fondi o durata ancora da scegliere.

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

## EXISTING — fondamenta marketplace, 13 ottobre 2026

Sono implementate categorie globali con CRUD admin, selezione categoria giacenza, registrazione cliente separata, campi anagrafici User e struttura CustomerAddress. La capacità `UserPolicy::purchase` ammette customer e retailer conservando il ruolo; admin escluso. `proposeExchange` richiede retailer approved, PRO temporalmente attivo e benefit scambio; non implementa un workflow di scambio.

InventoryItem aggiunge category_id nullable, shipping_cost_cents nullable, delivery_notes e vat_treatment descrittivo configurabile; il form gestisce unità di vendita. Categoria testuale/quantità/prezzi originali restano conservati. Categorie disattivate non assegnabili a nuovi prodotti; quelle già assegnate restano modificabili. Categoria referenziata da prodotto/proposta non eliminabile. Nessun carrello, ordine, reservation o pagamento nuovo. L'area cliente è solo accesso/profilo base: la capacità di acquisto è predisposta, non un checkout operativo. Gli indirizzi hanno Model/Policy/Action con ownership e default unici, senza CRUD pubblico in questa fase.

## Perimetro e stato della revisione

Audit del 13 ottobre 2026 sul checkout `8946d688c17acc2ab8dd3f9fb46d081f100c661f`. Questa revisione modifica solo documentazione e istruzioni per le attività future: nessuna migration, nuovo schema, pagamento o modifica al comportamento runtime.

- **EXISTING**: funzionalità verificate nel codice attuale, non promesse di collaudo in produzione.
- **PLANNED**: requisito del futuro marketplace, non implementazione disponibile.
- **OPEN BUSINESS DECISION**: scelta commerciale/operativa che richiede approvazione prima dell'implementazione interessata.

La destinazione del prodotto è un marketplace B2B/B2C integrato in **un solo monolite Laravel, con un solo database e lo stesso dominio applicativo**. Le quattro aree sono PUBLIC MARKETPLACE, CUSTOMER AREA, RETAILER AREA e ADMIN AREA. Nessun microservizio. Il negozio esterno diventa un'integrazione opzionale; non è il prerequisito del catalogo interno. Lo stato attuale e il piano futuro vanno sempre tenuti distinti.

## EXISTING — funzionalità riscontrate

| Area / componente | Comportamento attuale | Evidenza nel repository |
| --- | --- | --- |
| Account e ruoli | Solo `retailer` e `admin`; registrazione crea account, azienda e subscription atomicamente. Nessun profilo customer o carrello | `UserRole`, `UserPolicy`, `RegisterUser`, `RetailerFlowTest` |
| Azienda | Un titolare per Retailer; IVA univoca, IBAN cifrato/mascherato. Pending/rejected/suspended non operano; admin approva/rifiuta pending e sospende approved | `Retailer`, `ReviewRetailer`, `RetailerPolicy`, `AdminPanelTest` |
| Giacenze | Bozza/invio, quote, prezzi EUR, quantità in millesimi, foto private. Dati approvati conservati durante `change_pending` | `SaveInventoryItem`, `InventoryRules`, `InventoryFlowTest` |
| Moderazione | Approva e promuove proposta/foto in transazione, imposta `published`, crea sempre StorePublication; il fake è elaborato dopo commit in modo sincrono | `ReviewInventoryItem`, `ProcessStorePublication`, `AdminPanelTest` |
| Pubblico | Home introduttiva e richiesta GET/POST `/products/{item}/request`, privacy, CSRF, antispam. Solo prodotto published/change_pending con published_at e azienda approved | `routes/web.php`, `PublicInventory`, `AvailabilityFlowTest` |
| Vendite | Incassi confermati di adapter fidati, righe multi-rivenditore, snapshot di commissione, rimborsi idempotenti. Non sono un checkout | `SaleAccounting`, `SaleAccountingTest` |
| Wallet e payout | Ledger append-only, maturazione, riserva dell'intero credito EUR, esito bancario amministrativo. Nessun pagamento bancario automatico o credito spendibile al checkout | `WalletBalance`, `RequestPayout`, `ReviewPayout`, `FinanceConcurrencyTest` |
| PRO | Checkout Stripe ricorrente, webhook/queue, riconciliazione, entitlement e downgrade FREE. Il pagamento non approva l'azienda | `Services/Billing`, `ExternalIntegrationsTest`, `StripeSdkGatewayTest` |
| Store esterno | Contratto create/update/publish/archive, fake locale, receipt order.paid/refund firmate. Nessun provider reale | `StoreGatewayInterface`, `FakeStoreGateway`, `FakeStoreWebhook` |
| Audit/deployment | Audit amministrativo append-only, CI e pacchetto cPanel con installer temporaneo per prima installazione | `RecordAdministrativeAction`, `DomainModelTest`, `ManualDeploymentTest`, `DeployPackageTest` |

`config/plans.php` inizializza FREE (5 articoli, 1.000.000 cent di magazzino, niente scambio, 200 bps) e PRO (limiti null, scambio abilitabile, 50 bps, 6.900 cent/mese o 49.900 cent/anno). Le righe `plans` sono la fonte runtime; PlanSeeder non sovrascrive personalizzazioni. Il flag scambio PRO non significa che un workflow Exchange esista già. Le quote sono effettivamente applicate a giacenze non archiviate, incluse bozze/rifiutate; una riduzione proposta non libera quota prima dell'approvazione.

## Principi trasversali

Laravel standard, Blade + Tailwind + Alpine, mobile-first: card e form su smartphone, tabelle solo quando utili su desktop, tastiera/focus/label/contrasto e target tattili almeno 44×44 px. Business logic fuori dai controller, Form Requests, Policies per letture/scritture/download e ownership, Actions/Services, transazioni e `lockForUpdate` per risorse concorrenti. Nessuna nuova SPA, microservizio o framework interno.

Denaro in centesimi interi, percentuali in basis point, quantità in millesimi esatti con le primitive presenti, idempotenza per ogni effetto economico. Snapshot storici non riscritti, audit e ledger append-only, nessun secret nel repository, sessioni/CSRF/rate limit e storage privato conservati. Le verifiche MySQL devono coprire lock/vincoli; test fake non attestano pagamenti o consegna mail reali.

## PLANNED — quattro aree e criteri di accettazione

| Area | Capacità da introdurre | Vincoli |
| --- | --- | --- |
| PUBLIC MARKETPLACE | Catalogo, filtri/paginazione e dettaglio delle offerte approvate, foto pubblicabili, ingresso acquisto o scambio | Usa InventoryItem e Retailer locali; mai FakeStoreProduct o successo remoto come fonte del catalogo; niente dati privati nelle risposte |
| CUSTOMER AREA | Profilo B2B/B2C, carrello, propri ordini e notifiche | Stessi users/autenticazione Laravel, autorizzazione per proprietario; nessun accesso al wallet del venditore o ordini di altri clienti |
| RETAILER AREA | Workflow attuali più propri VendorOrder, evasione, proposte di scambio versionate, notifiche | Azienda approved e benefit del piano applicabili; visibilità limitata alla propria porzione dell'ordine |
| ADMIN AREA | Moderazione conservata, supervisione ordini/scambi/reservation, anomalie e audit | Accesso esplicito tramite Policy; gli esiti economici derivano da eventi verificati, non da uno stato browser |

### Catalogo e pubblicazione locale

MarketplaceCatalog è una lettura del dominio esistente, non una copia del catalogo esterno. La base di eleggibilità è la versione approvata di InventoryItem, `published`/`change_pending`, `published_at` presente e Retailer approved. La vendibilità richiederà inoltre disponibilità residua e requisiti del piano applicabili al flusso: non inventare oggi un ritiro automatico di prodotti al downgrade, che il codice non esegue. Una proposta pendente non deve esporre prezzi, foto o quantità non approvati.

L'approvazione locale deve funzionare senza risolvere StoreGatewayInterface, senza creare obbligatoriamente StorePublication e senza chiamate remote. Un eventuale export è un effetto opzionale separato: il suo errore non nasconde il prodotto nel marketplace interno. Questo disaccoppiamento è **PLANNED**, non già realizzato. La sospensione aziendale deve impedire nuove operazioni e la visibilità pubblica; il trattamento di ordini già impegnati resta da decidere.

Le immagini rimangono private alla sorgente. Serve una futura modalità di lettura pubblica che controlli l'eleggibilità dell'offerta e restituisca esclusivamente immagini approvate; la route autenticata attuale non è adatta al catalogo anonimo. Non rendere pubblico l'intero storage.

### Customer, carrello e ordini

Customer rappresenta il profilo acquirente B2B/B2C associato a users, separato da Retailer. Registrazione cliente e selezione delle aree non devono richiedere azienda, partita IVA del venditore o piano PRO. Le capacità di un utente che compra e vende vanno definite prima di cambiare il singolo ruolo attuale; non creare account duplicati come soluzione implicita.

Cart è modificabile e non prova disponibilità, prezzo definitivo o pagamento. Alla conferma, una Action rilegge sotto lock articoli, approvazioni e quantità, ricalcola i totali sul server e crea Order, VendorOrder e OrderItem con snapshot. Un Order contiene porzioni per venditore; il cliente vede il proprio intero ordine, ogni rivenditore solo la propria porzione. Stati d'ordine, evasione e pagamento devono essere separati.

StockReservation deve impedire overselling, avere scadenza esplicita e transizioni idempotenti di consumo/rilascio. La disponibilità sarà quantità fisica meno riserve attive; checkout simultanei, scadenze, callback tardive, modifiche quantità e ordini annullati devono condividere gli stessi lock. Non usare AvailabilityRequest come reservation e non trattare il lock di SaleAccounting come decremento stock.

### Scambi e notifiche

Exchange coordina uno scambio tra rivenditori; ExchangeVersion è una proposta immutabile, ExchangeLine descrive offerte/quantità/valori per ciascun lato, ExchangeAcceptance registra l'accettazione di quella precisa versione da parte di ogni partecipante. Una controproposta crea una nuova versione: accettazioni precedenti non valgono per la nuova. Finalizzazione solo con accettazioni coerenti, benefit scambio verificati e disponibilità riservata atomicamente. Conguaglio e suoi pagamenti sono un dominio futuro, non una chiamata al billing PRO.

Notification introduce avvisi in-app e, se previsto, mail dopo commit, con destinatario autorizzato, deduplicazione e nessun dato bancario/segreto nel messaggio. Notifiable e le mail auth esistenti sono primitive riutilizzabili, non un centro notifiche marketplace già disponibile.

### Pagamenti: confini obbligatori

**SubscriptionBilling = abbonamento PRO già esistente** (`Services/Billing`, BillingCheckout, BillingSubscription, Subscription). **MarketplacePayments = futuro pagamento ordini/scambi**, con MarketplacePayment e propri identificativi, receipt e regole. Non usare il ritorno Checkout PRO, invoice.paid dell'abbonamento o l'attivazione PRO per segnare un ordine pagato. Non scegliere Stripe Connect o altro modello definitivo in questa fase; non implementare pagamenti marketplace.

## OPEN BUSINESS DECISION — blocchi delle fasi interessate

| Decisione | Perché serve / cosa blocca |
| --- | --- |
| Chi incassa e chi vende legalmente | Contratto pagamenti marketplace, responsabilità, beneficiario e documenti fiscali |
| Modalità di trasferimento ai rivenditori | Settlement, maturazione wallet, payout e riconciliazione; nessuna equivalenza automatica con il bonifico attuale |
| Commissioni sui conguagli | Base imponibile, aliquota, segno e snapshot per Exchange; i 200/50 bps attuali non risolvono questa scelta |
| IVA e fatturazione B2B/B2C | Prezzi inclusivi/esclusivi, sconti, tasse, fatture e allocazioni multi-venditore |
| Rimborsi/resi e contestazioni | Chi autorizza/paga, quota commissione, trasporto, reso stock, debiti dopo payout |
| Durata reservation | TTL, rinnovi, rilascio, SLA worker/cron e trattamento di pagamento arrivato dopo scadenza |
| Guest checkout e account con più capacità | Customer, autenticazione, storico ordini e migrazione dei ruoli |
| Scambio: partecipanti, valuation e completamento | Benefit richiesti a entrambi i lati, logistica, annullamento, valore giuridico dell'accettazione |
| Spedizione/ritiro e sospensioni/downgrade | Costi/stati di evasione e gestione impegni esistenti quando il venditore non può più operare |
| Wallet spendibile e credito di scambio | Regole di disponibilità, compensazione e concorrenza col payout; il wallet attuale non autorizza acquisti |
| Retention e notifiche | Consensi/privacy B2C, dati fiscali, canali e durata delle evidenze |

Queste decisioni non bloccano audit/documentazione o catalogo in sola lettura; precedono le funzionalità economiche coinvolte.

## Sequenza delle prossime fasi — PLANNED

1. Approvare le decisioni necessarie a identità, acquisti/scambi, stock e denaro; definire stati e casi di accettazione senza modificare il billing PRO.
2. Separare pubblicazione interna ed export opzionale; introdurre catalogo/dettagli/foto approvate, con test di indipendenza dal gateway e di privacy.
3. Introdurre Customer e capacità delle aree, conservando users/retailers; migrazione additiva e test accesso incrociato.
4. Progettare/revisionare migration additive di Cart, Order, VendorOrder, OrderItem e StockReservation; implementare il flusso locale senza incassi reali, con test MySQL di concorrenza/scadenza e snapshot.
5. Introdurre Exchange/versioni/righe/accettazioni e Notification, riutilizzando reservation; nessun conguaglio reale finché le regole non sono approvate.
6. Solo dopo decisione sul modello di incasso/trasferimenti: MarketplacePayments, settlement verso wallet, rimborsi e riconciliazione, sandbox e regressione SubscriptionBilling.
7. Migrazione graduale e collaudo cPanel: backup/restore, cron/worker, release compatibili, rollout e rollback senza cancellare vendite, ledger, audit o mapping esterni.

La classificazione dei componenti è in [architecture.md](architecture.md); schema esistente e nuove entità concettuali in [database.md](database.md); contratti e separazione pagamenti in [integrations.md](integrations.md). [ui-ux.md](ui-ux.md), README, [release-readiness.md](release-readiness.md) e checklist operative descrivono il prodotto precedente/attuale: i riferimenti al negozio esterno non costituiscono requisiti per il marketplace pianificato e andranno riallineati nelle successive fasi.
