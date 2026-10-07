# Distribuzione Linux / cPanel

Questa è una procedura da revisionare ed eseguire sul server scelto, non un deployment automatico. Nessun comando di questa guida è stato eseguito contro produzione. Preparare prima staging, backup e piano di ripristino; usare gli stessi pacchetti fissati nei lockfile e gli asset della stessa release.

Per hosting senza SSH/Terminale vedere la [procedura File Manager e installer temporaneo](cpanel-no-ssh-deployment.md). Questa guida mantiene i comandi per server con CLI o interventi del provider.

## Requisiti e hosting compatibile

PHP >= 8.4.1 su web e CLI, Composer 2, MySQL >= 8.0.16 (consigliato 8.4), CHECK e trigger abilitati. Estensioni e ImageMagick JPEG/PNG/WebP sono elencati nel README; `proc_open` non deve essere disabilitato; la CLI worker deve avere pcntl per applicare i timeout. Richiesti accesso SSH/Terminal, cron ogni minuto, processi worker o una modalità bounded equivalente, storage privato persistente, TLS e SMTP/API mail configurabile. Se l’hosting impedisce questi requisiti, non è compatibile con il rilascio corrente.

PHP-FPM/CLI: `upload_max_filesize=15M`, `post_max_size=128M`, `max_file_uploads=8`, `memory_limit` almeno 256M come punto di partenza da misurare, directory temporanea scrivibile. Reverse proxy/web server deve accettare il totale del form; webhook hanno limite applicativo di 1 MiB. ImageMagick resta soggetto a timeout e limiti di risorse del servizio. Non disabilitare policy/TLS per aggirare errori.

MySQL: utf8mb4, InnoDB e modo strict. Utente di migration con CREATE/ALTER/INDEX/DROP/REFERENCES/TRIGGER oltre a SELECT/INSERT/UPDATE/DELETE; runtime con soli privilegi necessari alle operazioni. La creazione trigger con binlog attivo può richiedere intervento DBA o configurazione `log_bin_trust_function_creators`: concordarla con il provider senza disabilitare binlog, replica o PITR di produzione. cPanel deve permettere questi vincoli, non una migration parziale.

## Directory e document root

Esempio Linux: app `/srv/zeromagazzino/current`, storage persistente esterno al rilascio e document root `/srv/zeromagazzino/current/public`. L’utente di PHP-FPM, CLI e worker deve accedere agli stessi `.env`, DB, storage e cache. Con release multiple collegare lo storage persistente a ogni release, senza creare link pubblici verso i file privati.

Nginx: root sul solo `public`, `try_files $uri $uri/ /index.php?$query_string`, PHP-FPM corretto, nessuna esecuzione PHP di file caricati, blocco dei dotfile. Apache: `DocumentRoot` sul solo `public`, mod_rewrite e `.htaccess` Laravel applicabile. Non esporre `.env`, vendor, storage privato, backup o log; verificare risposte 403/404 a richieste dirette. Non usare `artisan serve` come server di produzione.

cPanel: codice in `/home/ACCOUNT/apps/zero`, dominio/addon/subdomain con document root `/home/ACCOUNT/apps/zero/public`. Se il dominio principale impone `public_html`, chiedere al provider la modifica document root o un symlink supportato verso **il solo public**. Non copiare l’intero repository in `public_html` e non modificare index.php per aggirare l’isolamento. Se nessuna soluzione è permessa, usare un hosting compatibile.

In MultiPHP Manager scegliere PHP 8.4 aggiornato e verificare anche il binario CLI/cron, spesso `/opt/cpanel/ea-php84/root/usr/bin/php`; confermare il percorso e la patch con il provider. Nel seguito `php` rappresenta il binario corretto, non necessariamente il default di sistema.

## Preparare la prima installazione

1. Creare database e utente nel pannello/DBA. Sul cPanel i nomi possono avere prefisso ACCOUNT: riportarli esattamente nel `.env`.
2. Distribuire codice/versione verificata fuori dal document root. Portare `public/build` della stessa versione o compilarlo sul server con Node 24 LTS.
3. Creare `.env` da `.env.example` solo se assente, inserire dati tramite un canale privato. In produzione usare APP_ENV=production, APP_DEBUG=false, APP_URL HTTPS, SESSION_SECURE_COOKIE=true, MAIL_MAILER reale. Non usare il mailer log.
4. Generare APP_KEY **solo se assente e prima di cifrare dati**. In aggiornamento preservare la chiave; una rotazione richiede piano, APP_PREVIOUS_KEYS e verifica/decrittazione dei dati esistenti.

```sh
cd /srv/zeromagazzino/current
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer check-platform-reqs --no-dev
# Solo la prima installazione, quando la chiave è assente:
php artisan key:generate --force
# Solo se si compila su questa macchina:
npm ci
npm run build
# Document root deve già essere configurata sul public.
php artisan migrate --force
php artisan db:seed --class=PlanSeeder --force
```

Non eseguire `composer update`, `npm update`, `composer setup`, `migrate:fresh`, `migrate:refresh` o factory sul database reale. `PlanSeeder` è ripetibile e non modifica piani già personalizzati; controllare comunque righe FREE/PRO, prezzi, commissioni e limiti prima di accettare iscrizioni.

### Storage link e permessi

Le foto sono in `storage/app/private/inventory` e servite da route autenticata con Policy. Non devono essere accessibili tramite URL `/storage/inventory`.

```sh
# Facoltativo: solo se si usano file intenzionalmente pubblici.
php artisan storage:link
```

Questo collega `public/storage` a `storage/app/public`, non le foto private. Non collegare l’intera `storage/app` o `storage/app/private` al document root. `FILESYSTEM_DISK=s3` non migra automaticamente queste foto.

Con owner/gruppo corretti, directory codice 755 e file 644 sono un esempio iniziale; `storage` e `bootstrap/cache` devono essere scrivibili dall’utente applicativo (775/664 con gruppo dedicato oppure 750/640 se l’owner coincide). `.env` 600 se stesso utente o 640 con gruppo ristretto. Non rendere scrivibile il codice al web server senza necessità e mai usare 777. Adattare al modello utenti del provider, inclusi SELinux/ACL, senza rendere pubblici dati sensibili.

## Cache e riavvio

```sh
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

Dopo una modifica `.env` ricostruire config cache e riavviare worker. Non usare `env()` fuori dai file config. `queue:restart` usa la cache condivisa; verificare che il supervisor riavvii davvero il processo. La root corrente e i symlink devono essere coerenti con il working directory del worker.

`php artisan schedule:list` deve mostrare `store:sync`, `integrations:retry` ogni minuto e `billing:sync` ogni ora. La verifica dei comandi è distinta dalla prova che cron/worker restino attivi nel server reale.

## Worker queue

Default: `QUEUE_CONNECTION=database`, `INTEGRATIONS_QUEUE_CONNECTION=database`, `DB_QUEUE_RETRY_AFTER=150`, cache database condivisa. Il timeout worker 45s deve essere minore di retry_after; retry_after deve superare anche il lease integrazioni di 120s. Non usare sync/deferred/null per gli eventi esterni. Se si sceglie Redis, provisionare/authenticare il servizio, estensione PHP Redis, cache/lock condivisi e allineare connessione worker e REDIS_QUEUE_RETRY_AFTER.

Esempio Supervisor Linux, da adattare a percorsi e utente esistenti:

```ini
[program:zeromagazzino-worker]
command=/usr/bin/php8.4 /srv/zeromagazzino/current/artisan queue:work database --queue=integrations,default --sleep=3 --timeout=45 --tries=5 --max-time=3600
user=zeromagazzino
directory=/srv/zeromagazzino/current
autostart=true
autorestart=true
numprocs=1
stopasgroup=true
killasgroup=true
stopwaitsecs=180
redirect_stderr=true
stdout_logfile=/srv/zeromagazzino/shared/logs/worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
```

Creare directory privata del log, owner corretto e processo supervisor monitorato. Per connessioni diverse sostituire `database` e controllare il backend durevole; il provider reale usa queue `integrations`. Scalare i worker solo dopo misure di backlog, lock e risorse.

cPanel: preferire il gestore processi fornito dall’hosting. Se non consente worker persistenti ma permette cron/proc_open, può essere sufficiente un worker bounded **non sovrapposto** ogni minuto, dopo prova in staging:

```cron
* * * * * cd /home/ACCOUNT/apps/zero && /usr/bin/flock -n /home/ACCOUNT/.zero-worker.lock /opt/cpanel/ea-php84/root/usr/bin/php artisan queue:work database --queue=integrations,default --stop-when-empty --max-time=50 --sleep=1 --timeout=45 --tries=5 >> /home/ACCOUNT/logs/zero-worker.log 2>&1
```

Verificare flock, directory log privata e limiti di processo/cron del provider. `--max-time` non interrompe un job già in corso; il lock impedisce sovrapposizioni. Questo modello può aggiungere latenza e non garantisce capacità per alti volumi: se cron ogni minuto o processi sufficienti non sono consentiti, cambiare hosting. Non usare il worker bounded e quello persistente insieme senza una scelta operativa esplicita.

## Scheduler e cron

Un cron ogni minuto, con stesso utente/configurazione del worker, su un solo nodo scheduler:

```cron
* * * * * cd /srv/zeromagazzino/current && /usr/bin/php8.4 artisan schedule:run >> /srv/zeromagazzino/shared/logs/scheduler.log 2>&1
```

cPanel equivalente: working directory `/home/ACCOUNT/apps/zero`, percorso PHP ea-php84 verificato e log `/home/ACCOUNT/logs/zero-scheduler.log`. Lo scheduler pianifica recuperi/pubblicazioni/riconciliazioni; non sostituisce il worker. Ruotare i log cron e monitorare ultima esecuzione/backlog. Non aggiungere cron separati per i singoli comandi già pianificati.

## Mail, HTTPS e integrazioni

Configurare SMTP reale con mittente autorizzato e TLS verificato; per SMTP su TLS implicito usare MAIL_SCHEME=smtps e porta indicata dal fornitore, tipicamente 465. MAIL_SCHEME=smtp con STARTTLS è possibile sulla porta del fornitore; verificare la sicurezza del trasporto. MAIL_URL è un’alternativa contenente credenziali da proteggere, non da inserire in documentazione/log. Provare reset password e consegna senza divulgare token; configurare SPF/DKIM/DMARC dal provider.

Certificato valido, redirect HTTP→HTTPS, cookie Secure/HttpOnly/SameSite e APP_URL esterno HTTPS. Se TLS termina su proxy, configurare i proxy fidati e le intestazioni corrette per l’infrastruttura specifica; non fidarsi indiscriminatamente di client Internet. Verificare URL generati e cookie dal browser. Proteggere log accessi: non registrare query/reset token, payload webhook, Authorization o firme. APP_DEBUG=false deve impedire stack/configurazione nelle pagine errore.

Stripe:

- Account di test per staging; live solo dopo approvazione operativa. Configurare Price ID ricorrenti EUR 69 €/mese e 499 €/anno, secret SDK e secret dell’endpoint del rispettivo ambiente.
- Endpoint POST `https://DOMINIO/webhooks/stripe`: checkout.session.completed, customer.subscription.created/updated/deleted, invoice.payment_failed e invoice.paid.
- Server/worker con uscita TLS verso api.stripe.com; browser verso checkout.stripe.com. NTP funzionante, corpo raw non riscritto da WAF/proxy; endpoint browser conservano CSRF.
- Provare firma errata, duplicato, retry, PRO pagato pending, insoluto e cancellazione. Un 202 prova solo ricezione durevole: verificare stato processed e risultato dominio dopo il worker.

Store: mantenere STORE_DRIVER=fake finché non è implementato e collaudato un provider reale. `/webhooks/store/fake` e STORE_WEBHOOK_SECRET sono protocollo di test; per il provider selezionato registrare adapter, credenziali/config, signature ufficiale e mapping remoto. Tutorial e payload normalizzati in [integrations.md](integrations.md). Confermare la fonte autorevole dello stock: gli eventi finanziari attuali non scalano automaticamente le giacenze.

## Aggiornamento e rollback

Prima di un aggiornamento: backup consistente, prova migration su copia/staging, scelta di una finestra operativa e release/build verificati. Se necessario usare `php artisan down` senza pubblicare bypass token, fermare/gracefully drainare worker, applicare migration compatibili, ricostruire cache, riavviare worker e `php artisan up`. Mentre il sito è down i webhook possono ricevere 503: contare sui retry provider e verificare replay/riconciliazione dopo il ripristino.

Preferire migration additive compatibili con il codice precedente. In un rollback ripristinare codice e asset coerenti, cache e worker; non fare rollback DB alla cieca su ledger/vendite. Il restore del DB può perdere eventi già ricevuti dopo lo snapshot: riconciliare Stripe/negozio/banca, replay firmati/idempotenti e chiavi locali. Definire responsabilità e massimo downtime prima del rilascio.

## Log, monitoraggio e recupero

LOG_STACK=daily, LOG_LEVEL=warning in produzione come punto iniziale, LOG_DAILY_DAYS secondo conservazione concordata. Log applicativi in `storage/logs`; log web/worker/cron privati e ruotati. Il reporting SQL di produzione registra soli codici e connessione; comunque vietato aggiungere payload, credenziali o coordinate bancarie ai log.

Monitorare HTTP/errori 5xx, `/up` per boot del framework, DB, mail, spazio disco, backlog jobs, failed_jobs, receipt pending/failed/esaurite e store_publications. `/up` da solo **non prova** che DB, cron, SMTP o queue siano sani. Non cancellare receipt, ledger o audit per far sparire un problema.

```sh
php artisan queue:failed
php artisan integrations:retry
php artisan billing:sync
php artisan store:sync
# Solo dopo diagnosi dell'errore:
php artisan integrations:retry --event=ID_LOCALE --force
```

## Backup database, upload e chiave

Backup DB e upload devono rappresentare uno stato coerente: pianificare snapshot coordinati oppure breve pausa di scritture/worker. Conservare copia cifrata off-site, retention/RPO/RTO e controllo accessi. Sul DB InnoDB un dump coerente può usare `--single-transaction --routines --triggers --events --hex-blob`; binlog/PITR richiedono gestione DBA. Non omettere trigger/CHECK e non usare password nella riga di comando o nel crontab.

Esempio con file client MySQL privato **preconfigurato dal DBA** (chmod 600), senza mostrare credenziali:

```sh
mysqldump --defaults-extra-file=/PERCORSO_PRIVATO/mysql-backup.cnf --single-transaction --routines --triggers --events --hex-blob zeromagazzino > /PERCORSO_PRIVATO_BACKUP/database.sql
```

Creare il dump con umask restrittiva, cifrarlo e trasferirlo tramite sistema di backup autorizzato; non salvarlo sotto public o nel repository. Verificare privilegi backup e configurazione GTID/PITR con il DBA, non improvvisare opzioni di replica.

Includere `storage/app/private` (foto e dati privati) e `storage/app/public` se usata, `.env`/APP_KEY e chiavi precedenti in archivio segreti separato. La chiave deve essere recuperabile per decifrare IBAN/receipt: backup DB senza chiave non è recupero completo. Cache/compiled views non sono dati da ripristinare; ricostruirle. Testare restore in ambiente isolato, con mail/Stripe/worker esterni disabilitati per evitare effetti reali, verificando conteggi, saldi, foto, trigger e dati cifrati.

La checklist operativa è in [pre-production-checklist.md](pre-production-checklist.md).
