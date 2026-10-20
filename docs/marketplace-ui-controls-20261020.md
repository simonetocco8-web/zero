# Controlli UI marketplace — 20 ottobre 2026

## EXISTING — modifiche

Base: `origin/main` al commit `a00e1639b469c071b868aa7160a9311d02661efd`, comprensivo di tema, home e asset brand.

- Recensioni: tracciato flex dedicato (`reviews-slider-track` / `review-card`), sempre su una sola riga, con overflow locale e scroll snap. Circa 1,15 card mobile, 2,2 tablet, 3–4 desktop. Nessun autoplay. Frecce di una card, tastiera ←/→ e Home/End, focus visibile; movimento immediato con reduced motion e fluido altrimenti. Conservati 20 contenuti demo, disclaimer, badge, stelle, attività e riepilogo 4,9/5.
- Categorie home: cerchi 80 px mobile e 96 px desktop, etichette sottostanti e scorrimento orizzontale senza comprimere i cerchi.
- `category-icon`: unico mapping per slug/nome, incluse edilizia-2/edilizia-pesante; icona box per categorie future. Nuove SVG interne, senza dipendenze aggiuntive.
- `CategoryMenu`: componente Blade PHP che legge tutte le categorie attive tramite `MarketplaceCatalog::categories()`. Nessuna query nel Blade. Dropdown disponibile nei layout marketplace esistenti; click, click esterno, Escape con ritorno al pulsante, frecce su/giù, Home/End e Tab. `aria-expanded` e `aria-controls` con ID Alpine univoco. Su schermi piccoli il pannello si ancora al gruppo di navigazione per restare nel viewport.
- Toggle tema: una sola istanza per layout, fuori da header/nav, circolare e fissa a sinistra al 50% dell'altezza. Luna in light, sole in dark, aria-label/title aggiornati. Conservati script iniziale, localStorage e sincronizzazione tra schede. Piccolo margine di sicurezza per contenuto/sidebar quando il controllo potrebbe sovrapporsi al testo.

`product-rail` e il comportamento desktop dei prodotti restano invariati. Nessuna modifica a schema, migration, rotte, autorizzazioni, autenticazione, pubblicazione, lifecycle o flussi economici. Nessuna modifica a `MARKETPLACE_EXECUTION_MODE`. Logo e favicon di main conservati.

## File

- `app/View/Components/CategoryMenu.php`
- `resources/views/components/category-menu.blade.php`
- `resources/views/components/category-icon.blade.php`
- `resources/views/components/icon.blade.php`
- `resources/views/components/theme-toggle.blade.php`
- `resources/views/components/head.blade.php`
- `resources/views/components/marketplace-layout.blade.php`
- `resources/views/components/area-shell.blade.php`
- `resources/views/layouts/guest.blade.php`
- `resources/views/marketplace/home.blade.php`
- `resources/css/app.css`
- `resources/js/app.js`
- `tests/Feature/MarketplaceUiControlsTest.php`
- `tests/Feature/BrandEntryTest.php` (datasource del nuovo menu simulato nel test di solo rendering senza DB)
- questo documento.

## Verifica

- `composer validate --strict`: superato.
- `vendor/bin/pint --test`: superato.
- `php artisan test --compact`: **404 passati, 27 saltati, 2497 asserzioni**. SQLite isolato; i test specifici MySQL saltati non costituiscono validazione della concorrenza MySQL.
- Nuovi test UI: **4 passati, 91 asserzioni**, per tutte le categorie attive e i link su home/catalogo/categoria, slider e recensioni, unico toggle fuori dagli header su tutti i layout, mapping e fallback icone.
- `npm run build`: superato.
- `git diff --check`: superato.

Come nella verifica precedente, nei soli processi di test/anteprima `MARKETPLACE_COMMISSION_VAT_BASIS_POINTS` è vuoto perché il binding dell'ambiente contiene un valore non numerico. Nessun file runtime modificato. Suite eseguita con accesso ai socket locali richiesto dai test installer, senza incassi o chiamate a provider reali.

Browser Chromium / Playwright: home e catalogo a **375, 430, 768, 1024, 1440 px**, entrambi i temi; dashboard customer/admin/retailer a 375 e 1440 px nei due temi. **32 combinazioni** totali, **zero violazioni axe WCAG 2 A/AA e 2.1 AA**, zero errori JavaScript e zero overflow della pagina. Dropdown controllato aperto e chiuso, click esterno, Escape, Enter e focus con frecce/Home/End. Verificati layout delle recensioni su una riga a tutte le larghezze, frecce e tastiera, scorrimento locale e mantenimento della griglia prodotti desktop. Toggle unico, solo icona, persistente dopo reload e centrato verticalmente.

Fixture solo locali su SQLite dedicato: sette categorie attive, inclusa una categoria futura dal nome lungo, per verificare fallback e scorrimento. Foto fixture assenti; gli asset brand reali di main sono presenti. Screenshot light/dark mobile/desktop e risultati JSON in `work/controls-screenshots/`, non versionati.

Verifica touch aggiuntiva in Chromium mobile emulato a 375 px: eventi touch reali del protocollo browser fanno scorrere sia recensioni sia categorie; verifica riuscita. Anche la freccia con movimento standard (senza reduced motion) avanza correttamente. Non è stato eseguito un collaudo su dispositivo fisico.
