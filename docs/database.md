# Modello dati — ZeroMagazzino

Schema implementato nel Prompt 2, con migration aggiuntive rispetto al bootstrap. MySQL 8.0.16+ (8.4 verificato), InnoDB, `utf8mb4`, PHP a 64 bit. SQLite è disponibile per la suite rapida; MySQL resta il riferimento per il deployment.

## Entità e relazioni

| Tabella / Model | Responsabilità e legami |
| --- | --- |
| `users` / `User` | Autenticazione e ruolo; `hasOne(Retailer)` |
| `retailers` / `Retailer` | Azienda, contatti, sede, IBAN cifrato e moderazione; `user_id` univoco, partita IVA univoca, revisore opzionale |
| `plans` / `Plan` | Catalogo configurabile: prezzi, limiti, scambio e commissione |
| `subscriptions` / `Subscription` | Storico piano del rivenditore, periodicità, prezzo/valuta concordati, stato e date |
| `inventory_items` / `InventoryItem` | Offerta del rivenditore, quantità, prezzi, disponibilità logistiche, condizione e stato; coppia provider/ID prodotto esterno opzionale |
| `inventory_images` / `InventoryImage` | Foto ordinate dell'offerta, disk/path e testo alternativo |
| `availability_requests` / `AvailabilityRequest` | Richiesta cliente, contatto, prodotto e rivenditore destinatario |
| `sales` / `Sale` | Ordine esterno identificato da provider/ID, stato, valuta e totale |
| `sale_items` / `SaleItem` | Righe per rivenditore, riferimento inventario e snapshot di nome/SKU/unità, prezzo, quantità e commissione |
| `wallet_transactions` / `WalletTransaction` | Ledger append-only, causale, importo firmato, valuta, maturazione, fonti e chiave idempotenza |
| `payout_requests` / `PayoutRequest` | Richiesta bonifico, importo, IBAN snapshot cifrato, stato, revisore e riferimento pagamento |
| `integration_events` / `IntegrationEvent` | Receipt durevole: provider/evento, hash del corpo originale, payload cifrato opzionale, stato e retry |
| `audit_logs` / `AuditLog` | Attore amministrativo, azione, soggetto polimorfico e snapshot redatti; append-only |

Restano le tabelle Laravel per password reset, sessioni, cache e queue. Non sono create le precedenti proposte `stock_items`, `credit_accounts`, `payout_reservations`, `external_listings` o `integration_operations`: le prime sono sostituite dalle entità sopra; un eventuale outbox per pubblicazioni sarà introdotto con il workflow di integrazione.

## Stati

- Rivenditore: `pending`, `approved`, `rejected`, `suspended`.
- Inventario: `draft`, `pending`, `published`, `change_pending`, `rejected`, `archived`.
- Condizione: `new`, `end_of_line`, `old_stock`, `damaged_packaging`.
- Richiesta disponibilità: `new`, `contacted`, `closed`.
- Bonifico: `pending`, `paid`, `rejected`; nessuno stato intermedio «approved».
- Subscription: `pending`, `active`, `cancelled`, `expired`; periodicità `monthly`/`yearly`.
- Vendita: `pending`, `paid`, `cancelled`, `partially_refunded`, `refunded` (vocabolario locale da mappare al futuro provider).
- Evento integrazione: `pending`, `processing`, `processed`, `failed`.

Enums PHP con cast Eloquent e vincoli enum nel database. I CHECK garantiscono timestamp per approvazione/rifiuto del rivenditore, pubblicazione/cambio pendente, richiesta contattata/chiusa, bonifico pagato/rifiutato ed evento elaborato. Non implementano ancora i workflow di transizione: le future Actions devono validarli e autorizzarli.

## Importi e quantità esatti

Tutti i prezzi, limiti monetari, totali e movimenti usano `BIGINT` firmati con suffisso `_cents`. I valori non finanziari che devono essere positivi hanno CHECK dedicati. `ExactIntegerCast` accetta solo interi PHP o stringhe intere: rifiuta float, booleani, valori decimali/esponenziali e overflow. Nessun passaggio di denaro attraverso float.

Valuta `CHAR(3)` maiuscola, default EUR; validazione sintattica del codice nel cast. Il seed definisce EUR. Il saldo è sempre calcolato separatamente per valuta, senza conversione FX; le future interfacce devono limitare la scelta alle valute ISO 4217 effettivamente supportate.

Commissioni in basis point: 200 = 2%, 50 = 0,5%. `MoneyMath` usa Brick Math (dipendenza esplicita), interi arbitrari per i prodotti intermedi e arrotondamento half-up al centesimo, applicato per riga/commissione. Un risultato fuori dal range degli interi supportati genera un errore, senza troncamento o conversione a float.

La quantità pubblica `$item->quantity` / `$saleItem->quantity` è una stringa esatta a tre decimali. Il database conserva `quantity_milliunits`: `"1.250"` equivale a 1250 millesimi. Il setter accetta interi o stringhe con al massimo tre decimali, rifiuta float e precisione eccedente. Inventario ammette zero; una riga vendita richiede quantità positiva. `unit` indica l'unità commerciale; la sua lista sarà definita nei form futuri.

`InventoryItem::inventoryValueCents()` moltiplica `zero_price_cents` per la quantità esatta. Totali ordine, IVA, spedizione e commissioni concordate non sono calcolati automaticamente al salvataggio: sono snapshot che le future Actions/importer devono validare. Cambiare il prodotto non modifica una riga vendita storica.

## Piani

| Piano | Mensile | Annuale | Articoli | Valore magazzino | Scambio | Commissione |
| --- | ---: | ---: | --- | --- | --- | ---: |
| FREE | 0 cent | 0 cent | 5 | 1.000.000 cent (10.000 €) | No | 200 bps (2%) |
| PRO | 6.900 cent (69 €) | 49.900 cent (499 €) | Illimitati | Illimitato | Sì | 50 bps (0,5%) |

`config/plans.php` definisce i valori iniziali; `PlanSeeder` crea le righe mancanti. Il database è la fonte runtime delle caratteristiche: ripetere il seed non sovrascrive personalizzazioni. `null` nei limiti significa illimitato; zero è un limite effettivo. Cambiare il config non aggiorna righe già esistenti: un aggiornamento delle caratteristiche va eseguito esplicitamente.

Subscriptions multiple conservano lo storico; un indice univoco sulla colonna generata `active_retailer_id` impedisce due righe `active` per la stessa azienda. Le inattive hanno chiave generata NULL e possono essere multiple. Il cambio piano deve disattivare la precedente e attivare la nuova nella stessa transazione. Prezzo e valuta della subscription sono snapshot e non cambiano aggiornando il piano.

Il seed non crea account, sottoscrizioni o inventario demo. Quote, fatturazione e criterio degli stati da conteggiare per i limiti sono responsabilità dei prossimi workflow; nessun controllo quota è ancora applicato dalle pagine placeholder.

## Ledger e saldo

`WalletBalance` ricostruisce tre valori, senza campi saldo aggiornabili su `retailers`:

- `availableCents`: somma di tutti i movimenti maturati (`available_at` non NULL e non futuro), incluse riserve/rilasci.
- `totalCents`: credito contabile complessivo, anche non maturato, escludendo riserve e rilasci che non consumano il credito.
- `reservedCents`: opposto della somma maturata di `payout_reservation` e `payout_release`.

| Tipo | Segno | Significato |
| --- | --- | --- |
| `sale_credit` | Positivo | Credito da riga vendita |
| `commission` | Negativo | Commissione della riga vendita |
| `refund` | Negativo | Storno/rimborso della riga vendita |
| `payout_reservation` | Negativo | Riduzione temporanea della disponibilità |
| `payout_release` | Positivo | Rilascio della riserva; aggiunto per distinguerlo dal rimborso di un bonifico |
| `payout` | Negativo | Consumo effettivo del credito per pagamento |
| `payout_reversal` | Positivo | Storno di un bonifico già contabilizzato |
| `adjustment` | Non zero, entrambi i segni | Rettifica motivata e auditata dal futuro workflow |

Esempio: credito 10.000 cent, riserva −6.000 → disponibile 4.000, totale 10.000, riservato 6.000. Al pagamento si aggiungono rilascio +6.000 e payout −6.000 nella stessa transazione: disponibile/totale 4.000, riservato zero. Al rifiuto si aggiunge soltanto il rilascio. Uno storno successivo del pagamento aggiunge `payout_reversal` +6.000.

Crediti/commissioni/rimborsi richiedono una riga vendita; i quattro tipi bonifico richiedono una richiesta bonifico. Non si può riferire contemporaneamente una riga vendita e un bonifico. Una chiave idempotenza globale univoca impedisce di ripetere lo stesso effetto; ogni tipo di movimento bonifico è unico per richiesta. Rimborsi parziali distinti possono avere chiavi diverse sulla stessa riga.

Un credito con `available_at = NULL` è non maturato. Se la data non era determinabile, la maturazione successiva richiede compensazione append-only del movimento pendente e nuovo movimento maturato, senza duplicare il totale. Commissione e credito relativi allo stesso incasso devono avere maturazione coerente. La finestra commerciale resta da confermare.

Eloquent rifiuta modifica/cancellazione di ledger e audit; trigger DB bloccano anche UPDATE/DELETE diretti. Correzioni tramite movimenti compensativi. Questo protegge il DML ordinario, non operazioni DBA come DROP/TRUNCATE. I saldi possono diventare negativi dopo rimborsi/storni; la gestione del debito è una decisione commerciale futura.

Il servizio di saldo è una lettura, non un servizio di autorizzazione o prenotazione. Per evitare overspending le future Actions devono autorizzare il rivenditore, bloccare la sua riga con `lockForUpdate()`, ricalcolare il disponibile e inserire riserva/richiesta atomicamente. Tutti gli scrittori che cambiano disponibilità devono usare lo stesso lock. Questi workflow non sono implementati in questa fase.

## Vincoli e indici

- `retailers.user_id` e `vat_number` univoci: un solo account titolare per profilo nella versione attuale. Normalizzazione e validazione della partita IVA saranno applicate all'ingresso del futuro form.
- SKU univoco per rivenditore; SKU NULL ripetibile. EAN non univoco: può identificare merce disponibile presso più offerte.
- Posizione immagine univoca per articolo. La cancellazione di immagini DB in cascata non elimina automaticamente i file storage: servirà il relativo workflow.
- Coppia provider/ID prodotto, ordine o evento univoca; ID riga univoco nell'ordine. Identificativi esterni confrontati case-sensitive anche su MySQL.
- Foreign key composte su richieste/righe e inventario impediscono attribuzioni a un rivenditore diverso. Le righe condividono la valuta della vendita; i riferimenti wallet condividono proprietario e valuta di riga/bonifico.
- CHECK su importi, quantità, percentuali, segni, riferimenti obbligatori, date e coppia provider/prodotto. MySQL usa CHECK reali; SQLite usa trigger equivalenti per validare INSERT/UPDATE, oltre agli enum e alle FK. SQLite controlla anche il tipo di storage integer per evitare l'affinità REAL.
- Indici su ownership/stato, code amministrative, maturazione wallet e retry eventi. FK restrittive su dati finanziari e audit; nessuna cancellazione in cascata dei movimenti.

## Sicurezza, audit e integrazioni

`iban` di rivenditore e bonifico usa il cast `encrypted` e non viene serializzato. Payload integrazione `encrypted:array`, anch'esso nascosto; hash SHA-256 del corpo originale fornito dall'intake futuro, non ricalcolato dal payload normalizzato. Non rigenerare APP_KEY senza una procedura di rotazione dei dati cifrati.

Le Policies di lettura separano proprietà e ruolo. Un rivenditore può vedere solo le proprie risorse e righe vendita; l'intero ordine multi-rivenditore, gli eventi integrazione e gli audit sono riservati all'amministratore. Nessuna nuova route espone questi dati. I servizi interni presuppongono l'autorizzazione nel chiamante; le future liste devono filtrare per owner e le future modifiche richiedono nuove capacità Policy esplicite.

`RecordAdministrativeAction` verifica il ruolo amministratore, registra attore/azione/soggetto e conserva soltanto campi di stato ammessi, scartando payload annidati, float, IBAN e secrets. Deve essere chiamato nella stessa transazione della futura azione amministrativa; non esistono ancora endpoint di approvazione o pagamento da collegare automaticamente.

Receipt univoche ed effetti wallet univoci forniscono le primitive di idempotenza, non un'integrazione attiva. Verifica firma, gestione eventi fuori ordine, job, retry e riconciliazione saranno implementati con l'adapter. Nessuno Stripe/ecommerce reale è incluso.

Per creare i trigger MySQL con binary logging attivo può essere necessario un utente di deployment con privilegi appropriati. Il container Compose è solo di sviluppo e usa `--skip-log-bin`, senza assegnare SUPER all'utente applicativo. In produzione mantenere la strategia di replica/PITR e usare il canale di migration autorizzato; non rimuovere i trigger per far passare il deploy.

## Seed, factory e verifica

```sh
php artisan migrate
php artisan db:seed --class=PlanSeeder
php artisan test
```

Factory per tutti i modelli, con stati rappresentativi (approved/rejected, published, active, contacted/closed, paid/rejected, processed, saleCredit/reservation). Dati demo soltanto tramite factory esplicite, mai seed automatico di account.

La stessa suite va eseguita su un database MySQL di test dedicato (istruzioni nel README). Test su relazioni, enum, snapshot, seed, precisione/overflow, saldo/maturazione/riserve, ownership Policy e FK, unicità/idempotenza, immutabilità e cifratura. Nessun test usa servizi Stripe/ecommerce reali.

## Giacenze e revisione (Prompt 4)

`inventory_items` conserva i dati approvati nelle colonne standard. `proposed_data` e `proposed_images` (JSON interno, mai accettato dall’HTTP) conservano una sola proposta per la giacenza pubblicata. Le foto approvate restano nelle righe `inventory_images`; i file proposti restano privati. Approvazione promuove dati/foto in un’unica transazione, rifiuto di una modifica conserva il prodotto pubblicato, elimina la proposta e mostra la motivazione. Una nuova proposta sostituisce quella precedente. SKU resta univoco anche per giacenze archiviate.

Bozza salvabile e invio in verifica esplicito. Archiviazione permessa solo per draft/pending/rejected mai pubblicati e senza ID esterno; pubblicati e change_pending richiederebbero un ritiro remoto, non implementato. La pubblicazione qui è locale: nessuna esportazione ecommerce reale.

Quote dal piano attivo e temporalmente valido, contano tutte le giacenze non archiviate, incluse bozze/rifiutate. Valore esatto: somma di millesimi di quantità × centesimi, confrontata al limite × 1000 senza arrotondamento permissivo. Durante change_pending si riserva il massimo tra valore approvato e proposto; una proposta di riduzione non libera credito prima dell’approvazione. Tutte le scritture/archiviazioni/revisioni bloccano prima il rivenditore, poi subscription e giacenze. Letture bloccanti delle giacenze evitano snapshot precedenti al lock in MySQL REPEATABLE READ. I futuri cambi piano e movimenti di stock devono usare lo stesso ordine dei lock.

Foto opzionali, massimo otto, 15 MB ciascuna; verifica MIME reale, decodifica solo JPEG/PNG/WebP, limite 40 megapixel per contenere risorse. ImageMagick auto-orienta (EXIF), ridimensiona lato lungo 1800, converte JPEG qualità 85, rimuove metadati/GPS e genera UUID. Foto nuove sostituiscono l’intero gruppo; nessun nuovo file mantiene quello esistente. Conversione prima del lock DB; file nuovi rimossi su rollback. File di versioni precedenti non più referenziate dopo moderazione restano privati: una futura pulizia periodica può rimuoverli con politica di conservazione esplicita. Le immagini vengono servite esclusivamente tramite route autenticata con Policy e cache privata, senza storage pubblico.

## Amministrazione e bonifici (Prompt 5)

Aggiunti `store_publications` (FK giacenza/amministratore, snapshot JSON, risultato, stato, timestamp, indice stato/data) e catalogo `fake_store_products` (ID univoco, revisione, dati e flag pubblicato). `inventory_items.submitted_at` traccia l’ultimo invio in verifica; per record precedenti la lista usa created_at come fallback.

Bonifici pending: SEGNA COME PAGATO richiede riferimento della disposizione bancaria e IBAN, riserva negativa maturata esattamente pari all’importo e non già rilasciata/consumata. Nella stessa transazione: lock rivenditore/richiesta, payout_release positivo e payout negativo, stato paid, timestamp/reviewer e audit. Nessuna disposizione bancaria è eseguita. Rifiuto richiede motivazione e rilascia una riserva presente/coerente; una richiesta senza riserva può essere rifiutata ma non segnata pagata. Questo vincolo richiede che il futuro flusso di richiesta bonifico crei la riserva atomicamente. Operazioni duplicate non duplicano movimenti.

Stato pagamento del piano: gratuito per FREE; non verificato per PRO perché non esistono eventi di pagamento/provider reale. Lo stato subscription non viene usato come prova di pagamento. Sospensione solo da approved, con motivazione e audit; blocca le operazioni del rivenditore, senza inventare un ritiro remoto dei prodotti.

## Richieste di disponibilità (Prompt 6)

Form pubblico GET/POST `/products/{item}/request`, senza autenticazione ma con sessione e CSRF. Il negozio può collegare questa URL, inclusa nei nuovi snapshot gateway come availability_request_url; usare un link alla pagina, senza disabilitare CSRF per POST cross-origin. Nessuna API di raccolta pubblica o esposizione dei contatti.

Disponibile solo per published/change_pending con published_at e rivenditore approved; anche change_pending usa esclusivamente la versione approvata. POST ricontrolla prodotto/rivenditore sotto lock, ricava l’ownership dal database, forza stato new e data consenso server-side. Richiesta non equivale ad acquisto o prenotazione e può chiedere più della quantità esposta.

Campi aggiunti: customer_company, quantity_milliunits interi, privacy_accepted_at e privacy_policy_version. I record storici mantengono null per quantità/consenso, senza inventare evidenze retroattive. Quantità strettamente positiva, fino a tre decimali; nome/email obbligatori; campi testo senza markup e con limiti di lunghezza; Blade esegue escaping. Honeypot contact_website obbligatoriamente vuoto; 5 POST/minuto e 20/ora per IP, condivisi fra prodotti, includendo tentativi non validi. Nessun IP memorizzato nella richiesta. Configurare trusted proxy solo quando verificato dal deployment.

Privacy: consenso obbligatorio e versione dell’informativa configurabile tramite PRIVACY_POLICY_VERSION. PRIVACY_POLICY_URL sostituisce la pagina informativa interna: l’informativa definitiva deve indicare identità del titolare, recapiti, conservazione e diritti per il deployment effettivo. Dati personali nascosti dalla serializzazione generica, consultabili solo nella lista autenticata del proprietario; header private/no-store. Nessuna comunicazione o marketing automatico.

Pagina rivenditore paginata da 15 richieste, card mobile e tabella desktop; modifica consentita soltanto allo stato (new/contacted/closed) con Policy, middleware, CSRF e lock. Timestamp contatto conservato come storico; chiusura impostata entrando in closed e rimossa riaprendo. Dashboard conta richieste nuove e mostra le ultime cinque del solo proprietario.

## Snapshot economici e invarianti (Prompt 7)

Migration 2026_10_07_000004 aggiunge sale_items.discount_cents e net_cents. Backfill del netto dei record esistenti = line_total_cents − commission_cents. Il netto nullable consente compatibilità con inserimenti legacy; il nuovo servizio lo valorizza sempre. CHECK MySQL/trigger SQLite verificano sconto non negativo e netto coerente (e tipi interi SQLite). Factory aggiornata.

PayoutRequest.pending_retailer_id è una colonna generata solo per status pending; unique(pending_retailer_id,currency) consente una sola riserva pendente. La migration non elimina né corregge eventuali duplicati legacy: vanno riconciliati prima di applicare il vincolo. Gli IBAN della richiesta sono snapshot cifrati, separati dal profilo successivamente modificabile.

Ledger: sale_credit +lordo; commission −commissione; refund −lordo rimborsato; adjustment +commissione restituita, collegata alla riga e all’evento refund. Nessun movimento zero. payout_reservation −importo; payout_release +importo; payout −importo. Il rilascio seguito dal pagamento sostituisce la riserva con l’uscita effettiva. Saldo disponibile = somma movimenti EUR con available_at non null e <= now; il netto storico maturato somma i soli movimenti legati a righe vendita, escludendo bonifici/riserve.

Receipt canoniche in integration_events per provider+ordine e provider+refund ID (chiavi hash), più receipt dell’event ID quando fornito. Hash del payload normalizzato, indipendente dall’alias evento, impedisce riuso dell’identificativo con dati diversi. Payload cifrati; transazione unica per receipt, vendita/stato e movimenti. Lock dei rivenditori in ordine crescente e letture correnti FOR UPDATE del ledger impediscono l’uso di snapshot MySQL obsoleti. Retry limitato a 5 per deadlock. Rimborsi parziali calcolano la commissione restituita cumulativa sulla commissione originale, mai sulla percentuale del piano corrente.

## Billing e inbox (Prompt 8)

Migration 2026_10_07_000005: billing_checkouts (UUID intento, owner, prezzo/periodo snapshot, sessione univoca, URL cifrata e nascosta, stato/scadenza; colonna generata open_retailer_id univoca per creating/open); billing_subscriptions (provider/ID remoto univoci, owner, checkout FK, status, invoice_paid, importo/Price/periodo, fine periodo, cancellazione a scadenza, ultimo timestamp evento). ID remoti case-sensitive su MySQL.

subscriptions.billing_subscription_id FK nullable collega ogni intervallo PRO a uno stato provider, mantenendo la cronologia esistente e il vincolo di un solo piano locale active. Downgrade chiude il vecchio intervallo e attiva FREE, non cambia le vendite. integration_events.processing_at e indice status/processing_at supportano lease/retry. Payload inbox cifrati, hash e provider/event ID univoci; nessun secret persistito. fake_store_products.archived protegge dai tentativi di ripubblicare revisioni archiviate.
