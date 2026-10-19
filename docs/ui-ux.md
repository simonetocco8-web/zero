# UI e UX

## Refresh coordinato navy/aqua — 19 ottobre 2026

EXISTING: tema scuro centralizzato in `resources/css/app.css`, applicato a layout, accesso/registrazione, dashboard, catalogo, card, badge, form, notifiche e dialog. I token `canvas`, `surface`, `raised`, `line`, `control`, `ink` e `muted` separano le superfici dal testo; aqua identifica CTA e focus, con testo navy sui pulsanti pieni. Stati di errore/successo mantengono etichette e colori distinti. Nessun font remoto, framework o dipendenza applicativa aggiunti.

La homepage ospiti usa due sole CTA nel contenuto: **Accedi** e **Registrati**. La registrazione rivenditore mantiene il collegamento al profilo cliente. Header/footer della sola pagina iniziale omettono le azioni duplicate; Privacy e navigazione delle altre pagine restano disponibili. La comunicazione riguarda vendita dell’invenduto, liquidità e scambi PRO, senza rendere pubblico il catalogo riservato.

Eliminazione articoli e gestione foto riutilizzano Actions/Policies esistenti. Il dialog di eliminazione esplicita indisponibilità nel catalogo, conservazione dello storico e blocco prenotazioni attive. La gestione foto identifica la copertina, spiega l’ordine successivo e lo stato senza immagini; selezionando Elimina disabilita il file di sostituzione della stessa foto. La validazione server del conflitto rimane attiva anche senza JavaScript. Foto pubblicate aggiornate solo dopo moderazione; file privati storici conservati secondo [inventory-access.md](inventory-access.md).

Verifiche e file interessati in [ui-refresh-20261019.md](ui-refresh-20261019.md).

## Aggiornamento approvato — 18 ottobre 2026

Marketplace riservato agli utenti autenticati (rivendite approved), homepage ospiti con accesso/registrazione; eliminazione recuperabile articoli owner/admin, gestione foto con moderazione e quantità senza zeri decimali superflui. Questo aggiornamento supera le descrizioni storiche del catalogo pubblico. Contratti e migration in [inventory-access.md](inventory-access.md).

## EXISTING — aree riservate marketplace e notifiche

Aree cliente, vendite retailer e registri admin integrate con ownership e paginazione server-side. Consegne con transizioni serializzate, rimborsi integrali solo fake e notifiche Laravel persistenti con email accodate dopo commit. Credito/bonifici riutilizzano il dominio esistente; nessun nuovo incasso o movimento automatico. Stato aggiornato, contratti e operatività in [marketplace-private-areas.md](marketplace-private-areas.md); le indicazioni storiche PLANNED sulle aree sono superate da questa implementazione.

## EXISTING — scambi PRO versionati

Exchange, versioni/righe immutabili e sigillate, doppia acceptance sulla versione corrente, conguaglio merci separato da trasporto/IVA/commissioni, stock condiviso con gli acquisti, gateway fake, scadenza, completamento reciproco e area Scambi sono ora implementati. Solo retailer PRO attivi e approved negoziano; fiscalità irrisolta impedisce l'incasso simulato. Migration additiva, nessun pagamento reale o Stripe Connect. Le sezioni storiche che indicano Exchange PLANNED sono superate da questo stato: dettagli e limiti in [exchanges.md](exchanges.md).


## EXISTING — prenotazioni stock, stato aggiornato

Checkout e pagamento fake utilizzano ora StockReservation nel database: disponibilità fisica meno prenotazioni attive non scadute, lock ordinati, consumo/rilascio atomici, idempotenza checkout/intent/eventi e scadenza schedulabile. IVA indefinita continua a impedire l'incasso simulato. Le precedenti sezioni che indicano reservation PLANNED o assente descrivono lo stato storico, superato da questa implementazione. Contratti, TTL di sviluppo, limiti e verifica in [stock-reservations.md](stock-reservations.md).


## EXISTING — carrello e ordini simulati

Carrello persistente, checkout con riepilogo/riconferma e ordini padre/sottoordini/righe sono ora implementati, con snapshot server-side e ownership cliente. MarketplacePaymentGatewayInterface/FakeMarketplacePaymentGateway sono separati dal billing PRO e creano solo intenti pendenti, senza incasso. IVA non definita rimane nullable/provvisoria per scelta dell’utente; nessuna reservation o evasione/pagamento reale. Regole di trasporto, stati, limiti e risultati aggiornati in [marketplace-cart-orders.md](marketplace-cart-orders.md). Le sezioni PLANNED successive descrivono il disegno complessivo e vanno lette con questo stato aggiornato.

## EXISTING — marketplace pubblico

Homepage, catalogo filtrato/paginato, categorie e schede prodotto sono ora implementati nel monolite con immagini pubbliche autorizzate. Le query leggono soltanto la versione approvata di offerte published/change_pending appartenenti a retailer approved, senza dipendere dal gateway store. Ricerca e filtri server-side, SEO e bottom sheet mobile sono collaudati; acquisto e proposta scambio sono solo placeholder autorizzati, senza scritture economiche. Dettagli, limiti e verifiche in [public-marketplace.md](public-marketplace.md). Le sezioni PLANNED successive restano il disegno complessivo; carrello/pagamenti non sono introdotti.

## Struttura delle aree

Rivenditore: riepilogo, giacenze, richieste, vendite, credito/bonifici, profilo/piano. Prima dell'approvazione mostrare stato della verifica, eventuale motivazione e azioni consentite.

Amministratore: dashboard sintetica, code rivenditori e giacenze, stato pubblicazioni, bonifici e anomalie integrazione. Evidenziare elementi da revisionare e fallimenti senza nasconderli dietro un generico stato approvato.

Cliente: catalogo e acquisto nel negozio esterno. Il gestionale espone solo il flusso di richiesta disponibilità necessario all'integrazione, senza carrello o checkout propri.

## Smartphone

- Card per giacenze, vendite e richieste: foto/titolo, dato principale, badge testuale e azione prioritaria.
- Navigazione inferiore per poche destinazioni frequenti; altre voci in menu accessibile. Non coprire contenuti, errori o tastiera con barre fisse.
- Target tattili almeno 44×44 px, spaziatura adeguata, layout senza scroll orizzontale a 320 px.
- Form in singola colonna con sezioni brevi, label persistenti, input appropriati e errori vicini ai campi. Denaro e quantità hanno formato locale in UI e conversione esatta lato server.
- Foto con input file immagini e suggerimento `capture="environment"`, mantenendo scelta da galleria/file. Anteprima, avanzamento, limiti dichiarati e possibilità di rimuovere prima dell'invio.
- Feedback di salvataggio e caricamento, prevenzione doppi invii in UI e idempotenza server per azioni sensibili.

## Desktop

Sidebar, contenuto ampio e dashboard con indicatori definiti (giacenze in revisione, richieste aperte, credito disponibile). Tabelle solo per confronto utile, con colonne prioritarie e alternativa mobile a card. Filtri e paginazione server-side per elenchi lunghi.

## Componenti e stati

Blade come default, componenti riutilizzabili per badge, campi, card, dialog e feedback. Alpine.js per menu, anteprime e disclosure locali. Livewire solo per interazioni server frequenti che ne giustificano costo e complessità, con motivazione documentata.

Distinguere stato vuoto, caricamento, errore e successo. Badge con testo e icona opzionale: bozza, in verifica, rifiutato, approvato, pubblicazione in corso, pubblicato, errore sincronizzazione. Mostrare motivazioni e azione successiva utile.

Conferma esplicita per rifiuti, ritiri e bonifici, con importo/valuta e conseguenze. Mai chiamare «pagato» un bonifico solamente approvato. Non mostrare saldo generico quando maturato, disponibile e riservato hanno significati diversi.

## Accessibilità e verifica

Obiettivo WCAG 2.2 AA: semantica, label, tastiera, focus visibile e gestito nei dialog, contrasto e messaggi live accessibili. Non usare solo colore né toast fugaci per errori importanti.

Verificare almeno viewport 320/375/768/1280 px, zoom, tastiera e flussi touch. Testare caricamento foto su dispositivi mobili, errori server, connessione lenta e doppio invio. Nessuna schermata è implementata in questa fase.

## Design system implementato (Prompt 1)

Componenti in `resources/views/components`: `button` (primary/secondary/danger), `field`, `select`, `textarea`, `checkbox`, `card`, `alert`, `badge`, `modal`, `toast`, `empty-state`, `loading-state`. Token e classi base in `resources/css/app.css`: palette navy/aqua con token semantici per sfondo, superfici, bordi e testo, font di sistema senza richieste esterne, spaziatura Tailwind su scala di 4 px, pulsanti/input con altezza minima 44 px.

Layout `components/layouts/retailer.blade.php` e `admin.blade.php` condividono `area-shell`. Sidebar da 1024 px; sotto tale soglia header compatto e bottom navigation rivenditore a tre colonne/due righe, con spazio finale e safe area. Amministratore con menu mobile che va a capo. Le pagine commerciali mostrano esplicitamente lo stato «In preparazione», senza saldi o dati inventati.

`modal` usa un `<dialog>` nativo: focus vincolato, Escape e ritorno al pulsante di apertura. `toast` riceve l'evento Alpine `toast` con messaggio testuale, offre chiusura esplicita e non scompare automaticamente. `field` associa errori e suggerimenti al campo. Galleria locale autenticata `/design-system` per verificare varianti e interazioni.

## Credito (Prompt 7)

Metriche e storici in card responsive, nessuna tabella larga; layout rivenditore e navigazione mobile esistenti. Storici vendite, movimenti e bonifici con paginatori indipendenti server-side. Lordo/sconto/commissione snapshot/netto distinti, stato vendita e stato bonifico con testo, indicazione movimenti non ancora disponibili. IBAN mostra solo ultime quattro posizioni. Azione “RICEVI IL BONIFICO” disabilitata se credito non positivo, IBAN mancante o richiesta pendente; controlli equivalenti server-side. Feedback visibile dopo POST, errori di validazione, link al profilo per completare IBAN. Opzione acquisti futuri solo informativa.

## Piano e pagamenti (Prompt 8)

Dashboard collega la pagina billing, accessibile anche con profilo pending. Card mobile: piano attivo, stato aziendale, stato Stripe, fine periodo e rinnovo annullato; scelta mensile/annuale e Checkout ospitato. Prezzi mostrati dalle righe plans, pulsante disabilitato senza configurazione, errori generici e feedback sul ritorno. Nessun secret o parametro tecnico nell’interfaccia. Il messaggio di successo richiede attesa della conferma webhook, senza fingere attivazione. Anche il pannello admin distingue stato pagamento e stato approvazione.

## Revisione UX (Prompt 9)

Paginazione condivisa accessibile con target 44×44 px, wrapping dei testi lunghi e margini di scorrimento per non coprire i campi focalizzati con la navigazione mobile. Modal limitati alla viewport, conferma dell’archiviazione e ripristino del modal con errori vicino al campo. Checkbox con valore esplicito anche quando deselezionate. Form prodotto a pagina unica con sezioni e riepilogo aggiornato senza calcoli monetari JavaScript; errori di foto, condizione e descrizione associati ai campi. Layout pubblico con titoli specifici, skip link e nessun autofocus automatico. Evidenze e limiti in [audit.md](audit.md).
