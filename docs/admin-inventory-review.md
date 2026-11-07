# Giacenze in verifica e comando spedizione

Base: `main`, commit `3ee2939033d4b19aaae61622f96b966d2d549b68` (merge PR #28).

## Problema e comportamento

Il conteggio della dashboard comprendeva `pending` e `change_pending`, ma mancava un accesso diretto alla coda unificata. La gestione era nascosta nel sottomenu Rivenditori, le azioni erano soltanto icone e il dettaglio non consentiva di confrontare tutti i dati pubblicati con quelli proposti.

`InventoryStatus::reviewValues()` e lo scope `awaitingReview` definiscono ora gli stessi stati per dashboard, navigazione, filtro «Da approvare» e controllo della revisione. Il riquadro apre `/admin/stock?status=awaiting_review`; la voce principale Giacenze espone sempre In verifica con conteggio. L'elenco mantiene i filtri e la paginazione, dando priorità alle richieste con `submitted_at DESC, id DESC`. Dopo una decisione si torna alla coda.

Su mobile ogni riga diventa una scheda con etichette e comandi visibili; sul desktop rimane una tabella. Il dettaglio completo confronta valori pubblicati/proposti, imballaggio, dimensioni in cm, peso in kg, prezzi, quantità, disponibilità e fotografie autorizzate. Testo «Modificato» e colore evidenziano le differenze. Il presenter lavora su un clone senza scrivere dati. Le foto pubblicate e proposte sono distinte; l'assenza di foto proposte riflette il comportamento già esistente di approvazione.

Le route, le Policy, i lock e le transazioni di approvazione rimangono gli stessi. I dati diventano effettivi solo dopo conferma; il rifiuto conserva la versione approvata. Nessuna modifica all'architettura pagamenti o alle tariffe Sendcloud, nessuna migration, modifica `.env`, operazione su produzione, merge o deploy.

Il componente Calcola Spedizione compare una sola volta dentro `data-product-actions`, con icona camion e pulsante condiviso. Popup, autorizzazioni e condizioni di visibilità sono conservati. La richiesta tariffe parte solo con «Calcola costo».

## Verifiche

- 56 test mirati su MySQL: 484 asserzioni; dashboard, coda, dettaglio, filtri, paginazione, autorizzazioni, approvazione/rifiuto e inventario.
- Concorrenza reale su MySQL: due processi amministrativi sulla stessa richiesta; una sola approvazione, pubblicazione e registrazione audit (7 asserzioni). Compresa anche nella suite completa.
- Suite completa MySQL: 765 test superati, 5.394 asserzioni; include regressioni marketplace, stock, ordini e Sendcloud.
- Suite completa SQLite: 733 test superati, 32 esclusi intenzionalmente perché richiedono MySQL, 5.085 asserzioni.
- Laravel Pint sull'intero codice PHP versionato e sui nuovi file: superato.
- Build Vite: superata.
- Browser Chromium/Playwright a 320, 375, 768 e 1280 px: dashboard → coda → confronto, pulsanti testuali, fotografie, approvazione dal dettaglio e rifiuto con motivazione, conteggi 2 → 1 → 0. Nessuno scorrimento orizzontale mobile, errore JavaScript o violazione axe WCAG 2 A/AA e 2.1 AA nei pannelli verificati.
- Browser marketplace: un solo comando spedizione nella barra azioni, focus ripristinato con Escape, nessuna chiamata automatica, passaggio da 30×20×15 cm/1 kg a 45×25×20 cm/2,75 kg dopo approvazione. Il calcolo esplicito restituisce la fixture ufficiale Sendcloud, senza traffico reale verso il provider.

Le suite sono state eseguite sequenzialmente: esecuzioni simultanee interferiscono con la directory condivisa di `Storage::fake`, producendo falsi errori sulle fotografie. Tutti i risultati finali usano esecuzioni isolate. Le prove browser usano database e storage separati dai test.

## File modificati

- `app/Actions/ReviewInventoryItem.php`
- `app/Enums/InventoryStatus.php`
- `app/Http/Controllers/AdminDashboardController.php`
- `app/Http/Controllers/AdminInventoryController.php`
- `app/Http/Requests/AdminFilterRequest.php`
- `app/Models/InventoryItem.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/AdminFilters.php`
- `app/View/InventoryReviewComparison.php`
- `config/navigation.php`
- `docs/admin-inventory-review.md`
- `resources/css/app.css`
- `resources/views/admin/dashboard.blade.php`
- `resources/views/components/admin-confirm.blade.php`
- `resources/views/components/area-shell.blade.php`
- `resources/views/components/icon.blade.php`
- `resources/views/components/modal.blade.php`
- `resources/views/components/shipping-calculator.blade.php`
- `resources/views/inventory/admin.blade.php`
- `resources/views/inventory/partials/admin-detail.blade.php`
- `resources/views/marketplace/show.blade.php`
- `tests/Feature/AdminInventoryReviewQueueTest.php`
- `tests/Feature/AdminTablesAndThemeTest.php`
- `tests/Feature/InventoryConcurrencyTest.php`
