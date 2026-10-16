# Pagamenti marketplace — decisioni correnti

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

## Architettura approvata

Un PaymentIntent piattaforma per ordine, `transfer_group` stabile, allocazioni immutabili per vendor. Non usare destination charge singola per carrello multi-vendor, né charge dirette multiple spacciate per pagamento unico. Nessun `application_fee_amount` sui separate charges: la commissione si trattiene dal netto trasferibile. Account collegato verificato per ogni beneficiario; nessun transfer prima della maturazione e riconciliazione.

Il bonus è finanziamento della piattaforma, non riduzione del credito del seller: totale ordine = carta + bonus; conguaglio solo carta al lancio. Nessun acquisto solo saldo retailer, né combinazione saldo retailer+carta. La precedente richiesta generica di wallet spendibile è superata dal PDF.

Provider fee, IVA servizio, refunds e transfer reversals richiedono registrazioni separate e attribuzione per sottoordine; rimborsare il cliente non recupera automaticamente un transfer. Firma sul corpo originale, receipt esterno unico, idempotenza e riconciliazione pagamenti tardivi obbligatori. Nessun saldo mutabile.

## Verifiche prima dell'attivazione

- Stripe: paesi/valute/account/capabilities, responsabilità per charge piattaforma, trasferimenti, fondi disponibili, tempistiche/payout del lunedì e richieste anticipate. La soluzione tecnica scelta non dimostra automaticamente conformità al ruolo contrattuale del venditore.
- Commercialista/legale: fiscalità merci, due cessioni permutative, IVA della commissione e del bonus, documentazione e termini B2B/B2C/recovery. Non dedurre aliquote dalla sola approvazione della percentuale.
- Runtime: chiavi e webhook marketplace dedicati, esclusi da Git; configurazione non live per default. Nessuna credenziale presente nell'ambiente cloud al momento della ricezione del PDF, nessun accesso API Stripe verificato.

## EXISTING — incremento successivo alla ricezione del PDF

- `MarketplaceEconomicRules` centralizza regole approvate e guardie: esecuzione disabilitata per default; il provider Connect richiede verifica operativa e fiscale esplicita, chiavi dedicate e regime IVA servizio configurato. L’ambiente PSP è esplicito (`MARKETPLACE_STRIPE_MODE=test|live`), verificato nella risposta e nei webhook; LIVE richiede un ulteriore flag. Le chiavi opache fornite da un proxy sono ammesse senza dedurne il tipo dal valore. Non riutilizza il client/chiavi/webhook degli abbonamenti.
- `StripeConnectMarketplaceGateway` implementa entrambi i contratti: Checkout carta una tantum sulla piattaforma, PaymentIntent con `transfer_group`, nessuna destination charge né application fee. Una sessione per ordine/conguaglio, chiave idempotente stabile; nessuna chiamata remota nei lock. Snapshot finanziari storici incompleti non sono convertiti automaticamente in pagamenti reali.
- Onboarding Express IT tramite Stripe ospitato (configurazione paese da verificare in attivazione), account univoco per retailer, refresh autenticato dal provider. Richiesti dati, charges/payouts e transfers attivi. Il ritorno dal browser non abilita autonomamente l'account. Beneficiario salvato nello snapshot.
- `/webhooks/marketplace/stripe`: firma sul corpo originale e tolleranza 300s, ambiente test/live, sessione/operazione/importo/valuta e chiave di richiesta verificati. Receipt evento persistente, hash e idempotenza; il redirect non conferma il pagamento. I metodi fake non possono confermare pagamenti Connect.
- `CustomerPromotionalBonus`: grant di campagna amministrativo, durata 12 mesi, ledger separato append-only con vincoli ownership SQL. Disponibilità da somme DB, prenotazione atomica con stock, consume soltanto al successo, release su annullamento/scadenza. Usa al massimo il 10% intero per difetto del totale merce IVA inclusa; mai trasporto/conguaglio. Se il bonus disponibile è inferiore, il residuo resta carta. Ripartizione bonus per vendor con resti maggiori deterministici, nessun centesimo perso. Il credito retailer resta escluso.
- Totale ordine = bonus + carta, mentre il seller mantiene il proprio lordo concordato. Il bonus non riduce imponibile merci o base commissione: è finanziamento piattaforma. Il riepilogo espone la quota richiesta; alla conferma si ricontrolla disponibilità, la scheda ordine mostra le quote definitive prima del pulsante Stripe.
- Il pagamento riuscito consuma stock e bonus una sola volta e registra credito seller **pending**, commissione e IVA servizio separate. Conguaglio accreditato con riferimento ExchangeVersion, senza Sale/SaleItem.
- Rimborsi Connect integrali prima della consegna: intent persistito prima della chiamata, idempotenza PSP, stato pending se il provider non ha completato; solo successo ripristina stock/bonus e registra storno/recovery e commissione. Evasione bloccata durante la pratica. Carta rimborsata al metodo originario; bonus ripristinato nei lotti originari, mantenendo la scadenza registrata (per rimborsi su bonus già scaduti serve gestione assistita della validità, nessuna nuova durata inventata).
- `marketplace:reconcile-payments`, schedulato ogni minuto e senza sovrapposizione, ritenta intent rimborsi pendenti e gestisce incassi tardivi dopo stock liberato. Il rimborso tardivo riguarda solo carta; nessun nuovo consumo stock/bonus o credito seller. Conserva intent/receipt anche se la sessione arriva dopo la scadenza.
- Stripe Checkout richiede una durata sessione diversa dalla riserva: il limite stock di 15 minuti resta autorevole nel DB. Una sessione ancora aperta non prolunga lo stock; l'eventuale incasso tardivo viene rimborsato dalla riconciliazione. Le carte rifiutate non consumano nulla e la riserva termina alla scadenza.

## PLANNED / limiti prima di un lancio operativo

Questo incremento implementa incasso, bonus e rimborso integrale pre-consegna, **non certifica un marketplace pronto per la produzione**. Nessuna richiesta live è stata inviata. Restano da collaudare con account Stripe reali in testmode e da completare prima del lancio:

- riconciliazione fee PSP/balance transactions, trasferimenti separati e loro reversal, payout del lunedì e richieste anticipate;
- prova consegna/OTP, contestazioni per importo e rilascio dopo 72h con fondi provider disponibili. Il rilascio dei crediti reali nel codice è bloccato: i crediti non diventano prelevabili soltanto perché l'ordine è pagato/completato;
- rimborsi parziali PSP, resi B2B/B2C assistiti, reclami e recovery dopo un trasferimento effettivo. Il dominio return/audit precedente resta disponibile, ma non applica automaticamente una policy post-spedizione;
- amministrazione campagne bonus, rinnovo assistito di bonus scaduti restituiti e report di riconciliazione dedicati;
- aggiornamento continuativo delle capabilities Connect e condizioni contrattuali/fiscali approvate.

Questi punti sono distinti dalle decisioni commerciali, ora ricevute: non richiedono di scegliere nuovamente il funds flow o di riaprire le percentuali. Le credenziali vanno inserite nelle impostazioni sicure, mai in chat; l'accesso `api.stripe.com` non è attualmente tra le destinazioni consentite nel runtime cloud. Il collaudo automatico usa esclusivamente risposte SDK simulate.

## Riallineamento dei componenti

| Area | Esito strutturale | Evidenza / limite operativo |
| --- | --- | --- |
| Order / VendorOrder / OrderItem | OK | Un incasso, N venditori e snapshot totali/commissioni/beneficiari; nessuna conversione distruttiva dello storico. |
| Cart / Customer / CustomerAddress | OK | Prezzi e ownership server-side; retailer resta retailer compratore. Bonus personale solo customer. |
| Categorie / inventario | OK | Dati approvati e stock fisico separato dalla disponibilità; nessuna revisione delle fondamenta. |
| StockReservation | OK | DB/lock/idempotenza e default approvato 15m; bonus scaduto durante checkout impedisce consumo e apre riconciliazione. |
| Exchange / Version / Line / Acceptance | MODIFICATO | Conguaglio solo merci, consegne separate; nuova versione/accettazioni per nuove condizioni e beneficiario. |
| Tax / shipping snapshots | NEEDS ADJUSTMENT operativo | Nullable e fail-closed; senza fiscalità certa, inclusa spedizione non zero, nessun incasso. Non introdotte aliquote implicite. |
| Wallet / ledger | MODIFICATO | Pending reale distinto dal fake; fee/IVA servizio separate; bonus in ledger separato. Rilascio reale e spesa wallet retailer bloccati. |
| Sale / SaleItem legacy | OK | Conservati; nuovi conguagli non diventano vendite. |
| Notification | OK | SQL persistente e mail accodate dopo commit riutilizzate; nessuna coordinata bancaria inviata. |
| Marketplace gateway / webhook | IMPLEMENTATO, collaudo PSP da eseguire | SDK Connect dedicato, firma/receipt/idempotenza, test offline. |
| Stripe subscription billing PRO | OK | Contratto, configurazione e webhook originali preservati. |
| Refund / return | MODIFICATO, NEEDS ADJUSTMENT per lancio | Integrale pre-consegna e late capture; partial/post-consegna/transfer recovery assistiti da completare. |
| Settlement / payout / dispute | BLOCKING per lancio | Nessuna liquidazione automatica finché mancano riconciliazione PSP, prova consegna/OTP e gestione contestazioni. |

Il PDF non elimina i blocchi operativi elencati: l'incremento non va attivato come servizio commerciale completo prima di risolverli.

## Migration additive di questo incremento

1. `2026_10_16_000001_add_connect_payment_tracking`: account collegati, identificatori PSP/session URL, receipt webhook.
2. `2026_10_16_000002_add_customer_promotional_bonus`: grant/ledger separati, ownership composita, trigger append-only, quote carta/bonus e snapshot.
3. `2026_10_16_000003_add_provider_refund_requests`: richieste rimborso integrale durabili, importi carta/bonus, riferimento PSP.
4. `2026_10_16_000004_add_late_capture_reconciliation`: incassi tardivi e trigger di immutabilità degli importi refund.

Nessuna colonna/tabella storica eliminata, nessun reset. Le migration down preservano lo storico finanziario.

## Verifica dell'incremento — 16 ottobre 2026

- Suite completa MySQL: **372 test passati, 2172 asserzioni**, incluse concorrenza stock/checkout/scambi e nuova concorrenza bonus; database isolati nuovi, nessun reset. Log locale `/workspace/runtime/pdf-release-mysql.log`.
- Suite completa SQLite: **355 passati, 17 esclusi perché richiedono MySQL, 2054 asserzioni**. Log `/workspace/runtime/pdf-release-sqlite.log`.
- Nuovi test: 18 casi adapter/firma/idempotenza/carta/bonus/rimborso/scadenza e 1 race bonus MySQL. Aggiornati i test delle regole approvate, del conguaglio senza trasporto e della ricostruzione del solo schema legacy.
- Build Vite, Pint e `git diff --check` passati; controllati anche whitespace dei file non tracciati e 50 link locali della documentazione.
- Chromium/axe: **24 verifiche** su 6 schermate a 320/375/768/1280 px, nessuna violazione WCAG selezionata o overflow orizzontale.
- Migrazione del database cloud esistente: hash delle colonne originarie invariati su **43 tabelle / 6 righe preesistenti**. Evidenza locale `/workspace/runtime/pdf-upgrade-evidence.json`. Verifica upgrade legacy con dati campione inclusa nella suite.
- Test SDK esclusivamente con risposte simulate: nessuna chiamata autenticata a Stripe e nessun incasso/transfer/payout reale. Draft ambiente salvato con requisito chiave marketplace, signing secret locale e dominio `api.stripe.com`; richiede revisione/salvataggio e pubblicazione dell'ambiente per il collaudo esterno.

## Storico prima del PDF (non normativo)

# Audit di riallineamento marketplace — decisioni approvate

## Decisioni correnti — prevalgono sull'audit storico

Sono approvate soltanto SALE_COMMISSION FREE=200 bp e PRO=50 bp. Tutti gli altri campi indicati dall'utente sono **DA DEFINIRE**, inclusi funds flow, commissione conguaglio, IVA, fatturazione, trasporto, annullamenti, rimborsi e durate checkout/conguaglio. Le precedenti percentuali/scadenze/assunzioni sono conservate negli snapshot storici, ma non sono default autorizzati per nuove operazioni.

| Decisione | Stato corrente |
|---|---|
| Commissioni vendite | FREE 200 bp; PRO 50 bp approvati; base da definire |
| Funds flow / provider reale | DA DEFINIRE |
| Commissione differenza, base e soggetto addebitato | DA DEFINIRE |
| IVA / fatturazione | DA DEFINIRE |
| Shipping / cancellation / refund | DA DEFINIRE |
| Durate reservation e pagamento conguaglio | DA DEFINIRE |

Configurazione in `config/marketplace.php`, validazione in `MarketplaceEconomicRules`; una stringa arbitraria o un valore zero inventato non abilita l'operazione. I soli profili implementati sono quelli descrittivi del dominio fake esistente: configurarli in produzione richiederebbe una scelta esplicita e non abilita un provider reale. Le fixture di test sono esplicite, isolate e non diventano configurazione applicativa.

Sono bloccati nuovi riepiloghi economici/ordini, accordi scambio, avvio e conferma dei pagamenti e nuove decisioni rimborso/annullamento senza regole complete. Letture, carrello e scadenza/rilascio automatico delle riserve persistite non richiedono di inventare nuove regole. Idempotenza degli eventi già elaborati e storico rimangono preservati.

La classificazione dell'audit sottostante è storica: oggi **BLOCKING per operazioni economiche nuove** finché queste decisioni restano mancanti. Struttura multi-vendor, ownership e separazione billing restano compatibili.


Questo audit riguarda i domini implementati nei Prompt 1–6. **Non autorizza l'avvio di incassi reali.** Decisioni, limiti operativi e convenzioni fiscali: [ADR funds flow](adr/marketplace-funds-flow.md).

## Classificazione dei componenti

Stato iniziale e intervento di questo audit; OK indica compatibilità strutturale, non readiness del PSP reale.

| Area | Stato iniziale | Evidenza / riallineamento | Stato dopo audit |
|---|---|---|---|
| Order | OK | Aggrega cliente, valuta, totali e sottoordini; pagamento padre separato dal billing PRO | OK |
| VendorOrder / SubOrder | NEEDS ADJUSTMENT | Venditore, consegna, indirizzo e totali già separati; aggiunto `funds_flow_snapshot` con venditore merce, commissione, netto e servizio piattaforma | OK |
| OrderItem | NEEDS ADJUSTMENT | Snapshot quantità/prezzo/IVA/fee già immutabili; aliquota vendita ora presa dalla policy approvata FREE/PRO, non da un Plan modificabile | OK |
| Cart | OK | Raggruppa per retailer/ID articolo, ricontrolla server quantità, prezzi e consegna; nessuna fiducia nei totali browser | OK |
| Customer / User | OK | Customer e retailer acquistano senza cambio ruolo; admin escluso; ownership applicata | OK |
| CustomerAddress | OK | Personale, default separati, indirizzo snapshot per sottoordine | OK |
| StockReservation | NEEDS ADJUSTMENT | DB autorevole, lock/vincoli/idempotenza già presenti; default checkout portato da 15 a 30 minuti | OK |
| Exchange | OK | PRO/approved, dominio distinto da Sale, riserve condivise, doppia accettazione, stato e audit | OK |
| ExchangeVersion | BLOCKING | Conguaglio solo valori merci, fee irrisolta e scadenza riserve troppo breve: ora due cessioni fiscali, debiti effettivi, 50 bp sul conguaglio al ricevente, termini trasporto e finestra 24h snapshot | OK strutturale |
| ExchangeLine | OK | Proprietà, quantità e valori immutabili; fiscalità di ciascuna riga ora descritta separatamente nella versione | OK |
| ExchangeAcceptance | OK | Versione/hash esatti, nuova versione invalida efficacia delle vecchie accettazioni; nuovi termini inclusi nell'hash | OK |
| Wallet / ledger | NEEDS ADJUSTMENT | Append-only DB già presente; aggiunte origini marketplace, bucket e significati reversal/recovery; fake isolato dai bonifici legacy | OK strutturale |
| Sale / SaleItem | OK / NEEDS ADJUSTMENT | Legacy separato, commissioni già snapshot; aliquote approvate centralizzate e SaleItem reso append-only anche nel DB. Non riutilizzato come vendita del conguaglio | OK per legacy |
| Notification | OK | Inbox/outbox persistenti, ownership, queue dopo commit, payload minimale; rimborso notificato solo quando eseguito in fake | OK |
| Categorie | OK | CRUD admin, FK, migrazione categoria legacy e categorie inattive non selezionabili | OK |
| Inventario | OK | Versione approvata pubblica, seller approved, quantità server-side, campi IVA/consegna e riserve; storico preservato | OK |
| Shipping vendite | OK | Separato per vendor e accettato prima del pagamento; costo zero possibile. Tariffazione per riga resta convenzione tecnica | OK strutturale |
| Shipping scambi | NEEDS ADJUSTMENT | Due flussi già presenti; aggiunti pagatore default ricevente e accordo esterno/rimborso mittente nella versione | OK |
| Tax snapshots | NEEDS ADJUSTMENT | Nessuna aliquota fissa; helper comune e due cessioni A/B con imponibile, aliquota/trattamento, IVA, lordo nullable; servizio commissione separato | OK strutturale |
| Annullamenti / refund | BLOCKING | Ricevuta fake e cambio stato non bastavano: ora pratica/audit, reversal commissione/credito, recovery e stock return univoco prima della spedizione; dopo serve valutazione | OK strutturale |
| MarketplacePaymentGatewayInterface | OK / BLOCKING reale | Contratto su Order e fake separato esistenti; multi-vendor allocabile dai sottoordini. Guardie fake intenzionali: adattatore/provider/routing reale ancora assenti | OK fake, reale PLANNED |
| Stripe subscription billing | OK | Contratti, checkout/subscription/webhook separati; nessun riuso per pagare merci o conguagli | OK |

## Modifiche database

`2026_10_13_000007_align_marketplace_funds_flow` aggiunge:

- snapshot funds flow nullable ai sottoordini e scadenza nullable al pagamento conguaglio;
- `marketplace_return_cases`, eventi append-only e `marketplace_stock_returns` con unicità pratica/riga;
- al wallet origini vendor/versione/pratica, riferimento reversal, significato operazione, bucket, flag simulated e snapshot opzionale;
- FK, indici, controlli monetari/stati/ownership e protezione append-only SaleItem.

Campi aggiunti agli snapshot JSON delle nuove versioni, senza ALTER/backfill degli accordi sigillati. Il ledger enum SQL legacy è preservato. Le nuove colonne SQLite vengono aggiunte nativamente, senza ricostruire wallet o perdere i suoi trigger. Nessun record storico viene riscritto. `down()` rifiuta la distruzione dello storico.

## Decisioni codificate e limiti intenzionali

Aliquote vendite 200/50 bp, commissione scambio 50 bp della differenza pagata al ricevente, zero fee a differenza zero; venditore retailer/servizio ZeroMagazzino distinti. Trasporto buyer nelle vendite e ricevente negli scambi, con accordi alternativi versionati. Default proposti 30 minuti checkout e 24 ore conguaglio. Gli importi/rate rimangono snapshot, indipendenti da successivi cambi del piano/configurazione.

OPEN BUSINESS DECISION: base commissionabile vendite dettagliata, fiscalità merci/trasporto/commissione, costi PSP e loro ripartizione, maturazione credito, regole dopo spedizione/resi e modalità tecnica Connect. Il fake conserva le convenzioni tecniche preesistenti e le descrive, senza trasformarle in regole commerciali approvate. La fiscalità irrisolta impedisce incasso simulato.

PLANNED: connected account/KYC, routing e trasferimenti reali, PaymentIntent, payout/provider refund, webhook marketplace firmati, riconciliazione PSP, documenti fiscali, orchestrazione operativa dei resi dopo spedizione. Restano distinti dal billing subscription.

Non vengono automaticizzati trasferimenti, payout o incassi reali. Il ledger fake serve a verificare le invarianti e non può finanziare bonifici legacy. Pratiche successive alla spedizione richiedono valutazione; il ciclo reale di restituzione/rimborso è volutamente incompleto.

## Test

`MarketplaceFundsFlowAuditTest`: aliquote e identità seller multi-vendor, cambio Plan, esempio 300/1,50/298,50, zero fee, due cessioni fiscali e conguaglio su lordo, IVA irrisolta e blocco del falso conguaglio zero provvisorio, trasporto/versioning, finestra 24h indipendente da checkout 30m, rimborso/storno/restock idempotente, recovery e saldo negativo dopo payout simulato, review dopo consegna, immutabilità/ownership ledger.

Aggiornati test precedenti sulle scadenze e sulla commissione scambio, e aspettativa stock rimborso pre-spedizione. Aggiunta gara MySQL su due rimborsi simultanei con retry pagamento dopo rimborso. Suite completa e diff verificati prima della consegna; risultati registrati qui a fine audit.

## Esito finale — 14 ottobre 2026

Audit completato riprendendo le modifiche già presenti, senza ricostruire le fasi precedenti. Corretto anche il caso di valori scambio uguali ma fiscalità irrisolta: il conguaglio zero rimane provvisorio, l'accettazione è bloccata e nessuno stock viene consumato; serve una nuova versione con importi definiti.

- Suite completa MySQL: **346 test superati, 2.011 assertion**, incluse le gare reali con connessioni separate.
- Suite completa SQLite: **330 test superati, 1.898 assertion**; **16 test MySQL saltati** per dipendenza da lock/concorrenza del motore.
- Test specifici audit: 13 casi; aggiunta inoltre la gara sui rimborsi in StockReservationConcurrencyTest. Adeguati i test preesistenti per nuove scadenze/commissione/restock e fissato l'orologio del test della finestra 24h.
- Build Vite e Laravel Pint riusciti; `git diff --check` senza errori. Verificato il diff preservando le modifiche delle fasi precedenti già presenti nel checkout.
- Chromium/axe: **124 verifiche** su 31 pagine a 320/375/768/1280 px, senza violazioni WCAG A/AA o scroll orizzontale.
- Migration 000007 applicata; upgrade verificato su fixture SQLite preesistenti con **40 tabelle / 51 righe originali preservate**. Verifica di ripetibilità sul database MySQL applicativo: **43 tabelle / 6 righe**, nessuna variazione delle colonne originali e nessuna migration pendente.

Nessun fresh/refresh/reset, rimozione di dati o pagamento reale eseguito. Restano volutamente PLANNED i servizi PSP marketplace reali; le decisioni fiscali, base commissionabile vendite e costi PSP indicati nell'ADR sono prerequisiti da risolvere prima dell'incasso reale.

## Configurazione centralizzata corrente e verifica

`MARKETPLACE_EXECUTION_MODE=disabled` è il default. I campi `MARKETPLACE_FUNDS_FLOW`, `MARKETPLACE_SALE_COMMISSION_BASE`, `MARKETPLACE_EXCHANGE_COMMISSION_BASIS_POINTS`, `MARKETPLACE_EXCHANGE_COMMISSION_BASE`, `MARKETPLACE_EXCHANGE_COMMISSION_CHARGED_TO`, `MARKETPLACE_VAT_RULES`, `MARKETPLACE_INVOICING_RULES`, `MARKETPLACE_SHIPPING_RULES`, `MARKETPLACE_CANCELLATION_RULES`, `MARKETPLACE_REFUND_RULES`, `MARKETPLACE_RESERVATION_MINUTES` e `EXCHANGE_DIFFERENCE_PAYMENT_MINUTES` non hanno fallback commerciale. Valori vuoti sono normalizzati a null; zero commissione è ammesso solo se esplicitamente configurato, non sostituisce una regola mancante. Aliquote vendite definite una sola volta nella configurazione marketplace e riutilizzate dal catalogo piani.

I profili tecnici fake supportati e tutti i parametri di regressione sono visibili in `tests/Fixtures/marketplace-rules.php`, caricata soltanto dai test e dai relativi processi di concorrenza. Non è caricata da app, config o bootstrap. Valori funds flow reali o stringhe arbitrarie sono rifiutati finché non esiste il relativo adattatore/strategia; `execution_mode=live` non è supportato. Anche con profili simulation completi il ledger marketplace non può scrivere credito non simulato/prelevabile.

Con regole mancanti la validazione impedisce nuove scritture economiche e chiamate al gateway. La conferma pagamento non consuma le riserve né accredita il seller. Sono preservati il contenuto del carrello, il pagamento pendente e le ricevute precedenti; scadenza/rilascio automatico continuano a funzionare usando le scadenze già persistite. Le scelte refund/review e i nuovi movimenti ledger sono protetti. Il billing abbonamenti e la contabilità legacy rimangono domini distinti.

Verifica corrente: **353 test MySQL superati / 2.070 assertion**; **337 SQLite superati / 1.956 assertion**, con **16 test MySQL saltati**. Sette test specifici verificano default null, ogni regola obbligatoria, profili non supportati, percentuali invalide/zero esplicito, rollback checkout, blocco pagamento senza consumo stock, scadenza con regole mancanti e impossibilità di generare credito marketplace reale in simulation. Build Vite, Pint e `git diff --check` riusciti. Probe del bootstrap applicativo: mode disabled, funds flow e durate null, FREE/PRO 200/50 bp; operazioni economiche bloccate. Nessuna nuova migration, nessuna modifica distruttiva dello storico e nessun incasso reale.
