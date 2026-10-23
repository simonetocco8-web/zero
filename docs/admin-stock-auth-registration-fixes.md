# Giacenze admin, email e registrazione — 22 ottobre 2026

Base: `main` commit `691e785d54ebb4dfce0e5f3b8454fc84f1baf34c`.
Branch: `codex/admin-stock-auth-registration-fixes-20261022`.

## Modifiche

La tabella `/admin/stock` usa un componente icona dedicato per lo stato, con
`role="img"`, `aria-label` e tooltip `title` italiani. Ogni stato ha un simbolo
riconoscibile; il colore non è l'unico indicatore. Il componente testuale
`x-inventory-status` resta invariato e continua a essere usato nel dettaglio e
nelle altre pagine. La lista resta una tabella anche su mobile.

Il modal mostra in alto la prima immagine proposta quando esistono sia
`proposed_data` sia immagini proposte. Altrimenti sceglie la foto persistita con
la position minore, usando quella position effettiva anche quando non è zero.
Le immagini passano esclusivamente da `inventory.image`, con `proposed=1` per
le proposte: nessun path, disk o URL privato è inserito nell'HTML. La route e le
sue autorizzazioni non sono state modificate. Foto contenuta, lazy loading,
alt con nome prodotto e placeholder quando nessuna foto è disponibile.

Il controllo mailer è condiviso in `DeliveringMailerGuard`: risolve mailer moderni,
configurazione legacy `mail.driver` e override `MAIL_URL` come Laravel. Accetta
smtp, sendmail, ses, ses-v2, mailgun, postmark e resend; array soltanto in testing.
Composizioni failover/roundrobin sono accettate solo se tutti i figli consegnano;
cicli, trasporti sconosciuti e qualunque percorso verso log vengono rifiutati.
La verifica non apre connessioni e non certifica la raggiungibilità SMTP.

Nel reset amministrativo, configurazione non di consegna produce:

> Invio email non configurato sul server. La password non è stata modificata.

L'errore SMTP/trasporto mantiene invece il messaggio precedente sul ripristino
non completato e sulla conservazione della vecchia password. La conferma
selezionata si riapre tramite il componente modal esistente e mostra l'errore
all'interno. Rimangono password server-side da 24 caratteri, quattro categorie,
cast hashed, azzeramento remember_token, autorizzazioni, throttle, audit e
transazione con rollback. Password ed eccezioni sensibili non vengono esposte.

Il recupero password pubblico usa lo stesso controllo: con mailer log non invoca
il broker e non dichiara un invio riuscito; mostra soltanto l'indisponibilità del
servizio email. Errori del trasporto sono trattati senza dettagli tecnici o log
dell'eccezione. Gli status broker, anche nella conferma della nuova password,
usano traduzioni italiane risolte senza fallback ad altra lingua; se la chiave
non viene risolta, usano fallback italiani espliciti per sent, user, throttled,
token, reset e status sconosciuti. Nessuna chiave `passwords.*` viene mostrata.

Registrazione aziendale: la unique rule e l'indice UNIQUE restano attivi.
Il fallimento strutturato della rule Unique per vat_number aggiunge
`company_exists`, senza confronti sul testo dell'errore in Blade. Il form mostra
l'alert Azienda già registrata, i link Accedi/Recupera password e l'errore inline.
Una VAT formalmente errata non produce l'alert azienda esistente. Se il vincolo DB
rileva una duplicazione dopo la validazione, la transazione annulla anche
l'eventuale nuovo user e restituisce gli stessi errori strutturati. Nessun nuovo
retailer o abbonamento viene conservato e l'utente rimane ospite.

## Causa individuata del reset fallito e limiti della diagnosi

Nell'ambiente applicativo ispezionato, il mailer effettivo è `log`, con transport
`log`: il rifiuto è intenzionale per impedire la scrittura della password nei log.
Il precedente catch generico mascherava questa causa con lo stesso messaggio di
un errore SMTP. Inoltre il vecchio guard rifiutava qualunque `mail.driver` legacy,
anche smtp: questo falso rifiuto è stato corretto e coperto da test.

Questa osservazione riguarda l'ambiente disponibile, non un accesso autonomo al
server di produzione. Non sono state lette o modificate credenziali SMTP e non è
stato cambiato alcun `.env`. Un server con configurazione cache potrebbe ancora
usare un valore precedente: verificare il valore effettivo con il comando sotto.
Non è stato eseguito un invio SMTP reale. Rimangono i limiti documentati della
consegna email: accettazione SMTP non garantisce recapito e SMTP/DB non hanno
atomicità distribuita.

## Configurazione email richiesta sul server

Configurare fuori dal repository, tramite il gestore segreti/configurazione del
server, senza usare i valori di test:

- `MAIL_MAILER=smtp` oppure altro trasporto reale supportato;
- `MAIL_HOST` e `MAIL_PORT` del provider;
- `MAIL_USERNAME` e `MAIL_PASSWORD` del provider;
- `MAIL_FROM_ADDRESS` e `MAIL_FROM_NAME` autorizzati dal provider;
- eventuale `MAIL_SCHEME` (`smtp`/`smtps`) e TLS secondo runtime/provider;
- verificare un eventuale `MAIL_URL`, che può sovrascrivere le opzioni SMTP.

Non usare log o array in produzione, né un failover verso log per questi flussi.
Dopo l'aggiornamento della configurazione server, rigenerare la cache secondo la
procedura di deployment (`php artisan config:clear`, quindi
`php artisan config:cache`). Questi comandi non sono stati eseguiti in produzione.

Comando diagnostico read-only:

```sh
php artisan mail:diagnose
```

Mostra soltanto mailer predefinito, transport, host, porta e from address. Non
mostra username/password SMTP, URL con credenziali, token, chiavi o configurazione
completa. Risolve anche i campi consentiti di MAIL_URL; configurazioni non valide
vengono indicate senza riportare l'eccezione. Non invia email né cambia dati.

## Perimetro preservato

Nessuna migration nuova o modificata, nessuna modifica alla migration audit o allo
schema DB. Nessun intervento su pagamenti, Stripe, wallet, commissioni, ordini,
scambi, prenotazioni, regole marketplace o execution mode. APP_KEY e dati
applicativi esistenti conservati; tutti i test e le fixture browser usano database
nuovi e storage dedicato. Nessun merge o deployment effettuato.

## Verifiche eseguite

- `composer validate --strict`: riuscito.
- `vendor/bin/pint --test`: riuscito.
- `php artisan test`: 461 test passati, 28 saltati specifici dei database reali,
  2835 asserzioni, su SQLite isolato.
- Suite mirata MySQL 8.4.11: 66 test passati, 413 asserzioni. Include registrazione,
  vincolo UNIQUE e rollback dopo validazione obsoleta, recupero password,
  guard mailer, reset admin e presentazione stock.
- `npm run build`: riuscito.
- `git diff --check`: riuscito.
- Chromium + axe WCAG 2 A/AA e 2.1 AA: 40 combinazioni delle quattro pagine
  `/admin/stock`, `/admin/retailers`, `/forgot-password` e `/register` alle
  larghezze 375, 430, 768, 1024, 1440, in light e dark.
- Ulteriori verifiche nei dettagli stock: immagine persistita a position 4,
  proposta con proposed=1 e placeholder; immagini realmente caricate, route
  protetta, nessun path privato nell'HTML, icone con label/tooltip per sei stati,
  tastiera, focus ed ESC. La tabella mantiene scroll orizzontale interno mobile.
- Reset browser con mailer log: popup riaperto con messaggio specifico interno;
  recupero pubblico senza falso successo; registrazione duplicata con alert,
  link e password vuote. Nessun overflow pagina, errore JS/console o violazione
  axe rilevata nei controlli eseguiti. Schermate light/dark controllate anche
  visivamente.

I test usano una fixture numerica di processo per una variabile marketplace
malformata fornita dal runtime, senza modificare file di configurazione o
execution mode. I test di consegna usano fake/mock; il controllo di compatibilità
SMTP istanzia il vero transport Laravel senza aprire connessioni. Nessun invio
SMTP reale o modifica della configurazione email di produzione è dichiarato.

## File modificati

- `app/Actions/Auth/RegisterUser.php`
- `app/Actions/ResetRetailerPassword.php`
- `app/Http/Controllers/Auth/NewPasswordController.php`
- `app/Http/Controllers/Auth/PasswordResetLinkController.php`
- `app/Http/Requests/Auth/RegisterRequest.php`
- `resources/views/admin/retailers.blade.php`
- `resources/views/auth/forgot-password.blade.php`
- `resources/views/auth/register.blade.php`
- `resources/views/components/admin-confirm-form.blade.php`
- `resources/views/components/admin-confirm.blade.php`
- `resources/views/components/icon.blade.php`
- `resources/views/inventory/admin.blade.php`
- `resources/views/inventory/partials/admin-detail.blade.php`
- `tests/Feature/AdminRetailerPasswordResetTest.php`

## Nuovi file

- `app/Console/Commands/MailDiagnostics.php`
- `app/Exceptions/EmailDeliveryNotConfigured.php`
- `app/Services/DeliveringMailerGuard.php`
- `app/Support/PasswordStatusMessage.php`
- `app/Support/RegistrationMessages.php`
- `docs/admin-stock-auth-registration-fixes.md`
- `resources/views/components/inventory-status-icon.blade.php`
- `tests/Feature/AdminStockPresentationTest.php`
- `tests/Feature/Auth/DuplicateCompanyRegistrationTest.php`
- `tests/Feature/Auth/PasswordRecoveryMessagesTest.php`
- `tests/Feature/MailDeliveryGuardTest.php`
