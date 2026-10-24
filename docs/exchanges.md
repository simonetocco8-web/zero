# Scambi versionati tra rivenditori PRO

## EXISTING — riepilogo orientato al rivenditore

Stato, prossima azione, confronto «Tu offri / Tu ricevi», KPI e versioni precedenti collassabili: [presentazione e verifiche](exchange-detail-ux.md). Intervento solo UI; dominio, form e autorizzazioni invariati.


> Aggiornamento 16 ottobre 2026: il PDF pagamenti v1.0 e [marketplace-payments.md](marketplace-payments.md) prevalgono sui valori di sviluppo sotto. Checkout 30m, conguaglio 1440m, proposta 48h; conguaglio calcolato solo sui totali merce. Adapter Connect ora disponibile ma disabilitato per default; il credito retailer maturato è spendibile anche con carta secondo la successiva istruzione vincolante del committente; il bonus customer è separato. Nessun incasso live eseguito.

> Riallineamento successivo: [audit economico marketplace](marketplace-payments.md) e [ADR funds flow](adr/marketplace-funds-flow.md) prevalgono sui limiti storici qui descritti (fee scambio, TTL, trasporto e rimborso senza restock). I risultati di verifica riportati sotto descrivono la fase precedente.

## EXISTING

Dominio Exchange → ExchangeVersion → ExchangeLine, con ExchangeAcceptance, ExchangeCompletion e timeline ExchangeEvent. ExchangeDifferencePayment/receipt del conguaglio sono distinti dagli ordini e dal billing degli abbonamenti. Tutte le nuove tabelle e i collegamenti StockReservation arrivano tramite la migration additiva `2026_10_13_000005_create_exchanges`; nessuna giacenza, ordine, riserva o storico preesistente viene cancellato o reinterpretato. Il rollback dello schema rifiuta la rimozione degli storici.

Entrambe le parti devono essere retailer, approved, con abbonamento PRO attivo nei limiti temporali e piano exchange_available. Sono ricontrollate con letture DB correnti sotto lock alla proposta/controproposta, accettazione e successo del conguaglio. I prodotti di ciascun lato devono appartenere al relativo partecipante, essere published con published_at, exchange_available, EUR, avere quantità acquistabile sufficiente e supportare la consegna concordata. In questa fase **change_pending non è selezionabile per gli scambi**, anche se il marketplace continua a mostrarne la versione approvata per gli acquisti.

Un terzo retailer, customer o admin non accede agli scambi altrui. Partecipanti decaduti dal PRO/sospesi possono leggere lo storico e chiudere una proposta ancora aperta per rilasciare quantità; non negoziare o accettare. La ricezione di uno scambio già confermato può essere registrata dal partecipante anche dopo il downgrade.

## Proposte, versioni e accettazioni

A parte dalla scheda di un prodotto B. La proposta iniziale include tale prodotto e da 1 a 20 prodotti distinti per lato. A e B sono identità fisse dello scambio: una controproposta non le inverte. I valori concordati sono **unitari**, in centesimi EUR; il valore della riga usa MoneyMath con milliunità e arrotondamento HalfUp, poi si sommano le righe per lato. Valori non negativi, quantità positive, ownership e formati esatti sono verificati server-side; float e overflow monetari vengono rifiutati.

Ogni versione conserva nomi/codici/condizione/unità/retailer dei prodotti, quantità, valori unitari e merci, aziende, consegna A/B, costi trasporto separati, note, trattamento IVA e commissioni descrittive. Il riepilogo ha hash SHA-256 e scadenza immutabile. La versione viene costruita e **sigillata nella stessa transazione**, prima di essere corrente: trigger DB impediscono modifiche/eliminazioni e anche l'inserimento successivo di nuove righe in una versione sigillata, incluse quelle superate senza accettazioni.

Le accettazioni sono append-only, univoche versione/retailer, con utente proprietario e hash della stessa versione. Creare una proposta non implica accettarla. Una controproposta crea numero successivo, non modifica i record precedenti: le vecchie accettazioni sono conservate ma non contano per la nuova versione. Richieste con versione/hash superati vengono rifiutate; retry della stessa proposta/controproposta o accettazione sono idempotenti. Una chiave riutilizzata per contenuti diversi è rifiutata.

Le controproposte sono ammesse anche durante l'attesa del conguaglio, prima della conferma. In un'unica transazione rilasciano le riserve della versione vecchia, annullano il relativo intento fake e richiedono due nuove accettazioni. Se la nuova proposta è invalida, il rollback conserva la versione/riserve precedenti. Versioni confirmed/completed non sono modificabili, rifiutabili o annullabili in questo dominio; resi/amendment richiedono una fase futura.

## Valori e conguaglio

A = 1.000 €, B = 1.300 € → A paga 300 € a B. A > B → B paga la differenza ad A. Valori uguali → differenza zero, payer/payee NULL. Il calcolo usa solo valori merci, senza aggiungere automaticamente trasporto, imposte o commissioni.

delivery_a/delivery_b rappresentano le merci consegnate da ciascun lato alla controparte: pickup o shipping, destinazione concordata e shipping_cost_cents separato. Shipping richiede destinazione; pickup richiede trasporto zero e conserva la località del proprietario. Non viene inferito un debitore del trasporto o compensato il costo con il conguaglio.

Aliquote/trattamenti prodotto sono snapshot; **imposte dello scambio e commissioni non vengono calcolate**. Le commissioni conservano i basis point del piano, ma base/importo dello scambio restano nullable e separati. Il fake simula esclusivamente il conguaglio merci: questo importo non rappresenta un totale definitivo per un futuro provider reale.

La precedente scelta dell'utente sull'IVA rimane applicata: senza trattamento fiscale definito non si simula un incasso. In questa fase un conguaglio fake può riuscire soltanto quando tutte le merci dichiarano IVA non applicabile e il trasporto è zero; gli altri casi sono payment_provisional/tax_unresolved. Aliquote configurate per gli acquisti non approvano automaticamente il trattamento fiscale di uno scambio. Lo scambio a valore uguale può confermarsi senza alcun pagamento; le informazioni fiscali restano descrittive.

## Stock e stati

proposed/countered → awaiting_acceptance → accepted (transizione e timeline atomica). La seconda accettazione ricontrolla tutti i prodotti e crea StockReservation per **tutte** le righe nella stessa transazione. Una vendita intervenuta prima blocca la doppia accettazione e fa rollback della seconda acceptance: nessuna riserva parziale.

I lock Retailer ordinati precedono tutti i lock dello scambio/prodotti: condividono il mutex DB del normale checkout. Nessuna cache decide la disponibilità. StockReservation collega exchange_id e exchange_version_id, con FK/vincoli di appartenenza, esclusione order_id e unicità versione/prodotto. Disponibilità pubblica e carrello sottraggono anche le riserve attive degli scambi.

- Differenza zero: le prenotazioni vengono create e consumate atomicamente, decrementando lo stock fisico e incrementando stock_version; stato confirmed, nessun intento di pagamento.
- Differenza positiva: stato awaiting_difference_payment, stock fisico invariato e prenotazioni active. Non diventa confirmed finché l'esito fake non è paid.
- Successo fake valido: consumo/decremento di tutti i lati, payment paid e confirmed nella stessa transazione, senza duplicazione. Le vecchie proposte inventario diventano obsolete tramite stock_version.
- Rifiuto/annullamento, fallimento terminale del conguaglio o scadenza: rilascio di tutte le riserve ancora active; quelle consumed non si riaprono.
- confirmed → completed solo dopo che entrambe le parti confermano ricezione merci. Due record ExchangeCompletion univoci evitano che una sola parte completi tutto o che un retry ripeta la transizione. La ricezione non decrementa nuovamente stock.

## Gateway, retry ed eventi

FakeMarketplacePaymentGateway implementa anche ExchangePaymentGatewayInterface::createDifferenceIntent: namespace `exchange:{uuid}:version:{id}`, distinto da marketplace-order e dal billing Stripe PRO. Il gateway viene chiamato fuori dai lock SQL, con verifica dello stato e delle riserve prima/dopo; un errore ambiguo conserva la riserva fino a retry/scadenza. Il retry mantiene l'intento e la scadenza originali.

La UI consente la simulazione esplicitamente indicata come **nessun addebito**, soltanto al payer autenticato/partecipante PRO. Versione/hash, importo, valuta e riferimento sono ricavati/verificati server-side. ApplyExchangePaymentResult è un adapter interno fidato: nessuna route callback/webhook non autenticata o provider reale introdotto. Eventi hanno provider/event_id univoci e hash del payload; replay identico restituisce il risultato salvato, contenuto differente viene rifiutato, secondo paid distinto non decrementa di nuovo. Fallimenti dopo paid non regrediscono.

Esiti paid dopo scadenza, rilascio, controproposta o perdita dei requisiti PRO registrano reconciliation_required e non sottraggono quantità a un nuovo acquirente/scambio. Un evento della vecchia versione non altera stato/riserve della nuova. Non sono implementati incassi reali, trasferimenti, rimborsi o commissioni wallet; il fake non può essere sostituito direttamente da un provider senza il relativo progetto fiscale/commerciale e autenticazione eventi.

## Scadenza e UI

`MARKETPLACE_EXCHANGE_PROPOSAL_HOURS=48` è **un default di sviluppo**, configurabile da 1 a 168 ore, non una scelta commerciale definitiva. Ogni nuova versione crea una nuova scadenza proposta. Alla doppia accettazione la scadenza operativa diventa il minimo tra scadenza proposta e `MARKETPLACE_RESERVATION_MINUTES` (default approvato 30 minuti). Retry non estendono il termine. Le riserve scadute non contano nella disponibilità anche prima del cleanup.

`php artisan marketplace:expire-exchanges` è idempotente e schedulato ogni minuto. Anche marketplace:expire-reservations delega al cleanup scambi; esecuzioni sovrapposte sono sicure grazie ai lock e al ricontrollo. Ordini rimangono nel proprio percorso; riserve exchange non vengono trattate individualmente come riserve anonime. Il cleanup cancella gli intenti pendenti e registra expired senza eliminare lo storico o scadere confirmed/completed.

Area `/retailer/scambi`: Tutti, Ricevuti, Inviati, Da accettare, In pagamento, Completati, Rifiutati/scaduti (include annullati). Da accettare verifica l'assenza della propria acceptance sulla versione corrente; filtri paginati/scopati per partecipante, join/eager loading senza N+1. I termini trascorsi sono mostrati/scopati come scaduti anche prima del cleanup. Scheda con riepiloghi di tutte le versioni, accettazioni correnti, timeline con attore, consegna/costi e azioni con versione/hash. Form mobile a card con selezioni multiple e quantità/valori esatti; la selezione checkbox funziona anche senza JavaScript.

## OPEN BUSINESS DECISION

Durata commerciale e rinnovi, fiscalità/IVA e fatture scambi, base/importi commissioni, debitore e aggregazione trasporto, assicurazione/logistica, provider/modello di incasso/trasferimenti conguaglio, refund/chargeback e modifiche dopo conferma, stock multi-canale e dispute. I default e il fake servono allo sviluppo e non approvano tali regole.

## Verifica

ExchangeDomainTest copre accesso PRO/PRO, FREE/customer/terzi/sospensioni/date piano, prodotti/quantità/ownership/consegna, controproposte e vecchie acceptance, versioni immutabili e non estendibili, snapshot, doppia accettazione, merci/trasporto separati, valori uguali, conguaglio A→B/B→A, IVA irrisolta, release/cancel/reject, scadenza e pagamento tardivo, replay/eventi alterati, completamento reciproco e flussi HTTP/filtri. ExchangeConcurrencyTest usa processi PHP e connessioni MySQL separate per vendita normale vs scambio sull'ultima unità (anche retailer come compratore), accettazioni simultanee/retry, eventi fake duplicati e scadenza vs paid tardivo.

Risultati finali: **313 test MySQL passati, 1792 assertion**, inclusi i processi concorrenti; **298 test SQLite passati, 15 saltati esplicitamente** perché richiedono MySQL, 1689 assertion. Storico con nove versioni verificato con un numero fisso di query (massimo 16), senza N+1. Build Vite, Pint e git diff --check passati.

Chromium/axe: **28 controlli a 320/375/768/1280 px** su lista, proposta, versione iniziale, controproposta, storico, conguaglio pendente e completamento, senza overflow o violazioni dei tag WCAG 2A/2AA/2.1AA. Eseguito da browser il percorso proposta → accettazione A → controproposta B → nuove accettazioni B/A → conguaglio fake → ricezione A/B → completed, verificando lo stock pubblico decrementato una volta. Non equivale a una certificazione WCAG.

Upgrade del database cloud esistente: hash dei dati nelle colonne precedenti di **dieci tabelle** invariati prima/dopo migration. Seconda esecuzione migrate senza operazioni pendenti. Nessun migrate:fresh, migrate:refresh, reset o cancellazione di dati esistenti. Entrambi i comandi di scadenza sono presenti nello scheduler ogni minuto.
