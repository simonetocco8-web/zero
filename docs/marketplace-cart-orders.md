# Carrello e ordini marketplace — 13 ottobre 2026

> Aggiornamento 16 ottobre 2026: il PDF pagamenti v1.0 e [marketplace-payments.md](marketplace-payments.md) prevalgono sui valori di sviluppo sotto. Checkout 15m, conguaglio 1440m, proposta 48h; conguaglio calcolato solo sui totali merce. Adapter Connect ora disponibile ma disabilitato per default; il wallet retailer non è spendibile e il bonus customer è separato. Nessun incasso live eseguito.

> Riallineamento successivo: [audit economico marketplace](marketplace-payments.md) e [ADR funds flow](adr/marketplace-funds-flow.md) prevalgono sui limiti storici qui descritti (fee scambio, TTL, trasporto e rimborso senza restock). I risultati di verifica riportati sotto descrivono la fase precedente.

## EXISTING — prenotazioni stock, stato aggiornato

Checkout e pagamento fake utilizzano ora StockReservation nel database: disponibilità fisica meno prenotazioni attive non scadute, lock ordinati, consumo/rilascio atomici, idempotenza checkout/intent/eventi e scadenza schedulabile. IVA indefinita continua a impedire l'incasso simulato. Le precedenti sezioni che indicano reservation PLANNED o assente descrivono lo stato storico, superato da questa implementazione. Contratti, TTL di sviluppo, limiti e verifica in [stock-reservations.md](stock-reservations.md).


## EXISTING

Carrello persistente per User, con righe identificate da InventoryItem (non SKU), quantità in millesimi e consegna. Customer e retailer possono acquistare senza cambiare ruolo; admin escluso e autoacquisto vietato, anche nel servizio interno. Una SKU uguale presso due venditori rimane due articoli distinti. Aggiungere somma quantità alla riga esistente; modificare sostituisce la quantità; rimuovere elimina soltanto la riga del proprio carrello. Limite 100 articoli distinti.

Route autenticate: `/carrello`, POST `/carrello/prodotti/{item}`, PUT/DELETE `/carrello/righe/{cartItem}`; `/checkout`, POST `/checkout/riepilogo`, POST `/checkout/conferma`; `/ordini`, `/ordini/{order}` e POST retry simulazione. Acquista dalla scheda conduce al form di aggiunta, con quantità e modalità disponibili. Le pagine cliente hanno link Carrello/Ordini. UI a card e form responsive, CSRF, ownership Policy, noindex e pagine private/no-store.

## Checkout e validazione

Carrello → indirizzo/consegna per venditore → riepilogo → conferma → Order payment_pending. La spedizione richiede un indirizzo del compratore; si può creare tramite SaveCustomerAddress o usare un indirizzo salvato. Un indirizzo comune viene fotografato separatamente per ciascun sottoordine spedito; ritiro non richiede indirizzo.

MarketplaceCart e MarketplaceQuote ricontrollano pubblicazione locale, azienda approved, autoacquisto, quantità positiva e sufficiente, valuta EUR, prezzo approvato e consegna. Prodotti non più pubblici non espongono nuovi dati nel carrello e impediscono checkout. Le condizioni provengono solo dalle colonne approvate, mai dalle proposte. Nessun prezzo/totale del browser è accettato come fonte: campi economici espliciti sono rifiutati o input extra ignorato dalla whitelist validata.

Il riepilogo salva in sessione UUID e hash server-side di dati commerciali, disponibilità, consegna, fiscalità, commissioni e indirizzo. La conferma rilegge sotto lock User → Retailer ordinati → inventario ordinato e dipendenze, usando letture correnti MySQL, e richiede un nuovo riepilogo se qualcosa cambia. La chiave UUID unica rende il replay idempotente. Creazione padre/sottoordini/righe/intento persistente e svuotamento del carrello avvengono in una transazione; un errore prima del commit conserva il carrello.

## Order, VendorOrder e OrderItem

Migration additiva `2026_10_13_000003_create_marketplace_carts_and_orders` crea carts, cart_items, orders, vendor_orders, order_items e marketplace_payments (16 migration totali). FK restrittive e unique per cart/user, cart/product, checkout key, order/retailer, vendor/product e intento/order. Nessuna modifica a migration/schema storico; down rifiuta eliminazione delle nuove tabelle con dati cliente.

Order appartiene al compratore e aggrega subtotale, trasporto, imponibile/IVA nullable e totale dei VendorOrder. VendorOrder è la porzione per Retailer, con consegna, indirizzo, snapshot venditore/fiscalità e stato proprio. OrderItem conserva nome/SKU/unità/prezzo/quantità/costo consegna, trattamento/descrizione/aliquota IVA nullable, commissione in basis point e importo commissione nullable, oltre a marca/EAN/categoria/note e disponibilità fotografata. Modifiche successive a prodotto, piano o indirizzo non ricalcolano ordini già confermati. I Models rifiutano modifica/cancellazione degli snapshot commerciali; questa protezione Eloquent non sostituisce privilegi DB e audit per SQL amministrativo.

Stati predisposti per padre e sottoordini: payment_pending, paid, preparing, shipped, ready_for_pickup, completed, cancelled, refunded. Gli stati delle porzioni sono indipendenti, consentendo spedizione/ritiro diversi; in questa fase viene creato soltanto payment_pending. Non ci sono endpoint per transizioni di evasione/rimborso o un algoritmo di rollup padre. OrderPolicy protegge il compratore; VendorOrderPolicy prepara letture della sola porzione del venditore e supervisione admin, senza introdurre ancora un pannello evasione.

Sale/SaleItem rimangono registro/import delle vendite esterne, senza conversioni o doppi accrediti. Nessun WalletTransaction, payout o entitlement viene scritto da questo checkout.

## Totali, trasporto e IVA

Importi interi, Brick Math e MoneyMath HalfUp per riga. Trasporto simulato = costo shipping_cost_cents di ogni prodotto distinto, una volta per riga e non moltiplicato per quantità; sommato per venditore e padre. Ritiro costa zero. Shipping con costo null non può essere confermato finché il rivenditore non lo definisce: null non significa gratuito. Non vengono inventati sconti di spedizione combinata.

Scelta dell'utente: quando l'IVA non ha aliquota definita, creare ordine con **totali provvisori e IVA da definire, senza simulare un incasso**. Config `marketplace.tax_rates_basis_points` vuota per default: nessuna aliquota commerciale hardcodata. Included senza aliquota mantiene il prezzo esposto, imponibile/IVA nullable; excluded senza aliquota mostra esplicitamente che eventuali imposte aggiuntive non sono comprese. Not_applicable permette imposta zero per il prodotto. Il trasporto non ha ancora una regola fiscale approvata: costo positivo mantiene il totale fiscale provvisorio e imposta trasporto unresolved.

Solo aliquote intere configurate esplicitamente per included/excluded consentono simulazione matematica di scorporo/aggravio e snapshot. Non sono dichiarate regole fiscali definitive o conformità di fatturazione. Commissione: aliquota del piano attivo verificato alla conferma; importo nullable quando la base fiscale è irrisolta. Riepilogo e ordine distinguono subtotal, imponibile, IVA, trasporto e totale provvisorio. Nessun importo irrisolto viene presentato come imposta zero.

## Payment abstraction e recupero

MarketplacePaymentGatewayInterface è distinto da StripeBillingGateway. Binding iniziale FakeMarketplacePaymentGateway: createIntent con chiave stabile `marketplace-order:{checkout_key}`, riferimento deterministico e simulated=true, esclusivamente payment_pending. Non effettua chiamate esterne o incassi e non marca paid, neppure con fiscalità completamente nota. Non usa Checkout Stripe PRO o Stripe Connect.

L'intento locale è creato nel commit dell'ordine. StartMarketplacePayment invoca il gateway **dopo commit/fuori dai lock**, valida importo/valuta/stato/provider simulato e conserva soltanto campi ammessi. Guasto ambiguo lascia ordine/intento pendenti e carrello già convertito nell'ordine; retry dal dettaglio usa la stessa chiave, senza creare un altro ordine o abbonamento.

## Stock e limiti espliciti

Lo stock viene ricontrollato, **non prenotato o consumato**. Ordini payment_pending non garantiscono disponibilità futura; questa informazione è mostrata prima e dopo la conferma. Non esistono nuovi pagamenti reali, webhook marketplace, settlement, resi, IVA del trasporto o fulfillment. StockReservation/TTL e consumo al pagamento rimangono PLANNED; non si può attivare un provider reale sfruttando semplicemente il binding fake.

OPEN BUSINESS DECISION: incasso/trasferimenti, IVA/fatture, base commissionale definitiva, IVA trasporto, conguagli/rimborsi, logistica aggregata e durata reservation. Prima di un pagamento reale servono regole approvate, reservation/coordinamento multi-canale e nuovi test di pagamento vs scadenza.

## Verifica e rilascio

SQLite: 256 test passati, 1455 asserzioni, 7 skip MySQL; MySQL: 263 passati, 1498 asserzioni. Nuovi test coprono carrelli singoli/multi-vendor, SKU uguali, quantità/consegna/manipolazioni, snapshot, trasporti, accesso cross-user, autoacquisto, stale review, IVA configurata/provvisoria, replay, guasto/retry gateway e due conferme concorrenti con una sola creazione. Suite precedenti conservate; il test del precedente placeholder acquisto è aggiornato al nuovo ingresso carrello.

Build Vite/Pint/diff verificati; 17 controlli Chromium/axe a 320/375/768/1280 px su carrello, indirizzo/consegna, riepilogo, ordine e lista, con conferma reale del flusso simulato multi-vendor su database isolato: nessun overflow o violazione rilevata nei tag WCAG 2A/2AA/2.1AA. Non è certificazione WCAG o collaudo di un incasso reale.

Deploy: backup coerente, `php artisan migrate --force`, asset della stessa release, cache/worker secondo deployment.md. Mai fresh/refresh/reset o eliminazione di dati esistenti. Test su database nuovi con infrastruttura additiva; fixture browser isolate, nessun ordine/demo aggiunto al DB applicativo.
