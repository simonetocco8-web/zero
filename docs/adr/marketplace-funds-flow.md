# ADR — Stripe Connect separate charges and transfers

Stato: APPROVATO commercialmente dal PDF v1.0 del 14 ottobre 2026, ricevuto il 16 ottobre. Attivazione operativa subordinata a verifica Stripe e fiscale. Supera le precedenti decisioni sospese.

## Decisione

Il cliente paga una sola volta alla piattaforma; un Order contiene N VendorOrder attribuiti ai rispettivi retailer venditori. Stripe Connect separate charges and transfers permette N trasferimenti dello stesso incasso. ZeroMagazzino trattiene commissione e costi attribuiti. Venditore merci e issuer documento merci resta il retailer; ZeroMagazzino emette documento per il servizio.

## Conseguenze

- Billing abbonamenti PRO e marketplace usano contratti, chiavi/configurazione e webhook distinti.
- Commissioni snapshot 200/50 bp vendite e 50 bp conguaglio al ricevente. Nessun fee di vendita applicato al valore merci dello scambio.
- Incasso, allocazione, trasferimento, payout, rimborso e recupero sono eventi distinti con chiavi provider/idempotenti e audit append-only.
- Correzione vincolante successiva del committente (16 ottobre 2026): credito retailer già maturato spendibile su acquisti e conguagli, anche in combinazione con carta; checkout 30 minuti. Sostituisce il precedente divieto del PDF, preservando Connect e le commissioni approvate. Bonus customer in ledger promozionale separato finanziato dalla piattaforma.
- Maturazione dopo consegna documentata/OTP +72h, assenza contestazioni e fondi provider disponibili; bonifici automatici lunedì. Queste condizioni non limitano diritti di recesso/garanzia.
- La piattaforma risponde tecnicamente di costi PSP/rimborsi/contestazioni sul proprio saldo. Recuperi dal seller distinti dal rimborso cliente; non presumere reversal automatici.

## Alternative escluse

Destination charge singola non rappresenta l'allocazione multi-vendor. Charge dirette separate non soddisfano il pagamento unico. Stripe Checkout degli abbonamenti non rappresenta il dominio marketplace. Il ledger interno da solo non abilita un wallet spendibile.

## Limiti

La scelta del provider è confermata; configurazione dei connected account e validazione fiscale/contrattuale non sono certificate da questo ADR. Nessun incasso live durante sviluppo. Dettagli in [marketplace-payments.md](../marketplace-payments.md).

## Storico superato

# ADR — Marketplace: venditori, commissioni e funds flow

## Stato corrente: decisione funds flow sospesa

La nuova istruzione riapre FUNDS_FLOW e tutte le regole economiche salvo le aliquote vendite FREE/PRO 200/50 bp. Questa sezione prevale sulle approvazioni storiche sotto riportate. Non è approvato un modello di incasso, un provider, un merchant of record o un assetto di fatturazione; non sono presunti Stripe Connect né il riuso del Checkout abbonamenti.

**Provider/payment architecture coerente: non determinabile finché FUNDS_FLOW è DA DEFINIRE.** Un modello di incasso piattaforma con successiva distribuzione richiede capacità marketplace/allocazione multi-vendor e verifica dei requisiti PSP, KYC/AML e responsabilità dei fondi; un modello di pagamento diretto può richiedere pagamenti separati per seller e aggregazione applicativa. Stripe Connect è una possibile soluzione da valutare solo se il modello scelto lo richiede. Stripe Checkout per gli abbonamenti non definisce nessuno di questi flussi.

Incompatibilità/prerequisiti: il pagamento padre unico attuale non basta da solo per una futura architettura di addebiti diretti multipli; mancano adattatore, routing account e riconciliazione PSP. Incasso/distribuzione dei fondi, IVA e documenti, diritti di recesso/reso, commissione/base e costi trasporto richiedono valutazione contrattuale e professionale. Non si dichiarano illegali modelli non ancora definiti; si blocca l'esecuzione finché la compatibilità non è verificabile.

I profili fake implementati sono descrizioni tecniche per test, non scelte commerciali. Default applicativi delle regole aperte sono null, con errore di validazione prima di nuovi effetti economici. Nessuna modifica dello storico o migrazione distruttiva.


Stato: decisioni commerciali APPROVATE; adattatore PSP e operazioni reali PLANNED.
Data: 13 ottobre 2026.

## Decisione

Il retailer è il venditore e documenta/fattura la merce al compratore. ZeroMagazzino vende il proprio servizio al retailer e fattura la commissione. Un ordine cliente aggrega sottoordini distinti per venditore; i relativi snapshot identificano il venditore, gli importi dovuti, la commissione e il netto prima di costi PSP e fiscalità del servizio. ZeroMagazzino non viene identificato come venditore della merce.

Un futuro PSP marketplace, ad esempio Stripe Connect, gestirà pagamento, allocazioni/trasferimenti ai venditori e trattenuta della piattaforma. Il billing Stripe degli abbonamenti PRO rimane separato. Non è ancora scelta la modalità tecnica Connect (direct/destination/separate charges and transfers); deve supportare il carrello multi-vendor ed essere valutata con PSP e commercialista. Nessun account Connect, PaymentIntent, trasferimento, payout o webhook marketplace reale viene creato in questo task.

## Commissioni snapshot

- Vendite FREE: 200 bp (2%). Vendite PRO: 50 bp (0,5%). `MarketplaceCommission` applica le aliquote approvate al codice piano; successive modifiche del record Plan non riscrivono le operazioni già concluse. I prezzi degli abbonamenti e le altre capacità del piano continuano a usare il proprio dominio.
- Scambi: solo PRO. 50 bp della sola differenza economica pagata, trattenuti a chi la riceve. Differenza zero significa commissione zero. Nessuna commissione di vendita applicata ai valori delle due merci.
- Esempio: A 100.000 cent, B 130.000 cent → A paga B 30.000 cent; piattaforma 150 cent; netto economico B 29.850 cent, prima di PSP e fiscalità della commissione.
- Arrotondamento half-up al centesimo, calcoli interi/Brick Math. La commissione e la sua base vengono fissate nello snapshot, mai calcolate di nuovo in base al piano al pagamento.

La **base commissionabile delle vendite** (lordo/netto IVA, eventuale trasporto/sconti) non è stata esplicitata nelle decisioni ricevute. Il fake mantiene la convenzione interna preesistente: imponibile merce, trasporto escluso, con base descritta nello snapshot. Il legacy SaleAccounting usa il valore riga comunicato dall'integrazione, senza attribuirgli un trattamento fiscale. Queste convenzioni non costituiscono una decisione commerciale definitiva e vanno riconciliate prima dell'adattatore reale. Non vengono introdotte aliquote IVA o assunzioni sui costi PSP.

## Fiscalità e conguaglio

Snapshot merci: trattamento, descrizione, aliquota nullable, imponibile, IVA e lordo. Nessun 22% di default. Un'aliquota presente nella configurazione è un input tecnico di test/simulazione, non una regola fiscale approvata. Se mancano i dati, imponibile/IVA/lordo definitivo restano nullable, il riepilogo è provvisorio e non avviene incasso fake.

Ogni versione Exchange rappresenta due cessioni distinte: A→B e B→A, con emittente/destinatario e importi fiscali propri. La differenza è calcolata sui debiti reciproci effettivi quando definiti; l'IVA esclusa configurata aumenta il debito della relativa parte. Una differenza provvisoria non autorizza il pagamento; se risulta zero, non autorizza neppure la conferma dello scambio o il consumo stock: serve una nuova versione con importi definiti. La commissione del servizio ha uno snapshot fiscale separato con aliquota/IVA ancora nullable; il suo trattamento definitivo, eventuale IVA aggiuntiva e documento fiscale saranno stabiliti dal commercialista. Nessuna fattura automatica viene emessa.

## Trasporto

Vendite: il compratore paga il costo concordato per venditore; zero rappresenta trasporto gratuito/offerto. Modalità, importi e indirizzi entrano nel riepilogo accettato/hash server e negli snapshot. La convenzione tecnica esistente somma un costo per riga distinta; quantità non lo moltiplica. Tariffe per pacco/ordine e IVA trasporto restano da configurare prima di pagamenti reali.

Scambi: `delivery_a` è il trasporto A→B (paga B per default), `delivery_b` B→A (paga A). `payer_side` può indicare una diversa ripartizione concordata. `settlement=external` significa che il costo viene pagato al vettore separatamente e non modifica il conguaglio; `sender` significa rimborso esplicitamente concordato al mittente, incluso nei debiti reciproci. Tale scelta è mostrata nel form e nello storico, non presunta nello storico precedente. Costi/imposte rimangono separati dalle merci. Ogni modifica crea una nuova versione/hash e richiede le due nuove accettazioni. Per costi non nulli la fiscalità trasporto è ancora irrisolta: nessun incasso simulato.

## Stock e scadenze

Checkout: `MARKETPLACE_RESERVATION_MINUTES`, default iniziale proposto 30 minuti. Scambio con differenza: `EXCHANGE_DIFFERENCE_PAYMENT_MINUTES`, default iniziale proposto 1.440 minuti. La finestra di pagamento viene registrata nella versione e parte dalla doppia accettazione. Non viene abbreviata dalla reservation del checkout o dal precedente termine della proposta. Pagamento pendente e tutte le riserve scambio hanno lo stesso termine. Scadenza: niente conferma e rilascio delle quantità; un evento tardivo è registrato per riconciliazione. Valori configurabili, validati, non TTL hardcoded. Le scadenze già persistite non vengono estese retroattivamente.

## Resi, rimborsi e recovery

Prima della spedizione/consegna: rimborso integrale fake, storno della commissione snapshot, reversal del credito seller o recovery se già disponibile; ripristino delle sole quantità effettivamente consumate e non già ripristinate. Lock condivisi con pagamento/checkout e registrazioni univoche impediscono doppio riassortimento/rimborso. Il semplice stato `refunded` non è più l'unica evidenza.

Dopo spedizione o consegna: viene aperta una pratica return/refund da valutare. Motivo, fase, importi richiesti/approvati, stato restituzione, note e audit sono distinti. Una valutazione può approvare un importo parziale, senza eseguire automaticamente rimborso o stock return. Rimborso PSP, verifica ricezione merce, politiche contrattuali e completamento operativo del reso restano PLANNED; nessuna policy universale dopo spedizione.

Wallet append-only esistente esteso con origine vendor/versione/pratica, riferimento al movimento originario, `operation_kind`, bucket pending/available/reserved e flag simulated. Il tipo legacy `adjustment` ospita i nuovi significati senza cambiare l'enum SQL esistente. Accredito, commissione, trasferimento tra bucket, payout, reversal e recovery sono nuovi movimenti firmati. Un debito non recuperato può produrre saldo negativo. Rimborso cliente (ricevuta/pratica/evento) e recupero del credito seller (ledger) sono operazioni distinte.

Le registrazioni fake sono escluse da `WalletBalance`, elenco credito legacy e richieste bonifico. Nessun saldo viene sovrascritto, nessun payout reale viene avviato. L'esigibilità automatica dei crediti marketplace e le riserve per payout reali devono essere orchestrate dal futuro funds flow, non dedotte dai soli test del ledger.

## Storico e rollout

Migration additive; nessuna eliminazione, ricostruzione/reset DB o modifica degli importi storici. Le versioni scambio e le righe già sigillate restano identiche. Snapshot storici senza nuova policy non vengono reinterpretati come accordi sulle nuove commissioni/trasporti. Prima di un pagamento reale di un ordine/proposta precedente servono controllo esplicito e, quando cambia l'accordo, nuovo riepilogo/versione e accettazione. Il nuovo ledger non inventa accrediti storici già avvenuti: eventuali differenze richiedono riconciliazione.

Verifica finale completata il 14 ottobre 2026: esiti, classificazione per componente e limiti ancora aperti in [marketplace-payments.md](../marketplace-payments.md). Lo storico precedente al riallineamento è preservato; nessun incasso o trasferimento reale è stato attivato.
