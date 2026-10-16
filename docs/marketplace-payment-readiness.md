# Readiness pagamenti dopo le risposte del committente

Il PDF `ZeroMagazzino_Decisioni_Pagamenti_Credito.pdf` v1.0 del 14 ottobre, ricevuto il 16 ottobre 2026, risponde alle domande pendenti. Funds flow: Stripe Connect separate charges and transfers. Commissione conguaglio: 50 bp al ricevente; checkout 15m e conguaglio 1440m. Saldo retailer non spendibile al lancio; customer bonus separato, max10% merce, 12 mesi.

Non restano le precedenti domande commerciali generiche. Attivazione, verifica Stripe e fiscalità sono distinte dall'approvazione. Stato implementativo, nuovi adapter e limiti residui sono in [marketplace-payments.md](marketplace-payments.md); decisione in [ADR](adr/marketplace-funds-flow.md).

Il vecchio esempio 500€ = 200€ saldo retailer +300€ carta è superato e non ammesso al lancio. Con 500€ di sola merce, il massimo bonus customer è 50€, residuo carta almeno450€. Nessun bonus sui conguagli. Non creare un saldo cliente monetario ricaricabile.
