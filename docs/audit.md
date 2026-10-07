# Audit UX, accessibilità, sicurezza e performance — 7 ottobre 2026

## Perimetro e metodo

Revisione delle route, Policies, Form Requests, Actions, upload, sessioni, webhook, ledger, query e viste Blade. Verifiche Chromium/Playwright con axe-core, su database SQLite temporaneo separato dal database applicativo, con dati fittizi e nomi prodotto lunghi senza spazi. Nessuna credenziale reale Stripe o ecommerce utilizzata.

34 combinazioni di pagina/ruolo a 320, 360, 390, 430, 768 e 1440 px: **204 verifiche**. Incluse home, login, registrazione, recupero/reset/conferma password e verifica email, privacy, richiesta pubblica, dashboard e profilo dei rivenditori approved/pending/rejected, giacenze e seconda pagina, creazione, modifica bozza/pubblicato e dettaglio bozza/pubblicato, richieste, credito con storici paginati, piano, cinque sezioni amministrative, design system e 404. Verificati separatamente modal aperto, validazione del bonifico, tastiera, Escape, ritorno del focus, riepilogo del prodotto e CSRF.

## Problemi trovati e corretti

| Area | Problema | Correzione |
| --- | --- | --- |
| Responsive | Dashboard con scroll orizzontale per nomi prodotto senza spazi | Wrapping del testo, senza nascondere overflow o dati |
| Accessibilità | Paginatore Laravel con attributi ARIA proibiti su span; target compatti | Vista condivisa con label italiane, pagina corrente, link da almeno 44×44 px e wrapping |
| Focus | Descrizione parzialmente coperta dalla bottom navigation durante Tab | Margini e padding di scorrimento; nessun campo coperto nella verifica successiva |
| Touch e modal | Pulsanti × stretti, file input compatti, dialog senza altezza massima | Larghezza minima 44 px, upload con altezza minima 44 px, dialog limitato alla viewport e scroll interno |
| Conferme | Archiviazione immediata; errori dei modal non associati ai campi e contesto non recuperato | Conferma per archivio; identificativo del modal, riapertura dopo errore, dati preservati ed errori con aria-describedby |
| Form | Checkbox deselezionate potevano recuperare il valore precedente dopo errore | Valore hidden 0, checkbox 1 e old input; regressione testata |
| Form prodotto | Campi senza sezioni, errori di condizione/descrizione/foto poco accessibili | Pagina unica: dati essenziali → foto → prezzo/quantità → opzioni → riepilogo aggiornato → invio; componenti con errori associati |
| Linguaggio | Errori con nomi tecnici come payment_reference e messaggi fallback inglesi | Etichette italiane e traduzioni delle regole usate nei form |
| Autenticazione | Autofocus apriva immediatamente la tastiera mobile; titoli pubblici generici | Autofocus rimosso, titoli specifici e skip link nel layout pubblico |
| Validazione | SKU/EAN e riferimento bonifico accettavano 255 caratteri su colonne da 191 | Limiti server e modal allineati al database, evitando errori SQL con input validato |
| Upload | Il servizio non ripeteva il limite sorgente; percorsi persistiti usati direttamente per lettura/cancellazione | Controllo 15 MB nel servizio; solo disco local e percorso inventory/UUID.jpg per lettura/cancellazione |
| Sessione e privacy | Cache non vietata uniformemente sulle pagine autenticate | Middleware private/no-store, nosniff e Referrer-Policy; no-store anche sui form di autenticazione; token escluso dai dati flash |
| Rate limiting | Conferma e cambio password senza throttle di route | Limite di 6 richieste/minuto, oltre ai controlli già presenti sul login |
| Log | Errori SQL Laravel potevano registrare query, bindings e messaggi driver con dati personali | In produzione solo SQLSTATE, codice driver e nome connessione, senza query, bindings o messaggio originale; test dedicato |
| Integrazioni | Receipt processing senza processing_at non recuperata dal comando retry | Recupero degli eventi orfani, preservando lease attivi e idempotenza |
| Performance | Dashboard caricava tutte le colonne e contava i prodotti con una seconda query | Solo quantità/prezzo in cursor, conteggio nello stesso passaggio e calcoli esatti invariati |
| Performance | Elenchi admin caricavano interi storici per mostrare solo l’ultimo record | Relazioni latestOfMany eager loaded per abbonamenti, stato Stripe e pubblicazioni |
| Performance | Filtri data usavano DATE/COALESCE sulle colonne | Bound temporali con ultimo giorno inclusivo e fallback created_at esplicito; indice submitted_at esistente utilizzabile |
| Immagini | Foto degli elenchi caricate subito | Lazy loading e decoding asincrono; restano resize 1800 px e JPEG compresso lato server |

## Sicurezza verificata

- Autenticazione e ruoli sulle aree; Policies e ownership su letture, modifiche, foto, richieste, operazioni amministrative e credito. Le suite includono accesso fra rivenditori e blocco dei profili non approvati.
- Input validato e whitelist nelle Actions: proprietario, approvazione, saldo e importi del payout non sono assegnabili dal client. Query Eloquent con binding; filtri senza SQL interpolato dall’utente.
- Output Blade escaped e riepilogo Alpine con x-text; regressione XSS sui nomi prodotto. Richieste pubbliche sanitizzate, consenso obbligatorio, honeypot e rate limit.
- POST browser privo di CSRF verificato nel browser: **419**. Webhook su route stateless dedicate, firma raw/timestamp, limiti payload, receipt cifrate/idempotenti, retry e test di duplicazione.
- Upload con MIME reale, decodifica immagine, limiti pixel/processo, orientamento, JPEG e nome UUID; foto private e autorizzate. Nuove regressioni per percorso non sicuro e limite sorgente del servizio.
- Password hash Laravel, login con rigenerazione sessione, logout con invalidazione. Cookie HttpOnly/SameSite; Secure predefinito in produzione. Nessun secret aggiunto ai file.
- IBAN cifrato e hidden nei modelli, mascherato nelle viste e assente dai dati flash; audit e log delle integrazioni mantengono dati essenziali/errori redatti; in produzione gli errori SQL sono registrati senza query, bindings o messaggi originali. Pagine private escluse dalla cache.
- Ledger e operazioni finanziarie transazionali, idempotenti e con test di concorrenza MySQL. Nessun caching del saldo o calcolo monetario in float introdotto.

## Risultati e limiti

Le 204 verifiche finali non rilevano scroll orizzontale né violazioni axe nei tag WCAG 2 A/AA, 2.1 AA e 2.2 AA. Modal del bonifico: nessuna violazione automatica, contenuto entro la viewport. Escape chiude la conferma di archivio e restituisce il focus al pulsante. Riepilogo verificato con quantità 2 e prezzo testuale 10,50, senza calcoli JavaScript in float.

Le pagine operative mantengono card su mobile e tabella giacenze su desktop; le liste sono paginate ed eager loaded. Verificati gli indici esistenti per owner/stato/date e i filtri inclusivi. Non è stato eseguito un benchmark di carico su dati di produzione; aggregazione dashboard e ledger richiedono misure con volumi reali prima di ulteriori ottimizzazioni o cache.

L’audit automatico non certifica WCAG né sostituisce un penetration test. Restano da verificare su dispositivi fisici fotocamera/galleria, tastiere virtuali e screen reader. In produzione verificare HTTPS, APP_DEBUG=false, cookie Secure, configurazione mail, access log privi di token e aggiornamenti ImageMagick; webhook reali e provider richiedono sandbox/configurazione proprie. Le foto restano fino a 1800 px: eventuali miniature dedicate vanno valutate sui tempi di caricamento misurati.

- Suite MySQL, incluse concorrenza e 11 nuove regressioni: **208 test superati, 979 asserzioni**.
- Suite SQLite: **202 test superati, 943 asserzioni**, 6 skip attesi per i test specifici MySQL.
- Laravel Pint e build Vite eseguiti; composer.json validato; diff senza errori di whitespace.
- PHPStan/Psalm non configurati: nessuna analisi statica dichiarata eseguita.
- npm audit: **0 vulnerabilità** rilevate. Composer audit non completato: feed Packagist irraggiungibile dall’ambiente con HTTP 403 sul tunnel CONNECT; nessun risultato di assenza vulnerabilità PHP dichiarato.

I test di regressione aggiunti sono in `tests/Feature/SecurityUxAuditTest.php`. Le modifiche precedenti alle integrazioni sono state preservate. Nessun commit, push o merge eseguito per questo audit.
