# Dettaglio vendita del rivenditore — UX/UI

## EXISTING — 24 ottobre 2026

La pagina `/retailer/vendite/ordini/{vendorOrder}` usa il numero del sottoordine come riferimento principale, con ordine cliente e data di ricezione secondari. La data usa `vendor.created_at`, già caricato; non viene richiesta la data del padre, che il controller non seleziona. Badge e stepper descrivono lo stato esistente: pagamento, preparazione, spedizione/ritiro e consegna. Annullamento e rimborso mostrano la chiusura della consegna senza inventare passaggi completati.

La card «Prossima azione» precede il contenuto operativo e riutilizza integralmente `vendor-fulfilment`: stessa policy, POST con override PATCH, CSRF, target di stato e campi facoltativi per corriere/tracking. I testi spiegano l’azione corrente; stati terminali, pagamento pendente o autorizzazione negata non introducono nuove azioni. Workflow, transizioni e controlli backend restano invariati.

Desktop: contenitore `max-w-6xl`, prodotti/cliente/consegna nella colonna principale e riepilogo/commissione nella colonna laterale, con proporzione circa 2/3 e 1/3. Mobile: stato, stepper, prossima azione, prodotti, cliente, consegna/tracking, riepilogo, commissione e storico in colonna singola. Nessuna tabella rigida o scroll orizzontale della pagina.

## Dati e informazioni fiscali

Le card prodotto mostrano nome, SKU, quantità, prezzo unitario, totale merce e trattamento IVA dai dati conservati. I dettagli fiscali sono `<details>` chiusi inizialmente: per i nuovi snapshot viene riusato `line-tax-breakdown`, mantenendo anche base commissione e IVA spedizione; lo storico senza tale policy mostra i valori persistiti disponibili. Importi non determinati non vengono convertiti in zero.

Il riepilogo legge `subtotal_cents`, `shipping_cents`, `total_cents`, `taxable_cents` e `tax_cents` senza ricalcolarli. «Prodotti (prezzi indicati)» conserva la semantica originale netto/lordo dello snapshot; non viene presentato come un nuovo imponibile aggregato. Il totale cliente è prioritario, con indicazione esplicita della provvisorietà. Il dettaglio fiscale e l’eventuale rimborso restano consultabili.

La commissione è separata dal totale cliente: base, aliquota, importo e IVA del servizio derivano esclusivamente da `funds_flow_snapshot`. Nessuna deduzione del piano FREE/PRO dall’aliquota o dall’abbonamento attuale; il nome del piano viene mostrato solo se registrato nello snapshot. Le percentuali del nuovo riepilogo usano la virgola italiana, senza cambiare i valori. Non vengono aggiunte somme, ricalcoli IVA o commissioni.

Cliente: soltanto nome ed email già presenti nello snapshot. Consegna: indirizzo già caricato, trasporto e aliquote presenti negli snapshot delle righe, oppure sede/località del rivenditore per ritiro. Nessuna query per immagini o prodotto vivo. Tracking presente in un blocco distinto, con copia tramite Clipboard API e Alpine già disponibile; il testo è letto dal DOM escapato, non interpolato in JavaScript. Sono previsti feedback accessibili di successo o copia non disponibile. Lo storico usa una timeline verticale con eventi già caricati, senza query per l’autore.

## File e isolamento

- View `resources/views/retailer/sale.blade.php`.
- Nuovi componenti `vendor-order.status-progress`, `vendor-order.products`, `vendor-order.delivery`, `vendor-order.financial-summary`.
- Nuovo `VendorOrderPresentationTest` con 15 casi: stati e transizioni, spedizione/ritiro, tracking e escaping, più prodotti, snapshot fiscali/commissioni, cambio piano successivo, rimborso, provvisorietà, chiusure, autorizzazioni, GET senza mutazioni e assenza di query decorative.

I componenti condivisi `vendor-summary`, `vendor-fulfilment`, `order-totals` e `line-tax-breakdown` non vengono modificati. Test espliciti proteggono anche i dettagli consegna cliente e amministrazione. Nessun cambiamento a controller, route, policy, servizi economici, database, migration o `.env`.

Browser Chromium: undici situazioni, cinque larghezze (320/375/768/1280/1536 px), verifica delle colonne, nessun overflow, target minimi 44 px, apertura dei dettagli con Enter e copia del tracking da tastiera con feedback. Non è una certificazione WCAG completa.

Risultati locali: test pertinenti 48 passati; SQLite completa 517 passati, 0 falliti e 28 saltati; MySQL completa 545 passati, 0 falliti e nessuno saltato. I 15 nuovi casi passano su entrambi i database. Pint, Vite e compilazione Blade superati. `.env` verificato invariato.
