import Alpine from 'alpinejs';

Alpine.data('categoryMenu', () => ({
    open: false,
    close(restoreFocus = false) {
        this.open = false;
        if (restoreFocus) this.$refs.trigger.focus();
    },
    links() {
        return [...this.$refs.panel.querySelectorAll('a')];
    },
    focusAt(index) {
        const links = this.links();
        links.at(index)?.focus();
    },
    openAt(index) {
        this.open = true;
        this.$nextTick(() => this.focusAt(index));
    },
    move(direction) {
        const links = this.links();
        if (!links.length) return;
        const index = links.indexOf(document.activeElement);
        this.focusAt((index + direction + links.length) % links.length);
    },
}));

Alpine.data('reviewsSlider', () => ({
    behavior() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth';
    },
    step(direction) {
        const track = this.$refs.track;
        const card = track.querySelector('.review-card');
        if (!card) return;
        const distance = card.getBoundingClientRect().width + parseFloat(getComputedStyle(track).gap || 0);
        track.scrollBy({ left: direction * distance, behavior: this.behavior() });
    },
    goTo(end) {
        const track = this.$refs.track;
        track.scrollTo({ left: end ? track.scrollWidth : 0, behavior: this.behavior() });
    },
}));

Alpine.data('shippingCalculator', ({ endpoint, csrf }) => ({
    busy: false, error: '', fieldErrors: {}, rates: [], informational: false,
    selected: '', alternate: false, version: 0, controller: null,
    invalidate() {
        this.version++;
        this.controller?.abort();
        this.busy = false;
        this.rates = [];
        this.selected = '';
        this.error = '';
        this.fieldErrors = {};
    },
    money(cents) { return (cents / 100).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' }); },
    cheapest() { return Math.min(...this.rates.map(q => q.rate.final_cents)); },
    deliveryTime(rate) {
        const hours = rate.lead_time_hours;
        if (Number.isInteger(hours) && hours > 0) {
            if (hours >= 24) { const days = Math.ceil(hours / 24); return `Consegna indicativa: circa ${days} ${days === 1 ? 'giorno' : 'giorni'}`; }
            return `Consegna indicativa: circa ${hours} ${hours === 1 ? 'ora' : 'ore'}`;
        }
        return 'Tempi di consegna da confermare';
    },
    async calculate() {
        this.invalidate();
        const version = this.version;
        this.controller = new AbortController();
        this.busy = true;
        const form = new FormData(this.$refs.calculation);
        if (!this.alternate) { for (const key of [...form.keys()]) if (key.startsWith('address[')) form.delete(key); }
        else form.delete('address_id');
        try {
            const response = await fetch(endpoint, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: form, signal: this.controller.signal });
            const result = await response.json();
            if (version !== this.version) return;
            if (!response.ok) {
                const errors = result.errors || {};
                for (const [key, messages] of Object.entries(errors)) this.fieldErrors[key.replace(/^address\./, '')] = messages.join(' ');
                throw new Error(Object.values(errors).flat().join(' ') || 'Calcolo non disponibile. Riprova tra poco.');
            }
            this.rates = result.quotes;
            this.informational = result.informational;
            if (!this.rates.length) this.error = 'Nessuna tariffa disponibile per questa spedizione. Controlla indirizzo e quantità.';
        } catch (error) {
            if (error.name !== 'AbortError' && version === this.version) this.error = error.message || 'Connessione non disponibile. Riprova.';
        } finally { if (version === this.version) this.busy = false; }
    },
}));

window.Alpine = Alpine;
Alpine.start();
