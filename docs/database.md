# Modello dati — esistente e marketplace pianificato

## EXISTING — IVA vendite configurabile, 24 ottobre 2026

Aliquote esplicite per merce e spedizione, moderazione, snapshot immutabili e checkout fail-safe: [contratto IVA marketplace](marketplace-vat.md). Questa implementazione supera le descrizioni storiche di IVA meramente descrittiva e spedizione sempre irrisolta per le vendite; commissioni e flussi economici restano invariati.


## Aggiornamento approvato — 18 ottobre 2026

Marketplace riservato agli utenti autenticati (rivendite approved), homepage ospiti con accesso/registrazione; eliminazione recuperabile articoli owner/admin, gestione foto con moderazione e quantità senza zeri decimali superflui. Questo aggiornamento supera le descrizioni storiche del catalogo pubblico. Contratti e migration in [inventory-access.md](inventory-access.md).

## Credito retailer spendibile — schema additivo

`retailer_credit_holds`: fonte univoca `order_id` oppure `exchange_version_id`, `retailer_id`, centesimi EUR, flag simulated e stato reserved → spent/released. Owner/importo/fonte/valuta non modificabili; nessun campo balance. Fonte e ownership validate da trigger, storico non eliminabile.

`wallet_transactions.retailer_credit_hold_id` collega scritture append-only di reservation, release, purchase/exchange spend e refund. Bucket available/reserved; solo available con available_at maturato entra nella disponibilità. Prenotazioni payout legacy e spesa condividono il lock Retailer; lettura corrente delle righe sotto lock. Fondi simulati non incidono sul saldo reale.

`marketplace_payments.retailer_credit_cents`; `exchange_difference_payments.retailer_credit_cents`, `card_cents`, `funding_snapshot`. Snapshot credito/carta immutabili, allocazioni per vendor con resti maggiori/ID crescente; CHECK MySQL totale = carta + credito + bonus customer (quest'ultimo mai per retailer/conguagli). Storico card nullable preservato. Ledger promozionale customer resta distinto.

La migration `2026_10_16_000005_add_retailer_credit_funding` aggiunge soltanto schema/indici/trigger ed estende il CHECK funding esistente senza riscrivere dati storici. Su SQLite l'ALTER nativo conserva tutti i trigger wallet preesistenti; su MySQL nuova foreign key. Nessun reset/rollback/rimozione dati; migrazione inclusa nell'allowlist cPanel. Dettagli commerciali in [marketplace-payments.md](marketplace-payments.md).


## EXISTING — schema additivo pagamenti e bonus del 16 ottobre 2026

Quattro migration `2026_10_16_000001`–`000004`: `marketplace_connected_accounts`, `marketplace_stripe_receipts`, riferimenti PSP sulle due payment tables; `customer_bonus_grants` e `customer_bonus_entries` con ownership composita grant/user e order/user, trigger SQL append-only e scadenza per lotto; quote carta/bonus e funding snapshot immutabile per intento; `marketplace_provider_refunds` e `marketplace_late_capture_refunds` con chiavi idempotenti uniche e trigger sugli snapshot monetari. Ledger retailer preesistente conservato, senza colonna balance. Dati precedenti restano invariati; `card_cents=null` significa storico precedente, non bonus inventato. Dettagli e limiti in [marketplace-payments.md](marketplace-payments.md).

> Stato corrente: decisioni PDF v1.0 approvate, Stripe Connect separate charges and transfers; credito retailer maturato spendibile anche con carta, bonus customer separato. Attivazione subordinata a verifica provider/fiscale. Vedere [regole correnti](marketplace-payments.md). I paragrafi precedenti sono storici.

## APPROVED / EXISTING — riallineamento economico marketplace

Il retailer vende/documenta la merce; ZeroMagazzino fattura il servizio. Funds flow futuro con PSP marketplace come Stripe Connect, multi-vendor; nessuna operazione reale in questa fase. Vendite FREE 200 bp e PRO 50 bp snapshot; scambi PRO 50 bp della sola differenza pagata, a carico del ricevente. Due cessioni fiscali distinte nello scambio, trasporti concordati/versionati, riserve checkout iniziali 30m e conguaglio 24h configurabili. Resi/rimborsi con audit e ledger append-only separano rimborso cliente da recovery seller. Audit dei componenti, migration e limiti in [marketplace-payments.md](marketplace-payments.md); decisioni in [ADR funds flow](adr/marketplace-funds-flow.md). Questo stato prevale sulle sezioni storiche che indicano fee scambio, fondi o durata ancora da scegliere.

## EXISTING — aree riservate marketplace e notifiche

Aree cliente, vendite retailer e registri admin integrate con ownership e paginazione server-side. Consegne con transizioni serializzate, rimborsi integrali solo fake e notifiche Laravel persistenti con email accodate dopo commit. Credito/bonifici riutilizzano il dominio esistente; nessun nuovo incasso o movimento automatico. Stato aggiornato, contratti e operatività in [marketplace-private-areas.md](marketplace-private-areas.md); le indicazioni storiche PLANNED sulle aree sono superate da questa implementazione.

## EXISTING — scambi PRO versionati

Exchange, versioni/righe immutabili e sigillate, doppia acceptance sulla versione corrente, conguaglio merci separato da trasporto/IVA/commissioni, stock condiviso con gli acquisti, gateway fake, scadenza, completamento reciproco e area Scambi sono ora implementati. Solo retailer PRO attivi e approved negoziano; fiscalità irrisolta impedisce l'incasso simulato. Migration additiva, nessun pagamento reale o Stripe Connect. Le sezioni storiche che indicano Exchange PLANNED sono superate da questo stato: dettagli e limiti in [exchanges.md](exchanges.md).

## EXISTING — prenotazioni stock, stato aggiornato

Checkout e pagamento fake utilizzano ora StockReservation nel database: disponibilità fisica meno prenotazioni attive non scadute, lock ordinati, consumo/rilascio atomici, idempotenza checkout/intent/eventi e scadenza schedulabile. IVA indefinita continua a impedire l'incasso simulato. Le precedenti sezioni che indicano reservation PLANNED o assente descrivono lo stato storico, superato da questa implementazione. Contratti, TTL di sviluppo, limiti e verifica in [stock-reservations.md](stock-reservations.md).

## EXISTING — carrello e ordini simulati

Carrello persistente, checkout con riepilogo/riconferma e ordini padre/sottoordini/righe sono ora implementati, con snapshot server-side e ownership cliente. MarketplacePaymentGatewayInterface/FakeMarketplacePaymentGateway sono separati dal billing PRO e creano solo intenti pendenti, senza incasso. IVA non definita rimane nullable/provvisoria per scelta dell’utente; nessuna reservation o evasione/pagamento reale. Regole di trasporto, stati, limiti e risultati aggiornati in [marketplace-cart-orders.md](marketplace-cart-orders.md). Le sezioni PLANNED successive descrivono il disegno complessivo e vanno lette con questo stato aggiornato.

## EXISTING — indici del catalogo pubblico

La migration additiva `2026_10_13_000002_add_marketplace_catalog_indexes.php` aggiunge indici di lettura senza modificare dati: 15 migration totali. Nessuna tabella carrello/ordini/pagamenti creata. Query, visibilità e limiti della ricerca parziale sono descritti in [public-marketplace.md](public-marketplace.md).

## EXISTING — estensione additiva delle fondamenta

Migration `2026_10_13_000001_add_marketplace_foundations.php` (14 migration totali): categories con quattro valori iniziali e backfill delle stringhe esistenti, category_id nullable con FK restrittiva, shipping_cost_cents non negativo, delivery_notes/vat_treatment nullable, first_name/last_name/phone User nullable e customer_addresses con owner User e default shipping/billing unici tramite colonne generate. UserRole aggiunge customer senza cambiare ruoli già salvati.

Il backfill conserva esattamente category, prezzi/quantità, ID e dati economici; aggiunge il riferimento anche alla categoria nella proposta JSON senza cancellarne altri campi. Nomi diversi che producono lo stesso slug ricevono suffissi, senza accorpare dati. Nessun backfill IVA o consegna che inventi condizioni commerciali: nullable significa da definire, costo 0 significa gratuito. VAT è un codice configurabile descrittivo, senza aliquota/calcolo fiscale. Customer è rappresentato da User con anagrafica, non da una seconda identità duplicata.

SQLite aggiunge la FK nullable via ALTER ADD COLUMN per conservare i trigger già presenti; MySQL usa FK/CHECK. Down intenzionalmente rifiuta la rimozione delle nuove colonne/tabelle: rollback del codice e recovery operativo, non cancellazione di dati. Procedura e risultati aggiornati in [marketplace-foundations.md](marketplace-foundations.md). Le sezioni PLANNED di seguito restano il disegno complessivo, con Customer e dati prodotto ora parzialmente realizzati.

## Perimetro

Audit del 13 ottobre 2026, checkout `8946d688c17acc2ab8dd3f9fb46d081f100c661f`. **EXISTING** descrive le 13 migration e i Models attuali; **PLANNED** descrive entità concettuali da progettare in una fase successiva, non tabelle già create; **OPEN BUSINESS DECISION** identifica scelte commerciali non risolte. Nessuna migration o modifica dello schema applicativo in questa revisione. I test ricreano soltanto il database dedicato di test.

Un unico database MySQL InnoDB/utf8mb4 per PUBLIC MARKETPLACE, CUSTOMER AREA, RETAILER AREA e ADMIN AREA, nello stesso monolite/dominio. MySQL >= 8.0.16 per CHECK applicati, consigliato 8.4; PHP a 64 bit. SQLite è una suite rapida, non una prova dei lock MySQL.

## EXISTING — entità e relazioni reali

| Tabella / Model | Semantica e vincoli rilevanti |
| --- | --- |
| users / User | Account Laravel, password hashed, ruolo retailer/admin, hasOne Retailer; Notifiable per auth, nessun customer_id o ruolo customer |
| retailers / Retailer | user_id unique, IVA unique, profilo/sede/contatti, IBAN cifrato, stato e revisore; FK restrict |
| plans / Plan | Prezzi centesimi, quote nullable, exchange_available, commission_basis_points; runtime source of truth |
| subscriptions / Subscription | Storico entitlement con piano, prezzo/valuta snapshot, intervallo/date; active_retailer_id generated unique; billing_subscription_id nullable |
| inventory_items / InventoryItem | Offerta per Retailer, prezzi, quantity_milliunits, unit/currency, condizione/logistica, stato/revisore; proposed_data/proposed_images, submitted_at/published_at; provider/product ID nullable in coppia |
| inventory_images / InventoryImage | Foto approvate ordinate, disk/path/alt_text; unique(item,position), FK cascade della sola riga DB |
| availability_requests / AvailabilityRequest | Lead anonimo con contatti privati, quantità richiesta nullable, consenso/versione/timestamp; FK composta item/retailer. Nessun account cliente obbligatorio |
| sales / Sale | Vendita esterna normalizzata, unique(provider,external_order_id) non vuoti, stato/valuta/totale/date, email nullable nascosta; nessuna proprietà Customer |
| sale_items / SaleItem | External line ID unique per Sale, Retailer/InventoryItem, snapshot nome/SKU/unità/prezzo/quantità/sconto/lordo/commissione/netto; FK composte owner e valuta |
| wallet_transactions / WalletTransaction | Ledger append-only con importo firmato, valuta/maturazione, fonte sale_item o payout, receipt opzionale, idempotency_key unique |
| payout_requests / PayoutRequest | Importo positivo, IBAN snapshot cifrato, pending/paid/rejected, revisore/riferimento/date; pending_retailer_id generated + currency unique |
| store_publications / StorePublication | Outbox export: giacenza, requested_by admin, operation, status, snapshot payload/result JSON, error_code, processing/completed_at |
| fake_store_products / FakeStoreProduct | Catalogo simulato dell'adapter, ID/revisione/payload e flag published/archived; non è MarketplaceCatalog |
| billing_checkouts / BillingCheckout | UUID server, Retailer, Price/intervallo/prezzo snapshot, sessione/URL cifrata e scadenza; open_retailer_id unique, session ID unique |
| billing_subscriptions / BillingSubscription | Stato Stripe, external subscription/customer, invoice_paid, periodo pagato/cancel flag/versione evento, checkout/retailer; unique(provider,external_subscription_id) |
| integration_events / IntegrationEvent | Receipt unique(provider,event ID), hash, payload encrypted:array nascosto, stato/attempts/processing lease/next retry/error |
| audit_logs / AuditLog | Attore User admin, enum azione, soggetto polimorfico, before/after redatti; append-only |

Tabelle infrastrutturali Laravel: password_reset_tokens, sessions, cache/cache_locks, jobs/job_batches/failed_jobs. Nessun Cart, Order, VendorOrder, StockReservation, Exchange o MarketplacePayment nel checkout attuale. Nessun nuovo schema proposto viene applicato qui.

### Stati e regole implementate

- Retailer: pending/approved/rejected/suspended. `ReviewRetailer` consente pending→approved/rejected e approved→suspended; non esiste reinvio/reintegro automatico.
- InventoryItem: draft/pending/published/change_pending/rejected/archived; CHECK published_at per published/change_pending. Pubblicazione locale non significa export riuscito: StorePublication ha stato proprio pending/processing/succeeded/failed.
- Le colonne standard e inventory_images conservano la versione approvata; una sola proposta JSON resta separata. Review promuove la proposta oppure conserva la versione pubblicata e scarta la proposta rifiutata. Le foto proposte sono private.
- Archive Policy permette solo draft/pending/rejected mai pubblicati e senza external ID. Il contratto gateway possiede archiveProduct, ma il flusso locale non ritira oggi offerte pubblicate.
- AvailabilityRequest: new/contacted/closed, timestamp coerenti; quantity/consenso nullable per record antecedenti all'estensione. Non inventare consenso retroattivo.
- Sale: pending/paid/cancelled/partially_refunded/refunded nel vocabolario; SaleAccounting crea direttamente paid e gestisce rimborsi, non le altre fasi di ordine.
- Subscription: pending/active/cancelled/expired, monthly/yearly. BillingSubscription usa lo stato provider separato, non questo enum.
- Payout: pending/paid/rejected, nessuno stato approved che provi un bonifico.
- Receipt: pending/processing/processed/failed con processing_at e lease. Audit admin limitato a moderazione e payout dagli enum/Action esistenti.

### Piani, denaro e quantità

`config/plans.php` definisce FREE (0, 5 articoli, 1.000.000 cent di valore, niente scambio, 200 bps) e PRO (6.900 cent mensili, 49.900 annuali, limiti null, scambio disponibile, 50 bps). PlanSeeder crea solo piani mancanti, non account/demo, e non sovrascrive personalizzazioni. Subscription conserva prezzo/valuta concordati; le aliquote sono lette dal Plan dal servizio e fotografate su SaleItem, non versionate automaticamente in Subscription.

Importi `_cents` BIGINT firmati; commissioni intere in basis point; CurrencyCode per codice a tre lettere maiuscole (EUR è l'unica valuta operativa di SaleAccounting). ExactIntegerCast/ExactInteger rifiutano float/booleani/decimali/esponenti e overflow. MoneyMath/Brick Math usano intermedi esatti e HalfUp per riga/commissione. `quantity_milliunits=1250` significa `1.250` unità: HasExactQuantity espone stringhe a tre decimali; niente float o precisione eccedente. Inventario DB consente zero, form crea quantità positiva; SaleItem richiede quantità positiva.

InventoryRules blocca Retailer, subscription/piano e giacenze, verifica quote su tutte le non archiviate (incluse bozze/rifiuti). Il confronto valore usa quantità_milliunits × prezzo_cents contro limite × 1000. Per un prodotto pubblicato con proposta mantiene il massimo tra valore approvato e proposto. Sono vincoli di quota, non stock prenotato per acquisto.

### Sale/SaleItem e ledger: semantica da preservare

La migration `2026_10_06_000004_create_sales_wallet_and_integration_records.php` richiede identificativi esterni e lega SaleItem a inventario/retailer e valuta del Sale; `2026_10_07_000004_add_sale_financial_snapshots.php` aggiunge discount/net (net nullable per compatibilità legacy, servizio nuovo sempre valorizzato) e unique payout pendente. SaleAccounting acquisisce receipt canoniche order/refund, blocca Retailer in ordine stabile, verifica prodotto/owner e piano storico, scrive Sale paid, righe e credito/commissione nella stessa transazione. Non controlla vendibilità o quantità residua e non decrementa stock. Refund storna importi/commissioni cumulativamente, non ripristina merce.

Per questo **Sale/SaleItem sono KEEP come registro contabile/import esterno**, non i nuovi Order/OrderItem: mancano cliente autenticato, dati fiscali/logistici, carrello, reservation, VendorOrder e stati separati di evasione/pagamento. I loro ID/FK/storici non saranno rinominati o riattribuiti per adattarli al nuovo checkout. Un bridge futuro verso la contabilità richiede un contratto esplicito e deduplicazione tra canali.

WalletBalance calcola available (movimenti maturati inclusi riserve), total (tutti esclusi reservation/release), reserved (opposto di reservation+release maturati), netEarned (movimenti maturati con sale_item_id). `available_at` NULL/futuro non è disponibile. Tipi: sale_credit positivo, commission/refund negativi, payout_reservation negativo, payout_release positivo, payout negativo, payout_reversal positivo, adjustment nonzero di entrambi i segni.

CHECK impongono fonte sale_item per sale_credit/commission/refund e fonte payout per i tipi bonifico; le fonti non possono essere contemporaneamente presenti. FK composte difendono owner/valuta; idempotency_key globale unique e unique(payout_request_id,type). Trait e trigger impediscono UPDATE/DELETE di wallet/audit; correzioni solo compensative, non riscrittura dello storico. Gli operatori DBA restano responsabili di DROP/TRUNCATE e privilegi.

RequestPayout riserva l'intero disponibile EUR con IBAN valido e nessuna richiesta pendente; ReviewPayout paid richiede riserva coerente e saldo coperto, aggiunge release+payout ed esito/audit. Un rimborso dopo pagamento può creare debito; un rimborso durante riserva può bloccare paid. Nessun utilizzo wallet al checkout né nuova causale Order/Exchange oggi. Nuove fonti richiederanno estensione futura di FK/CHECK e dell'accounting: non usare adjustment scollegati per aggirare i vincoli.

### Vincoli, privacy e prove

Unicità SKU per Retailer anche se archiviato; EAN non unique; provider IDs case-sensitive su MySQL. FK restrict sui dati economici/storici; cancellare inventario referenziato da SaleItem non è consentito. Filesystem non è transazionale: cleanup file su rollback e dopo commit; la cascata DB immagini non elimina i file. Conservare backup coordinati DB/upload/APP_KEY.

IBAN Retailer/Payout e payload receipt/URL Checkout cifrati e nascosti dalla serializzazione. Non rigenerare APP_KEY o riportare secrets in documenti. Policies proteggono proprietario/ruolo; il cliente futuro non eredita la visibilità amministrativa del Sale né quella del wallet. `RecordAdministrativeAction` accetta soltanto admin e soggetti matching e scarta campi annidati/float/IBAN; non è un audit generico customer.

DomainModelTest verifica relazioni, cast, FK, unicità, snapshot, cifratura, append-only anche via Query Builder e redazione audit. SaleAccountingTest/FinanceConcurrencyTest verificano contabilità/rimborsi/payout, InventoryFlowTest/InventoryConcurrencyTest le quote/proposte. Le migration MySQL richiedono TRIGGER/CHECK: non rimuoverli per far riuscire un deploy cPanel.

## PLANNED — entità concettuali, nessuna tabella creata

Nomi di eventuali tabelle indicativi: colonne, enum, FK e indici andranno revisionati con le migration future, mantenendo le 13 esistenti intatte.

| Dominio / entità | Relazioni e contenuti previsti | Invarianti da progettare |
| --- | --- | --- |
| MarketplaceCatalog | Query/proiezione su InventoryItem, Retailer, InventoryImage e piani | Solo dati approvati; nessuna tabella duplicata obbligatoria e nessuna dipendenza StorePublication/FakeStoreProduct |
| Customer | Profilo acquirente collegato a User, tipo B2B/B2C e dati appropriati | Non sostituisce Retailer; cardinalità e capacità account da approvare, niente copia automatica IBAN/dati aziendali privati |
| Cart | Proprietario Customer/User o sessione se guest approvato, righe item/quantità | Riga unica per offerta nel carrello ove applicabile; prezzi indicativi, ownership e niente riserva implicita |
| Order | Customer, valuta, stato commerciale e snapshot cliente/indirizzi/totali | Chiave di conferma idempotente, snapshot dopo validazione server; separare pagamento/evasione |
| VendorOrder | Order + Retailer, propria porzione e stato evasione/totali | Una porzione per venditore nell'ordine; venditore non legge dati delle altre porzioni |
| OrderItem | VendorOrder/InventoryItem, snapshot venditore/nome/SKU/unità/prezzo/quantità/sconto/tasse | Owner/valuta coerenti, quantity_milliunits positiva e importi centesimi; dati fiscali dopo decisioni commerciali |
| StockReservation | InventoryItem, sorgente ordine o scambio/versione, quantità, expires_at/stato | Una sola sorgente coerente, idempotenza, indici item/stato/scadenza; consumo/rilascio singolo, disponibilità non negativa |
| MarketplacePayment | Order oppure conguaglio Exchange, importo/valuta, tentativi/provider IDs/esito | Sorgente esclusiva, chiavi idempotenti, niente FK a BillingCheckout o prova d'incasso da Subscription |
| Exchange | Partecipanti Retailer, coordinamento/stato e versione corrente | Partecipanti distinti e autorizzati; nessun pagamento o movimento implicito |
| ExchangeVersion | Exchange + numero, snapshot proposta e valuta/valori concordati | unique(exchange,version), contenuto immutabile dopo proposta; revisione non riusa vecchie accettazioni |
| ExchangeLine | Versione, lato/partecipante, InventoryItem, quantity_milliunits e valuation snapshot | Prodotto del partecipante, quantità positiva e centesimi interi; confronto con reservation |
| ExchangeAcceptance | Versione, partecipante, attore User, timestamp/evidenza | unique(version,participant), attore autorizzato, finalizzazione solo della stessa versione accettata da entrambi |
| Notification | Destinatario User, tipo/evento, contenuto minimo e read_at | Ownership e deduplicazione per evento/destinatario; Laravel Notifications riutilizzabile, forma di persistenza da progettare |

Cart richiederà anche righe persistenti se scelto un carrello DB; nomi/struttura sono futuri e non vanno confusi con OrderItem. Eventuali tabelle logistiche/fiscali/payment attempt non sono decise soltanto da questa lista.

### Disponibilità e transazioni future

Definire senza ambiguità quantity_milliunits come stock fisico e il momento di consumo della riserva. La disponibilità derivata sottrae le riserve attive/non scadute. Sotto lock comune si verificano quantità/approvazioni/prezzi e si crea una reservation; consumo e rilascio non possono ripetersi. Una scadenza deve essere efficace anche se il cleanup job ritarda. Non decrementare sia alla reservation sia al consumo senza una convenzione esplicita.

Ordini e scambi devono serializzare sugli stessi Retailer/articoli in ordine stabile e coordinarsi con Save/ReviewInventoryItem, payout e downgrade quando interessati. Un prezzo o quantità in proposed_data può essere antecedente a un consumo: promozione non deve rimettere in vendita stock già consumato. La suddivisione tra dati commerciali moderati e stock operativo è un cambiamento futuro da progettare. Non chiamare provider dentro transazioni SQL con lock.

### Migrazione additiva e riconciliazione

1. Preservare users/retailers, chiavi, mapping esterni, Sale/SaleItem, billing, ledger/audit/receipt e foto. Non migrare lead in ordini o creare clienti dalla sola email di una vendita senza consenso/ownership verificati.
2. Estendere ruoli/capacità dopo scelta account acquirente-venditore; provare cast enum, registrazione, dashboard e tutte le Policies con dati legacy.
3. Aggiungere Order/reservation/scambio e nuove relazioni finanziarie con vincoli espliciti; non modificare migration storiche o disabilitare trigger. Backfill solo di dati verificabili, su copia/staging.
4. Prima del doppio canale interno/esterno definire stock autorevole, mapping e origine contabile univoca; nessun secondo credito o secondo decremento per replay cross-channel.
5. Deploy compatibile con vecchi worker/cache, backup/ripristino e rollback additivo. L'installer web cPanel è solo prima installazione, non strumento di upgrade dello schema esistente.

## OPEN BUSINESS DECISION

Modello account/guest, dati B2B/B2C e retention, chi incassa, trasferimenti/settlement, IVA/fatture, commissioni conguaglio, refunds/resi/debiti, durata/rinnovo reservation e callback tardive, valuation/logistica scambi e wallet spendibile. Non fissare TTL, aliquote fiscali o provider pagamento in colonne/default prima dell'approvazione. Decisioni e sequenza in [requirements.md](requirements.md); confini in [architecture.md](architecture.md) e [integrations.md](integrations.md).

## Verifica della revisione

Suite PHPUnit esistente rieseguita il 13 ottobre 2026 in cloud, sequenzialmente, senza test nuovi o correzioni applicative:

| Comando dalla root del checkout | Esito |
| --- | --- |
| `php artisan test --compact --no-ansi` | SQLite: 215 passati, 1053 asserzioni; 6 saltati specifici MySQL |
| `DB_CONNECTION=mysql DB_DATABASE=zeromagazzino_test php artisan test --compact --no-ansi` | MySQL: 221 passati, 1089 asserzioni; inclusi i sei casi di concorrenza |

PHP 8.4.26, MySQL 8.4.11, database test dedicato e sacrificabile; nessun test preesistente fallito. Questa evidenza riguarda il comportamento attuale, non le entità PLANNED o sandbox/hosting reali. Procedura dettagliata e inventario migration in [testing.md](testing.md); distribuzione in [deployment.md](deployment.md).
