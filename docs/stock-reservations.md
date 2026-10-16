# Prenotazioni stock marketplace

> Aggiornamento 16 ottobre 2026: il PDF pagamenti v1.0 e [marketplace-payments.md](marketplace-payments.md) prevalgono sui valori di sviluppo sotto. Checkout 15m, conguaglio 1440m, proposta 48h; conguaglio calcolato solo sui totali merce. Adapter Connect ora disponibile ma disabilitato per default; il wallet retailer non è spendibile e il bonus customer è separato. Nessun incasso live eseguito.

> Riallineamento successivo: [audit economico marketplace](marketplace-payments.md) e [ADR funds flow](adr/marketplace-funds-flow.md) prevalgono sui limiti storici qui descritti (fee scambio, TTL, trasporto e rimborso senza restock). I risultati di verifica riportati sotto descrivono la fase precedente.

## EXISTING

`quantity_milliunits` rimane la quantità fisica (on_hand). La quantità acquistabile è `max(0, on_hand - reserved)`, con reserved pari alla somma DB delle prenotazioni **active e expires_at > ora**. La scadenza ha quindi effetto anche prima del cleanup. Il carrello non prenota; la conferma del checkout salva ordine, sottoordini, snapshot, payment pendente e tutte le prenotazioni nella stessa transazione. Un errore annulla l'intero checkout, mantenendo il carrello.

La migration `2026_10_13_000004` aggiunge stock_reservations, marketplace_payment_events e due colonne di versione stock. Non elimina tabelle/righe né modifica quantità, prezzi o ordini storici. FK restrittive, UUID/chiave idempotenza univoci, unicità ordine/prodotto, quantità positiva, stati e timestamp coerenti sono vincolati nel DB (CHECK MySQL, trigger SQLite). Non eseguire rollback distruttivi: `down()` rifiuta la rimozione dello storico.

Le prenotazioni hanno status active/consumed/released/expired, user/order nullable per estensioni, campi UUID, quantity_milliunits, expires_at, consumed_at/released_at e idempotency_key. `exchange_id` collega ora gli scambi PRO tramite exchange_version_id, aggiunto dalla migration successiva; il dominio scambi riserva e consuma quantità con gli stessi lock e transizioni. Dettagli in [exchanges.md](exchanges.md). Le riserve di ordini conservano order_id, quelle di scambi hanno sorgente exchange/version esclusiva.

## Lock, consumo e idempotenza

Le mutazioni seguono l'ordine comune: cliente quando necessario → retailer ordinati → inventory_items ordinati → ordine/payment → reservation ordinate. Tutti i percorsi di disponibilità autorevoli dentro transazioni leggono le reservation con lockForUpdate/get, evitando SUM su uno snapshot MySQL REPEATABLE READ precedente all'acquisizione dei lock. Query pubbliche usano un aggregato indicizzato nella stessa SELECT, senza N+1.

Il checkout riconferma lato server pubblicazione, approvazione retailer, ownership, quantità disponibile, prezzo, IVA/commissioni configurate, consegna e indirizzo. La chiave checkout restituisce lo stesso ordine; la chiave ordine/prodotto restituisce la stessa prenotazione. Se cambia il riepilogo, anche la quantità disponibile, il cliente deve rivederlo. Nessun dato monetario browser determina il totale.

StartMarketplacePayment verifica prenotazioni attive prima e dopo l'intento fake; la chiamata gateway avviene fuori dai lock SQL. La chiave intent è deterministica per ordine. I retry non creano un nuovo intento e **non prolungano la scadenza**. Errori di trasporto/esiti ambigui mantengono la prenotazione fino al retry o alla scadenza: rilasciarla subito potrebbe consentire una doppia vendita.

ApplyMarketplacePaymentResult è un confine **interno e fidato** per eventi normalizzati del gateway simulato; nessuna route webhook/callback browser non autenticata è stata aggiunta. Verifica riferimento, provider fake, modalità simulated, importo e valuta contro gli snapshot. Receipt univoca provider/event_id e hash del contenuto, elaborati nella stessa transazione di stock/payment/ordine, impediscono replay e alterazioni. Un secondo evento paid distinto restituisce already_paid; eventi falliti dopo paid non regrediscono l'ordine.

Un evento paid con fiscalità definita consuma tutte le reservation, decrementa fisicamente con guardia quantity >= richiesto e constraint non negativo, incrementa stock_version e aggiorna parent/vendor/payment a paid, atomicamente. Con **totali provvisori** restituisce tax_unresolved senza simulare incasso o decrementare. Il trasporto a costo positivo resta fiscalmente irrisolto secondo le regole precedenti.

Fallimento/cancellazione terminale rilascia tutte le prenotazioni e annulla parent/vendor. Il cliente può annullare solo il proprio ordine pendente, con CSRF e Policy; paid non viene annullato da questa azione. Un pagamento successivo a scadenza/rilascio restituisce una receipt reconciliation_required e non riprende stock già disponibile ad altri clienti. Nessun rimborso o trattamento commerciale di incassi tardivi reali è implementato.

Gli ordini payment_pending anteriori alla migration non vengono prenotati retroattivamente: non garantirebbero stock. Un retry su un ordine privo di prenotazioni valide è rifiutato; occorre un nuovo checkout.

## Scadenza operativa

`MARKETPLACE_RESERVATION_MINUTES=15` in .env.example è **un default di sviluppo, non una decisione commerciale definitiva**. Configurazione marketplace.reservation_minutes: intero da 1 a 1440; valori invalidi bloccano la creazione e fanno rollback. Prima della produzione approvare durata, callback tardive, fiscalità, provider e coordinamento stock multi-canale.

Eseguire `php artisan marketplace:expire-reservations`; è registrato nello scheduler Laravel ogni minuto. Elabora batch e ricontrolla ogni ordine sotto gli stessi lock: nessuna dipendenza dalla cache per la correttezza, esecuzioni sovrapposte/reiterate sono sicure. Il comando annulla ordini pendenti e marca expired le prenotazioni scadute, released le eventuali altre prenotazioni ancora valide dello stesso ordine. Preserva quelle consumed. Gestisce anche eventuali reservation senza ordine/scambio e delega gli scambi a ExchangeWorkflow::expire, senza scadere individualmente una sola riga dello scambio. Attivare `schedule:run` ogni minuto tramite cron oppure `schedule:work` nello sviluppo cloud.

## Modifiche inventario

InventoryRules impedisce riduzioni fisiche/proposte sotto le quantità già prenotate. SaveInventoryItem registra proposed_stock_version; ReviewInventoryItem blocca l'approvazione di proposte precedenti a un consumo/cambiamento fisico e richiede una nuova proposta. Questo impedisce che l'approvazione di una vecchia quantità ripristini unità vendute. Una sospensione/archiviazione impedisce nuovi checkout; un ordine già prenotato usa i propri snapshot e la propria scadenza.

Sale/SaleAccounting e integrazioni esterne rimangono contabilità/import, senza decremento stock duplicato o pagamento marketplace. Prima di connettere un canale esterno di vendita fisica, farlo passare dallo stesso protocollo DB: la sola reservation interna non sincronizza stock su negozi esterni.

## Verifica

StockReservationTest copre ultimo articolo, disponibilità pubblica, milliunità, rilascio multi-vendor, scadenza prima del cleanup, comando ripetuto, retry, duplicati/esiti incoerenti, IVA provvisoria, ownership cancellazione, quantità non negative, modifiche stock e proposte obsolete. StockReservationConcurrencyTest usa processi PHP separati e connessioni MySQL reali: ultima unità fra clienti diversi, prenotazioni simultanee, retry intent, evento successo duplicato e race successo/annullamento. SQLite salta esplicitamente le verifiche MySQL dei lock.

Risultati della precedente fase StockReservation (prima degli scambi): suite MySQL **284 test passati, 1603 assertion**, incluse verifiche concorrenti con processi separati; suite SQLite **273 passati, 11 saltati esplicitamente** (test MySQL), 1532 assertion. Build Vite, Pint e git diff --check passati. Chromium/axe: 16 controlli a 320/375/768/1280 px su catalogo, scheda, carrello e ordine senza overflow o violazioni dei tag WCAG 2A/2AA/2.1AA; checkout e annullamento verificati da browser con quantità pubblica aggiornata. Non equivale a una certificazione WCAG.

Upgrade del database cloud esistente verificato con hash dei dati e delle colonne precedenti di otto tabelle prima/dopo la migration: invariati. Seconda esecuzione migrate senza modifiche pendenti. Nessun reset o eliminazione di dati esistenti.
