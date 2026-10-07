# Prima installazione cPanel senza SSH o Terminale

L’installer web è una **misura temporanea per la sola prima installazione**, necessaria perché questo hosting non offre CLI. Non sostituisce un sistema di deployment, non aggiorna installazioni esistenti e deve essere eliminato immediatamente dopo l’uso. Non modifica architettura, ruoli, prezzi o integrazioni applicative.

## 1. Verificare il servizio hosting

Dal pannello/provider verificare PHP **8.4.1 o successivo della serie 8.4**, MySQL **8.0.16+** (consigliato 8.4), InnoDB, CHECK applicati e permessi CREATE/ALTER/INDEX/REFERENCES/TRIGGER per le migration. MultiPHP Manager deve selezionare PHP 8.4 anche per il dominio. Richieste estensioni: ctype, curl, dom, fileinfo, filter, hash, iconv, json, libxml, mbstring, openssl, pcre, PDO/pdo_mysql, session, tokenizer, xml; intl e bcmath sono utili. Composer e Node servono soltanto nel runner GitHub, non sul server.

Per le foto è ancora necessario ImageMagick con JPEG/PNG/WebP, `proc_open` abilitato e un percorso valido per `INVENTORY_IMAGE_BINARY`. Impostare `upload_max_filesize=15M`, `post_max_size=128M`, `max_file_uploads=8` e memoria adeguata in MultiPHP INI Editor. Verificare HTTPS, SMTP, spazio/inode per vendor/upload e tempo massimo delle richieste PHP: migration e cache devono poter terminare in una singola richiesta. Se il provider non supporta queste condizioni, il pacchetto non le aggira.

**Queue e scheduler restano necessari.** L’installer non avvia worker persistenti, non crea cron e non introduce endpoint pubblici per eseguirli. Chiedere al provider di configurare i processi/cron descritti in [deployment.md](deployment.md), eventualmente tramite la sezione Cron Jobs cPanel gestita dal provider (senza accesso SSH dell’utente). Se non è disponibile alcuna esecuzione pianificata, non attivare Stripe o eventi store in produzione: i webhook resterebbero in attesa e mancherebbero recuperi/riconciliazioni. Non cambiare le queue in `sync` per aggirare questo requisito.

## 2. Scaricare il pacchetto GitHub

1. Il workflow `.github/workflows/build-deploy-package.yml` deve essere presente sul branch predefinito per comparire nella pagina Actions. Pubblicarlo/revisionarlo tramite la normale PR; non serve alcun secret di produzione nelle Actions.
2. Aprire GitHub → repository → **Actions → Build deploy package → Run workflow**. Selezionare il ref revisionato; attendere che l’esecuzione risulti riuscita. La CI separata deve essere verde sullo stesso commit.
3. Nella pagina della run scaricare l’artifact `zeromagazzino-deploy-COMMIT`. GitHub può avvolgere il file in un ulteriore ZIP: estrarre prima l’archivio artifact e ottenere **`zeromagazzino-deploy.zip`**.
4. Conservare commit/run associati e un backup del pacchetto. Gli artifact scadono dopo 7 giorni. La generazione non distribuisce né contatta il server.

Lo ZIP contiene vendor senza dipendenze dev, asset Vite in `public/build`, codice e directory storage/cache vuote. Sono esclusi `.git`, `.github`, tutti gli `.env*`, test, node_modules, documentazione, helper locali, log, upload, cache runtime, hot marker e symlink storage. Anche `.env.example` è escluso: prenderlo separatamente dal **medesimo commit** del repository. Le directory vuote fanno parte dell’archivio. Non committare vendor o public/build.

## 3. Caricare ed estrarre con File Manager

1. Abilitare **Show Hidden Files** in File Manager: `.htaccess` e `.env` devono essere visibili.
2. Creare una directory privata, per esempio `/home/ACCOUNT/apps/zero`, **fuori da public_html**.
3. Caricare `zeromagazzino-deploy.zip` in questa directory ed estrarlo lì. Non contiene una directory superiore `deploy`: `app`, `vendor`, `public`, ecc. devono risultare direttamente in `/home/ACCOUNT/apps/zero`.
4. Eliminare dal server gli ZIP caricati. Verificare almeno `vendor/autoload.php`, `public/build/manifest.json`, `public/.htaccess`, `public/install-zero.php`.
5. Verificare le directory `storage/app`, `storage/app/private`, `storage/framework/cache/data`, `storage/framework/sessions`, `storage/framework/views`, `storage/logs`, `bootstrap/cache`; devono essere scrivibili da PHP. Non caricare vecchie sessioni/cache/DB SQLite.
6. In Domains impostare il document root a **`/home/ACCOUNT/apps/zero/public`**. Se cPanel non consente di cambiare il dominio principale, usare un dominio/subdominio configurabile o chiedere al provider. Non esporre la root applicativa e non spostare index.php per aggirare questa separazione.

Permessi da adattare all’owner del provider: codice directory 755/file 644; `.env` 600 o 640 con gruppo ristretto; storage/cache 750 o 775 secondo owner/gruppo. Mai 777. Non creare symlink pubblici verso storage/app/private: le foto sono servite dalle Policy applicative; `storage:link` non è necessario per questo flusso.

## 4. Database ed environment

In **MySQL Databases / Database Wizard** creare un DB **nuovo, dedicato e vuoto**, un utente e i privilegi necessari. Riportare esattamente i nomi prefissati `ACCOUNT_...` e l’host indicato dal provider. Non usare un database contenente un altro sito o un’installazione precedente. L’installer rifiuta qualsiasi DB che contenga già tabelle.

Creare nella root privata `.env` copiando il contenuto di `.env.example` dal commit distribuito. Inserire i valori reali soltanto nel File Manager sicuro; non salvarli su GitHub, nei messaggi o nell’artifact. Per i significati completi vedere [environment.md](environment.md).

Impostare almeno:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://DOMINIO
APP_KEY=INSERIRE_LA_CHIAVE_GENERATA_LOCALMENTE
DB_CONNECTION=mysql
DB_HOST=HOST_DEL_PROVIDER
DB_PORT=3306
DB_DATABASE=NOME_DB_PREFISSATO
DB_USERNAME=NOME_UTENTE_PREFISSATO
DB_PASSWORD="PASSWORD_PRIVATA_DEL_DB"
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
CACHE_STORE=database
QUEUE_CONNECTION=database
INTEGRATIONS_QUEUE_CONNECTION=database
STORE_DRIVER=fake
LOG_STACK=daily
LOG_LEVEL=warning
MAIL_MAILER=smtp
ZERO_INSTALL_TOKEN=INSERIRE_TOKEN_TEMPORANEO_CASUALE
```

Questi sono placeholder da sostituire, non credenziali utilizzabili. Configurare anche SMTP/TLS/mittente, `INVENTORY_IMAGE_BINARY`, privacy e altre variabili pertinenti. Lasciare le chiavi Stripe/store vuote finché i servizi esterni e worker non sono collaudati. Non abilitare debug per diagnosticare l’installer.

## 5. Generare APP_KEY offline

Su un computer fidato con PHP CLI locale (anche Windows/macOS/Linux), scaricare dal repository **`scripts/generate-app-key.php`** ed eseguire **localmente**:

```sh
php scripts/generate-app-key.php
```

Non richiede Composer. Genera una chiave `APP_KEY=base64:...` con 32 byte crittograficamente casuali, compatibile con AES-256-CBC Laravel, e un token temporaneo indipendente `ZERO_INSTALL_TOKEN=...` con 32 byte casuali in esadecimale. Copiare le due righe nel `.env` privato. Non usare generatori online, chat o token scelti a mano. Il helper non viene incluso nello ZIP e rifiuta esecuzioni web: **non caricarlo sul server**. L’installer non genera, modifica o stampa APP_KEY.

Conservare APP_KEY nel gestore segreti/backup cifrato: protegge anche gli IBAN e i dati delle integrazioni. In un aggiornamento non rigenerarla. Nessuna chiave reale è inclusa nel repository.

## 6. Eseguire una sola volta l’installer

L’installer accetta solo **POST su HTTPS**. Visitare l’URL con GET restituisce 404, anche con token valido. Non inserire mai il token nella query string: finirebbe nella cronologia e negli access log. Non inviare parametri per scegliere comandi o file.

Senza terminale si può creare sul proprio computer un file `installa-zero.html` con il seguente contenuto, sostituendo soltanto DOMINIO. Aprirlo nel browser, inserire il token nel campo password e premere il pulsante. Non inserire il token nel codice HTML e **non caricare questo file sul server**.

```html
<!doctype html>
<html lang="it">
<meta charset="utf-8">
<title>Prima installazione ZeroMagazzino</title>
<form method="post" action="https://DOMINIO/install-zero.php" autocomplete="off">
  <label for="token">Token temporaneo di installazione</label>
  <input id="token" name="token" type="password" required autocomplete="off">
  <button type="submit">Installa ZeroMagazzino</button>
</form>
</html>
```

Usare esclusivamente un dispositivo fidato e HTTPS valido. Se la policy browser/hosting blocca il form locale, il supporto può inviare un POST con il token nell’header `X-Zero-Install-Token`, mai nell’URL. Se HTTPS termina su proxy e PHP non riconosce il trasporto sicuro, chiedere al provider di configurare il parametro HTTPS del backend; lo script non si fida di header forwarded arbitrari.

Ordine fisso, non modificabile dalla richiesta:

1. Bootstrap Laravel e verifica environment production, debug disattivato e APP_KEY valida.
2. Connessione DB e verifica DB vuoto.
3. `migrate --force --no-interaction`.
4. `db:seed --class=Database\Seeders\PlanSeeder --force --no-interaction`, esclusivamente piani FREE/PRO, nessun account demo.
5. `optimize --except=config,routes,views` (cache eventi), poi `config:cache`, `route:cache`, `view:cache`, con controllo degli esiti.
6. Creazione `storage/app/installation-complete` con timestamp UTC ISO 8601.

Un lock file privato impedisce esecuzioni simultanee. Marker presente, token errato/assente/vuoto o GET restituiscono 404; lock occupato restituisce 409; un errore restituisce 500 con il solo nome della fase. Nessuno stack trace o output Artisan/secret è mostrato. Non sono eseguiti `migrate:fresh`, `migrate:refresh`, rollback/reset DB, factory, Composer, npm o key:generate. Il solo comando migration consentito è quello iniziale `migrate --force`.

## 7. Verificare esito e rimuovere l’accesso temporaneo

Se appare **“Installazione completata”** (HTTP 200):

1. Controllare nel File Manager il marker `storage/app/installation-complete` e il suo timestamp. Non eliminarlo e includerlo nei backup.
2. **Eliminare `public/install-zero.php`** dal server. Verificare che il suo URL risponda 404.
3. **Eliminare `ZERO_INSTALL_TOKEN` dal `.env`**, senza cambiare APP_KEY. Il token non è inserito nella config Laravel e quindi la sua rimozione non richiede rigenerazione cache. Eliminare anche il form locale e il token dal proprio archivio temporaneo.
4. Verificare HTTPS `/up`, `/login`, `/register`; registrare un account di prova autorizzato e verificare login/stato pending. Verificare via phpMyAdmin che esistano soltanto i piani previsti e che nessun utente demo sia stato creato dall’installer.
5. Verificare reset password/consegna SMTP e caricamento foto su staging. `/up` conferma soltanto boot, non salute di DB/SMTP/worker.
6. Per il primo amministratore seguire il provisioning esplicito di un utente fidato con ruolo `admin`, gestito dal responsabile tramite provider/phpMyAdmin; nessun account admin o password predefinita è creato dall’installer.
7. Configurare/verificare con il provider queue, scheduler e backup DB/upload/APP_KEY prima di attivare integrazioni. Completare [pre-production-checklist.md](pre-production-checklist.md).

Se fallisce prima delle migration (bootstrap/DB/lock), correggere configurazione, permessi o hosting e riprovare. Se fallisce durante/dopo le migration, il DB può essere parzialmente inizializzato: l’installer lo rifiuterà al tentativo successivo. **Non cancellare tabelle o marker e non aggiungere un reset web**: chiedere al supporto di verificare stato/migration/cache e completare il recupero con strumenti amministrativi su staging/backup. MySQL DDL non è interamente transazionale; non è promesso rollback automatico. In caso di timeout/disconnessione controllare prima marker e DB, senza inviare subito una seconda richiesta.

## Aggiornamenti successivi

Non reinstallare questo script su un’applicazione già avviata. Nuovi artifact vanno distribuiti preservando `.env`, APP_KEY, storage/upload e marker. Migration additive, rigenerazione cache/riavvio worker e rollback devono essere pianificati con il provider; l’installer iniziale non fornisce un meccanismo di aggiornamento senza CLI. Non sovrascrivere cache con quelle di un altro server e non sostituire lo storage esistente con quello vuoto dello ZIP.
