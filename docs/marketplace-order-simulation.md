# Completamento pagamento simulato ordini

## EXISTING

`POST /ordini/{order}/simula-pagamento`, nome `orders.payment-simulate`, permette di completare un ordine fake. Richiede autenticazione, accesso marketplace, policy `OrderPolicy::view`, limite 10 richieste/minuto e `marketplace.economic_rules.execution_mode=simulation`. Un GET non modifica nulla; la route è esclusivamente POST e mantiene la protezione CSRF del gruppo web.

Il controller ricarica ordine e pagamento e richiede provider `fake_marketplace`, `simulated=true`, reference non vuota e stato ordine/pagamento `payment_pending`. Modalità diverse (compresa `stripe_connect`) ricevono 403; stato o pagamento incompatibile riceve 409. Un ordine/pagamento già pagato risponde senza nuovi eventi economici. Nessun parametro finanziario del browser viene utilizzato: importo, valuta, reference e provider provengono soltanto dal database; parametri di alterazione del pagamento sono vietati dalla validazione.

Il controller genera un identificatore evento UUID server-side e chiama esclusivamente `ApplyMarketplacePaymentResult`. Il servizio conserva verifiche, lock, receipt idempotenti, IVA, consumo delle prenotazioni, stock, credito/bonus, ledger e notifiche. Due richieste concorrenti vengono serializzate dai lock già presenti nel servizio; una seconda richiesta sequenziale termina senza registrazioni aggiuntive. Nessuna chiamata al gateway Stripe Connect o al billing PRO.

Gli outcome `consumed`, `already_paid`, `tax_unresolved` e `reconciliation_required` hanno messaggi espliciti; esiti diversi ed errori mostrano un messaggio neutro senza dettagli interni. In particolare una POST diretta con IVA provvisoria non conferma il pagamento: il servizio restituisce `tax_unresolved`. Prenotazioni scadute/non valide seguono il dominio di riconciliazione preesistente.

## Interfaccia

Nella pagina ordine il pulsante **Simula pagamento riuscito** compare solo in modalità simulation, ordine/pagamento pendenti, pagamento fake simulato con reference e totali fiscali definiti. Il form contiene soltanto CSRF e una nota sull’assenza di addebiti reali. **Riprova avvio pagamento** rimane soltanto quando il pagamento esiste e la reference è null. Dopo l’esito riuscito la pagina mostra «Stato: Pagato» e «Pagamento simulato: nessun addebito reale», senza pulsante di simulazione.

## Confini e verifica

Nessuna migration, modifica a `.env`, percentuali, fiscalità, credito, bonus, TTL, cron, webhook, Stripe Connect o abbonamenti PRO. Attivazione delle modalità resta configurazione esterna; questa implementazione non la cambia.

`MarketplacePaymentSimulationTest` esercita l’endpoint HTTP con il servizio reale: più venditori, stock/ledger/notifiche, idempotenza con bonus e credito parziale, mode/provider/reference non ammessi, IVA irrisolta, scadenza, proprietà dell’ordine, POST/CSRF, input manipolati, rate limit e passaggio retry → simulazione → pagato. Il gateway Connect è esplicitamente verificato come non invocato.

### Risultati locali

- SQLite completa: 502 passati, 0 falliti, 28 saltati esplicitamente.
- MySQL completa: 530 passati, 0 falliti, nessun test saltato.
- Nuovi test simulazione: 18/18 passati su entrambi i database.
- Pint e build Vite: superati. Pagina ordine pendente/pagata verificata in Chromium a 320/375/768/1280 px; POST/CSRF, assenza di campi finanziari, focus e target minimo 44 px controllati.
- Nessuna modifica a `.env` o migration; nessun pagamento reale.
