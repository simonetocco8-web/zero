# Refresh articoli e UI — 19 ottobre 2026

Branch: `codex/retailer-item-delete-and-ui-refresh-20261018`, derivato dal `main` aggiornato `905a6ca`. Nessun deploy.

## Modifiche

- Tema navy/aqua con token semantici centralizzati, superfici scure, testo chiaro, CTA aqua con testo navy, focus visibile, campi e stati accessibili. Layout condivisi e pagine esistenti aggiornati senza cambiare stack o dipendenze.
- Homepage ospiti con due sole CTA Accedi/Registrati e messaggi su invenduto, liquidità e scambi PRO. Duplicazioni rimosse solo da header/footer di questa pagina; registrazione cliente raggiungibile nella pagina di registrazione.
- Delete owner/admin e gestione foto già implementati su main: riutilizzati Actions, Policy, CSRF e soft delete. Conferma eliminazione ora descrive indisponibilità e conservazione dello storico. Nessuna nuova migration o modifica a ordini, scambi o pagamenti.
- Copertina esplicita, istruzioni su rimozione/ordine successivo, empty state senza immagini e disabilitazione della sostituzione quando è selezionata la rimozione. Moderazione degli articoli pubblicati e conservazione dei file storici privati invariate.
- Il menu **Vai al marketplace** esistente è mantenuto su desktop/mobile; il catalogo resta riservato agli utenti autenticati e alle rivendite approved.
- Corretto il nome accessibile del collegamento nelle card marketplace: usa il contenuto visibile senza un aria-label che lo tronchi.

## Verifiche

| Controllo | Esito |
| --- | --- |
| Suite completa SQLite | 399 passati, 27 esclusi specifici MySQL; 2388 asserzioni |
| Gestione articoli su MySQL dedicato | 13 passati; 139 asserzioni |
| Test finali accesso/gestione/marketplace/componenti | 29 passati; 340 asserzioni |
| Build Vite e Pint | Passati |
| composer validate --strict e git diff --check | Passati |
| Chromium + axe, 48 combinazioni schermata/viewport a 320/375/768/1280 px | Nessun overflow o errore JS; anomalia nome accessibile card corretta e ricontrollata a tutte le larghezze |
| Scambi a 320/375/768/1280 px e dialog aperto | Nessuna violazione axe o overflow |
| Browser: tastiera, ritorno focus dal dialog, link marketplace mobile | Passati |
| Browser: sostituzione foto, eliminazione ultima foto e articolo | Passati su fixture isolate |

I bordi dei controlli usano un token distinto dalle superfici decorative: contrasto superiore a 3:1 anche sulla superficie raised; testo secondario e CTA sono verificati con axe. La suite browser usa un database SQLite nuovo e account temporanei separati dai dati applicativi.

Test aggiunti per CTA senza duplicazioni, collegamenti delle altre pagine, liste/catalogo dopo delete, ownership delle scritture foto, sostituzione admin, rimozione copertina e rimozione totale, file privati conservati e applicazione della copertina pubblicata solo dopo review.

La suite MySQL completa non è stata rieseguita; sono stati eseguiti i test pertinenti su un nuovo database dedicato. Le integrazioni esterne non sono state attivate e non sono stati eseguiti pagamenti reali.

## Validazione del committente

Il brand è stato interpretato dalle linee guida testuali navy/aqua allegate. Resta la validazione estetica finale con le grafiche originali e, se necessario, una prova su dispositivi fisici iOS/Android: le verifiche responsive automatiche usano Chromium.

## File modificati

- `docs/ui-refresh-20261019.md`
- `docs/ui-ux.md`
- `resources/css/app.css`
- `resources/views/admin/dashboard.blade.php`
- `resources/views/admin/payouts.blade.php`
- `resources/views/admin/retailers.blade.php`
- `resources/views/auth/confirm-password.blade.php`
- `resources/views/auth/forgot-password.blade.php`
- `resources/views/auth/login.blade.php`
- `resources/views/auth/register.blade.php`
- `resources/views/auth/reset-password.blade.php`
- `resources/views/auth/verify-email.blade.php`
- `resources/views/availability/create.blade.php`
- `resources/views/availability/index.blade.php`
- `resources/views/components/admin-confirm.blade.php`
- `resources/views/components/admin-filters.blade.php`
- `resources/views/components/alert.blade.php`
- `resources/views/components/area-shell.blade.php`
- `resources/views/components/badge.blade.php`
- `resources/views/components/brand.blade.php`
- `resources/views/components/card.blade.php`
- `resources/views/components/checkbox.blade.php`
- `resources/views/components/empty-state.blade.php`
- `resources/views/components/field.blade.php`
- `resources/views/components/head.blade.php`
- `resources/views/components/icon.blade.php`
- `resources/views/components/inventory-actions.blade.php`
- `resources/views/components/loading-state.blade.php`
- `resources/views/components/marketplace-card.blade.php`
- `resources/views/components/marketplace-layout.blade.php`
- `resources/views/components/select.blade.php`
- `resources/views/components/textarea.blade.php`
- `resources/views/components/toast.blade.php`
- `resources/views/exchanges/form.blade.php`
- `resources/views/exchanges/index.blade.php`
- `resources/views/exchanges/show.blade.php`
- `resources/views/inventory/admin.blade.php`
- `resources/views/inventory/form.blade.php`
- `resources/views/inventory/index.blade.php`
- `resources/views/inventory/photos.blade.php`
- `resources/views/layouts/guest.blade.php`
- `resources/views/marketplace/home.blade.php`
- `resources/views/marketplace/index.blade.php`
- `resources/views/marketplace/placeholder.blade.php`
- `resources/views/marketplace/show.blade.php`
- `resources/views/marketplace/welcome.blade.php`
- `resources/views/pages/design-system.blade.php`
- `resources/views/pages/placeholder.blade.php`
- `resources/views/pagination/accessible.blade.php`
- `resources/views/retailer/billing.blade.php`
- `resources/views/retailer/credit.blade.php`
- `resources/views/retailer/dashboard.blade.php`
- `resources/views/welcome.blade.php`
- `tests/Feature/BrandEntryTest.php`
- `tests/Feature/InventoryManagementAccessTest.php`
