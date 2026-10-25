# Registrazione azienda con Company Start Italia

## Contratto e verifica sandbox

Fonte tecnica consultata il 25 ottobre 2026: [documentazione ufficiale Company](https://console.openapi.com/it/apis/company/documentation) e [OAS italiano](https://console.openapi.com/oas/it/company.openapi.json), `info.version: 1.0.0`. SHA-256 dello schema consultato: `a3f1a889dc24c04dd225e14ddb64f05d3cf166ece392096194b0bbf2e1ba2a4a`.

Endpoint sincrono `GET /IT-start/{vatCode_taxCode_or_id}`, Bearer token e Accept JSON. Il 200 documenta **data come array** di record Start. L'esempio ufficiale usa la P.IVA sandbox `12485671007`. Il controllo pubblico dell'endpoint sandbox senza Authorization ha restituito HTTP 403; non è stata tentata una lookup autenticata con credenziali reali. I risultati funzionali sono verificati con `Http::fake()` sulla forma dell'OAS, non con dati inventati da pagine commerciali.

Esiti canonici senza azienda: HTTP 204 oppure HTTP 404 con `success=false` e codice applicativo numerico `302`. Un generico 404, data vuoto/malformato nel 200, timeout, 401/403, 402, 406, 429 e 5xx sono indisponibilità/errore tecnico, non prova che l'azienda non esista.

## Architettura e flusso

Nessuna migration, colonna, modifica al modello Retailer o alla transazione di `RegisterUser`. OpenAPI Company è separata da OpenAPI Trust: client, endpoint e configurazione autonomi; nessuna modifica alla logica IBAN.

1. GET `/register`: solo P.IVA, indicatore dei due step, accesso e registrazione cliente. Rimuove l'eventuale precedente contesto del wizard.
2. POST `/register/company`: normalizza spazi, maiuscole e prefisso IT opzionale, richiede esattamente 11 cifre con le regole locali già presenti, poi controlla `retailers.vat_number` unique **prima** di cache/API. Il progetto non aveva un checksum P.IVA locale: non ne viene introdotto uno implicito.
3. `CompanyRegistrationLookup` gestisce cache/lock e richiama `OpenApiCompanyClient`; `CompanyStartMapper` seleziona i dati utili. Nessun User/Retailer/Subscription viene creato in questa fase.
4. Contesto server nella sessione guest `registration.company_lookup`: P.IVA originaria, status found/not_found/unavailable, prefill minimo, stato attività, source, timestamp apertura contesto e flag ready. Durata massima 30 minuti; niente token, header o risposta raw.
5. GET `/register/details`: contesto assente/scaduto → Step 1. Found/not_found → form Step 2. Unavailable → scelta Riprova/Continua manualmente; il form account compare solo dopo la scelta manuale via POST.
6. POST `/register`: `RegisterRequest` mantiene tutte le regole aziendali/email/password/piano e aggiunge il controllo di contesto/ready e corrispondenza P.IVA. La P.IVA è readonly in UI e resta vincolata lato server; dopo errori non viene sostituita da un valore contraffatto di old input. Tutti gli altri campi restano modificabili e il salvataggio usa i valori confermati dall'utente.
7. `RegisterUser` mantiene transaction, vincoli unique, recupero del duplicato concorrente senza User orfani, evento Registered e creazione subscription. FREE active, PRO pending e Retailer sempre pending. Auth login, pulizia del contesto e rigenerazione sessione dopo successo.

«Modifica Partita IVA» ritorna a GET `/register`, invalida il contesto e richiede nuova validazione/lookup (eventualmente servita dalla cache). Stato aziendale CESSATA/INATTIVA/SOSPESA genera soltanto un avviso: nessuna approvazione o esclusione automatica. `RegistrationMessages::DUPLICATE_VAT` e i link Accedi/Recupera password restano disponibili sia al primo controllo sia al submit finale.

## Mapping OAS → form

| Campo OAS | Campo ZeroMagazzino |
| --- | --- |
| companyName | company_name |
| vatCode | confronto con la P.IVA richiesta; non la sostituisce |
| address.registeredOffice.streetName | address, preferito perché completo |
| toponym + street + streetNumber | address, fallback senza null o spazi doppi |
| address.registeredOffice.town | city |
| address.registeredOffice.province | province |
| address.registeredOffice.region.description | region |
| activityStatus | solo informazione temporanea nel wizard |

Referente, email contatto, telefono e sito restano vuoti finché li inserisce l'utente. Non conserviamo taxCode, GPS, zipCode, SDI, date di registrazione o ID del provider: non servono ai campi esistenti. Valori precompilati sono sottoposti alle stesse validazioni finali degli input manuali.

Risposta con vatCode diverso → warning sanitizzato, nessun prefill, scelta manuale con P.IVA originaria. Tipi/struttura non documentati o valori troppo lunghi → fallback tecnico. Nessun confronto fragile con messaggi tradotti per decidere found/not_found.

## Configurazione

Solo placeholder aggiunti a `.env.example`; `.env` e APP_KEY invariati:

```dotenv
OPENAPI_COMPANY_TOKEN=
OPENAPI_COMPANY_BASE_URL=https://test.company.openapi.com
OPENAPI_COMPANY_TIMEOUT=10
```

`services.openapi_company` accetta solo i due host ufficiali HTTPS: sandbox predefinita e produzione `https://company.openapi.com` da configurare esplicitamente sul server. Timeout limitato a 1–20 secondi, connect timeout 5 secondi. Nessun redirect e nessun retry automatico della lookup a consumo. Token mancante/configurazione errata non causa fatal error: fallback manuale esplicito. Nessun worker, queue o polling necessario per Company Start.

## Cache e prevenzione abusi

Cache del risultato già mappato, con chiave versionata che include hash di host/P.IVA:

- found: 6 ore;
- not_found: 1 ora;
- unavailable: 20 secondi.

Lock cache 30 secondi e attesa massima 1 secondo per ridurre richieste contemporanee duplicate. Se la lookup è già in esecuzione compare l'opzione manuale; riprovare dopo pochi secondi può usare il risultato già in cache. Richiesti cache Laravel e relativo lock funzionanti (driver standard del progetto). Nessun refresh dello Step 2 chiama il provider. Il contesto sessione non è una prova di identità aziendale e non modifica lo stato pending.

Route guest, web/CSRF per POST e throttle:

| Metodo / path | Nome | Limite al minuto |
| --- | --- | --- |
| GET /register | register | 60 |
| POST /register/company | register.company.lookup | 10 |
| GET /register/details | register.details | 60 |
| POST /register/company/manual | register.company.manual | 10 |
| POST /register | register.store | 6, invariato |

Per gli ospiti la chiave del limitatore standard Laravel è basata su dominio/IP; lookup e scelta manuale condividono il budget più restrittivo. Le nuove route `register.*` ricevono `Cache-Control: private, no-store` come le pagine di autenticazione esistenti. Non registriamo token, response complete, email o password; il warning per risposta anomala contiene soltanto tipo lookup, status HTTP e codice fisso.

## Verifica manuale

1. Configurare il token **sandbox** esternamente; non modificare credenziali di altri provider. Nessun worker aggiuntivo.
2. Aprire `/register`: solo P.IVA e link accesso/registrazione cliente. P.IVA malformata o già registrata deve fermarsi senza API.
3. Usare un'azienda sandbox documentata: controllare i campi precompilati, P.IVA readonly, fonte e indicatore Step 2. Correggere nome/indirizzo e verificare che venga salvato il valore corretto dall'utente.
4. Per risultato canonico not_found compilare il form manuale e completare la registrazione.
5. Senza token o con servizio indisponibile verificare scelta Riprova/Continua manualmente e completare il form dopo conferma manuale. Non deve comparire «azienda non trovata» per un errore tecnico.
6. Ricaricare lo Step 2 e tornare al primo passaggio con la stessa P.IVA: nessun costo duplicato entro TTL. Modificare P.IVA: nuovo controllo locale e nuovo contesto.
7. In due sessioni iniziare con la stessa P.IVA: dopo la prima registrazione, la seconda deve ricevere l'alert duplicato senza account orfani.
8. Verificare FREE/PRO pending aziendale e registrazione cliente invariata; nessun pagamento o attivazione Stripe.

## File e test

Nuovi: client/DTO/mapper/cache in `app/Services/Company/`, request `CompanyLookupRequest`, view `register-details`, componenti indicatore/alert duplicato, test `CompanyRegistrationWizardTest`, helper di contesto per test final-submit preesistenti e questa documentazione.

Modificati: `RegisteredUserController`, `RegisterRequest`, `routes/auth.php`, `config/services.php`, `.env.example`, view iniziale e componenti `company-fields`/`field` (prop opzionale per ignorare old input della sola P.IVA readonly), middleware header privati. I quattro test final-submit esistenti ricevono un contesto sessione coerente con il nuovo prerequisito; i nuovi test percorrono realmente lookup/scelta manuale/registrazione. Il test duplicato finale segue ora il redirect Step 2. Le asserzioni su ruoli, transaction, prezzi, subscription e recupero account restano attive.

Copertura: Step 1, normalizzazione/local unique, mapping, readonly e modifica P.IVA, found/not_found, errori e timeout, token mancante, fallback completi, cache positiva/errori, TTL sessione, spoofing browser, duplicati concorrenti, validazioni finali, FREE/PRO, rollback, Registered event, CSRF, guest, throttle e cliente. Tutte le chiamate dei test automatici sono fake e bloccano richieste non simulate.

## Esito verifiche finali

PHP 8.4 / Node 24 / MySQL 8.4; HTTP interamente fake nei test automatici:

- Nuovi casi wizard: 28/28 su SQLite e MySQL.
- Suite mirata registrazione/ruoli/duplicati: 52 passati su entrambi i database.
- SQLite completa: 580 passati, 28 skip già previsti, nessun fallimento.
- MySQL completa su nuovo DB isolato: 608 passati, nessun fallimento.
- ReleaseUpdateTest: 5/5 su MySQL (skip previsto su SQLite).
- Pint, Vite, `php artisan view:cache`: superati.
- Browser: sette stati a 320/375/768/1280/1536 px, anche tema scuro a 375/1536; nessun overflow, azioni almeno 44 px, P.IVA readonly e prefill modificabile.
- Nessuna migration, nessuna modifica a `.env`/APP_KEY o alla logica IBAN, nessuna chiamata autenticata OpenAPI reale, nessun deploy.
