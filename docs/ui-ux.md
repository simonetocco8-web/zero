# UI e UX

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

Componenti in `resources/views/components`: `button` (primary/secondary/danger), `field`, `select`, `textarea`, `checkbox`, `card`, `alert`, `badge`, `modal`, `toast`, `empty-state`, `loading-state`. Token e classi base in `resources/css/app.css`: palette verde/slate, font di sistema senza richieste esterne, spaziatura Tailwind su scala di 4 px, pulsanti/input con altezza minima 44 px.

Layout `components/layouts/retailer.blade.php` e `admin.blade.php` condividono `area-shell`. Sidebar da 1024 px; sotto tale soglia header compatto e bottom navigation rivenditore a tre colonne/due righe, con spazio finale e safe area. Amministratore con menu mobile che va a capo. Le pagine commerciali mostrano esplicitamente lo stato «In preparazione», senza saldi o dati inventati.

`modal` usa un `<dialog>` nativo: focus vincolato, Escape e ritorno al pulsante di apertura. `toast` riceve l'evento Alpine `toast` con messaggio testuale, offre chiusura esplicita e non scompare automaticamente. `field` associa errori e suggerimenti al campo. Galleria locale autenticata `/design-system` per verificare varianti e interazioni.

## Credito (Prompt 7)

Metriche e storici in card responsive, nessuna tabella larga; layout rivenditore e navigazione mobile esistenti. Storici vendite, movimenti e bonifici con paginatori indipendenti server-side. Lordo/sconto/commissione snapshot/netto distinti, stato vendita e stato bonifico con testo, indicazione movimenti non ancora disponibili. IBAN mostra solo ultime quattro posizioni. Azione “RICEVI IL BONIFICO” disabilitata se credito non positivo, IBAN mancante o richiesta pendente; controlli equivalenti server-side. Feedback visibile dopo POST, errori di validazione, link al profilo per completare IBAN. Opzione acquisti futuri solo informativa.
