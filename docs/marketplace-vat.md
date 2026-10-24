# IVA vendite marketplace — 24 ottobre 2026

## EXISTING

Ogni prodotto ha trattamento `vat_treatment` e aliquota `vat_rate_basis_points`, più `shipping_vat_treatment` e `shipping_vat_rate_basis_points` indipendenti. Il form accetta percentuali esplicite tra 0 e 100 con massimo due decimali, punto o virgola (22 → 2200 bp; 4,25 → 425 bp). Nessuna aliquota è ricavata dalla categoria, dal piano o da altre righe. La configurazione globale storica `tax_rates_basis_points` non viene utilizzata per le vendite; resta compatibile con il workflow scambi preesistente, fuori dal perimetro di questo intervento.

- `included`: prezzo indicato lordo; imponibile = lordo × 10000 / (10000 + aliquota), arrotondato al centesimo half-up; IVA = lordo − imponibile.
- `excluded`: prezzo indicato imponibile; IVA = imponibile × aliquota / 10000, arrotondata half-up; lordo = imponibile + IVA.
- `not_applicable`: prezzo invariato, imposta zero; aliquota vuota o zero, mai positiva.

Quantità in millesimi e prezzi in centesimi: prima si arrotonda l’importo della riga, poi si calcola l’IVA. Calcoli con interi/Brick Math, nessun float. Un’aliquota zero per `included`/`excluded` deve essere indicata esplicitamente. Un trattamento/aliquota mancante o incoerente mantiene **IVA da definire** e `totals_provisional`; la conferma di pagamento non può consumare stock o contabilizzare un incasso. La conferma ordine pendente rimane consentita, come prima.

La spedizione è addebitata una volta per riga distinta, non per unità, come nel flusso precedente. Quando il costo è positivo richiede trattamento e aliquota propri; non eredita implicitamente quelli della merce. Ritiro e spedizione gratuita hanno importi/imposte zero senza richiedere una configurazione fiscale. Il costo di spedizione mancante continua a impedire il checkout con spedizione.

## Snapshot e totali

La migration è additiva, con tre colonne nullable e nessun backfill. I prodotti esistenti con IVA inclusa/esclusa restano da configurare; quelli non applicabili conservano il comportamento precedente. Aliquote e trattamento seguono la moderazione esistente: una modifica proposta non cambia il prodotto acquistabile prima dell’approvazione amministrativa.

Le nuove righe ordine hanno `tax_snapshot.policy=explicit_product_vat_v1`. I campi top-level fiscali descrivono la merce; `shipping_tax` contiene trattamento, aliquota, importo indicato, imponibile, IVA e lordo del trasporto. `commission_base_cents` conserva l’imponibile della sola merce. La riga conserva anche `input_amount_cents`; i campi monetari non definiti rimangono null. Gli snapshot di righe/ordini preesistenti non vengono riscritti.

Per le nuove righe, `taxable_cents` e `tax_cents` includono merce e trasporto; `shipping_cost_cents` è il costo trasporto comprensivo delle imposte note. `subtotal_cents` conserva la somma dei prezzi merce indicati; può quindi essere netto o lordo in funzione dei trattamenti e non va sommato ciecamente all’IVA aggregata. `total_cents` somma lordo merce e lordo spedizione; se manca una componente fiscale mantiene il subtotale provvisorio con le imposte già note. I riepiloghi mostrano il dettaglio per riga, senza presentarlo come totale definitivo. `shipping_cents` sui sottoordini/ordine contiene il lordo trasporto noto: il bonus continua a escludere tutto il trasporto, IVA compresa.

La revisione checkout e la conferma ricalcolano server-side sotto lock: ogni modifica fiscale cambia l’hash e richiede riconferma. Aliquote/configurazione successive non modificano ordini già creati. I dettagli ordine, consegna e amministrazione leggono gli snapshot conservati.

## Flussi economici preservati

Commissione FREE 200 bp, PRO 50 bp sull’imponibile merce, senza IVA merce né spedizione. L’importo si arrotonda per riga come prima ed è a carico del venditore, non un supplemento cliente. L’IVA della commissione ZeroMagazzino resta distinta, configurata esclusivamente tramite `MARKETPLACE_COMMISSION_VAT_TREATMENT` e `MARKETPLACE_COMMISSION_VAT_BASIS_POINTS`; l’aliquota del prodotto non la determina. Il relativo snapshot mantiene i valori già concordati.

Nessuna modifica al billing PRO, alle percentuali, al credito retailer, ai limiti bonus o al funds flow. Nessuna attivazione Connect, chiamata live o modifica delle modalità di esecuzione. Verifiche con `simulation` / `simulated_vendor_allocations`, gateway fake e database di test isolati.

## Verifica

`MarketplaceVatTest` copre IVA inclusa/esclusa/non applicabile, aliquote assenti/zero/frazionarie, arrotondamenti, spedizione indipendente, gratuità/ritiro, multi-vendor FREE/PRO, commissioni, blocco incasso provvisorio, snapshot immutabili, hash stale, form, validazione, ownership e moderazione. Le suite esistenti coprono regressioni di ordini, credito/bonus, stock, scambi e abbonamenti. Test MySQL su database dedicato, mai sul database applicativo.

## PLANNED / OPEN BUSINESS DECISION

Fatturazione elettronica, regimi transfrontalieri, reverse charge, motivazioni normative delle esenzioni e attivazione fiscale/PSP live non sono introdotti da questa gestione di aliquote esplicite. Non viene dedotto alcun trattamento da paese, categoria o natura dell’acquirente.

### Risultati della verifica locale

- Suite SQLite completa: 479 passati, 28 saltati esplicitamente, 2964 asserzioni.
- Regressioni MySQL mirate: 101 passati; successiva riconferma finale IVA/checkout: 30 passati, inclusa commissione zero.
- Build Vite, Laravel config/route/view cache e Pint: superati.
- Form e checkout in Chromium a 320, 375, 768 e 1280 px: nessun overflow orizzontale o errore JavaScript; campi fiscali con label associate e altezza minima 44 px. Non è una certificazione WCAG completa.
- Migration applicata al database locale senza backfill. Runtime locale rilevato `disabled` / `stripe_connect_separate_charges_transfers`, lasciato invariato; le suite usano esplicitamente `simulation` / `simulated_vendor_allocations`. Nessun provider reale attivato.
