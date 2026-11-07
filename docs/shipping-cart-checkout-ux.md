# UX spedizioni, carrello e checkout

Base: `main` aggiornato, commit `7f52f73` (merge PR #29). Branch `feat/shipping-cart-checkout-ux`.

## Comportamento

Il popup usa i token dei temi chiaro/scuro per sfondo, label, input, radio, checkbox, link ed errori. Le informazioni del collo sono in cm e kg; le card mostrano corriere, servizio commerciale, prezzo indicativo e tempi stimati (ore convertite in giorni arrotondati per eccesso da 24 ore). «Più economica» dipende dal prezzo, anche in caso di parità. Non viene promessa una data di consegna.

Codici opzione, identificativi, condizioni tecniche, breakdown, label, protezione e supplementi non vengono renderizzati. Tutti i dati del provider restano nel payload e negli snapshot originali. Nessuna modifica al provider Sendcloud v3 o al calcolo delle tariffe. Le richieste partono solo da «Calcola costo». Modificare i dati annulla le risposte client in corso e la selezione precedente; il server verifica nuovamente il fingerprint alla selezione.

Nel carrello il calcolatore si apre direttamente per rivenditore, includendo tutte le righe, quantità e regole di imballaggio. Dopo la scelta ritorna al carrello; «Modifica spedizione» consente di ricalcolare/cambiare servizio. I preventivi sono verificati con `ShippingQuoteService::valid`: proprietà, stato, scadenza, provider, prodotti, quantità, imballaggi, origine e destinazione. Nessun riuso di dati di altri utenti o fallback ai costi legacy per righe dinamiche.

`PurchaseSummary` è un servizio di sola lettura condiviso da aggiunta prodotto, carrello e checkout. Riusa `MoneyMath::lineTotalCents` e `MarketplaceTaxSnapshot::amount`, con centesimi interi e gli arrotondamenti già previsti. Una spedizione dinamica viene conteggiata una sola volta per rivenditore. Per il ritiro il costo è zero; i costi legacy restano una volta per riga distinta secondo la regola esistente. Nei riepiloghi ordine già calcolati, `quoted()` adatta gli snapshot senza ricalcolare prezzi storici.

Il componente `purchase-summary` mostra merce, spedizioni calcolate e totale stimato soltanto se tutte le componenti e le imposte sono determinate. Gli importi parziali non diventano un totale generale. Una tariffa dinamica con trattamento fiscale sconosciuto resta visibile come costo indicativo, senza un totale fiscale inventato. IVA inclusa non viene aggiunta una seconda volta.

L’aggiunta prodotto ha una stima server-side aggiornabile per quantità/modalità, distinta dal riepilogo dell’intero carrello. Il riepilogo definitivo e la conferma esistenti riusano la stessa presentazione degli importi; con dati fiscali incompleti non viene mostrato un residuo carta come importo definito.

## Confini del checkout

`config/shipping.php` e `shipping.checkout_verified=false` rimangono invariati. Con spedizione dinamica il riepilogo è informativo: espone i componenti disponibili e le CTA per modificare/calcolare, senza form di conferma ordine, chiave di conferma in sessione o richiesta di pagamento. Le azioni server di ordine e i controlli di checkout esistenti continuano a bloccare la spedizione dinamica. Il ritiro conserva il proprio flusso autorizzato.

Nessuna modifica a wallet, commissioni, riserve stock, gateway di pagamento, credenziali, `.env`, configurazioni o dati di produzione. Nessuna migration, merge o deploy.

## Screenshot e verifica browser

Tutti i dati nelle immagini sono fixture locali e le risposte Sendcloud sono simulate dal file ufficiale già presente nei test. Nessuna chiamata reale al provider.

- [Popup desktop, tema scuro](images/shipping-ux/popup-desktop-dark.png): dati collo, indirizzo alternativo, label leggibili e card semplificate.
- [Popup mobile, tema chiaro](images/shipping-ux/popup-mobile-light.png): card, prezzi e avvertenza indicativa.
- [Carrello mobile, tema scuro](images/shipping-ux/cart-mobile-dark.png): importi per rivenditore e riepilogo generale.
- [Checkout informativo desktop](images/shipping-ux/checkout-informativo.png): costi disponibili e assenza della conferma ordine.

Chromium/Playwright: popup a 375 e 1280 px in entrambi i temi; carrello a 320/375/768/1280 px in entrambi i temi. Verificati contrasto anche in hover, assenza di overflow orizzontale, WCAG 2 A/AA e 2.1 AA con axe (zero violazioni nelle pagine/pannelli verificati), errori in italiano, indirizzo salvato/alternativo, focus con Escape, due rivenditori e più prodotti del primo, selezione/modifica dal carrello e riepilogo bloccato. Nessun errore JavaScript. Il limite di 10 richieste/minuto rimane attivo durante le prove.

## Test automatici

12 nuovi test coprono riepiloghi incompleti, colli multipli, due venditori, cambio servizio, scadenza, modifiche a imballaggi/quantità/destinazione, ritiro, arrotondamento IVA, importi fiscali sconosciuti, ownership, importi browser non attendibili, checkout informativo senza ordini/riserve/pagamenti, compatibilità legacy e payload Sendcloud completo con UI semplificata.

- Mirati MySQL: 67 superati, 546 asserzioni.
- Suite complete MySQL/SQLite: risultati finali nella PR.
- Pint su tutti i file PHP versionati e nuovi: superato.
- Build Vite: superata.

Le prime esecuzioni MySQL sono state invalidate dall’esaurimento del filesystem del runtime. I risultati finali usano un’istanza MySQL 8.4 separata con dati temporanei e spazio disponibile, senza cancellare database esistenti. Browser e PHPUnit usano storage/database distinti; MySQL e SQLite sono eseguiti sequenzialmente per evitare interferenze tra fixture filesystem.

## File modificati

- `app/Http/Controllers/CartController.php`
- `app/Http/Controllers/CheckoutController.php`
- `app/Http/Controllers/MarketplaceController.php`
- `app/Http/Controllers/ShippingQuoteController.php`
- `app/Http/Requests/PurchaseEstimateRequest.php`
- `app/Http/Requests/ShippingQuoteRequest.php`
- `app/Services/PurchaseSummary.php`
- `app/Services/Shipping/ShippingQuoteService.php`
- `docs/images/shipping-ux/cart-mobile-dark.png`
- `docs/images/shipping-ux/checkout-informativo.png`
- `docs/images/shipping-ux/popup-desktop-dark.png`
- `docs/images/shipping-ux/popup-mobile-light.png`
- `docs/shipping-cart-checkout-ux.md`
- `resources/css/app.css`
- `resources/js/app.js`
- `resources/views/cart/add.blade.php`
- `resources/views/cart/checkout.blade.php`
- `resources/views/cart/index.blade.php`
- `resources/views/cart/review.blade.php`
- `resources/views/cart/shipping-review.blade.php`
- `resources/views/components/order-totals.blade.php`
- `resources/views/components/purchase-summary.blade.php`
- `resources/views/components/seller-shipping-summary.blade.php`
- `resources/views/components/shipping-calculator.blade.php`
- `routes/web.php`
- `tests/Feature/AdminInventoryReviewQueueTest.php`
- `tests/Feature/PurchaseSummaryTest.php`
