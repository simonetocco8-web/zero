import Alpine from 'alpinejs';
import { quoteState, requestQuotes, validQuotes, failure, exchangeShipping } from './shipping';

Alpine.data('exchangeShipping', exchangeShipping);

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
    ...quoteState(), rates: [], informational: false,
    selected: '', alternate: false,
    invalidate() {
        quoteState().invalidate.call(this);
        this.rates = [];
        this.selected = '';
    },
    showError(error) {
        quoteState().showError.call(this, error);
        if (!this.alternate && (error.fields || []).some(field => ['recipient_name', 'address_line_1', 'postal_code', 'city', 'province', 'country_code'].includes(field.replace(/^address\./, '')))) {
            this.fieldErrors.address_id = error.message + ' Modifica l’indirizzo nella rubrica oppure usa un altro indirizzo.';
        }
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
        if (this.busy) return;
        this.invalidate();
        const version = this.version;
        const controller = this.controller = new AbortController();
        this.busy = true;
        try {
            const form = new FormData(this.$refs.calculation);
            if (!this.alternate) { for (const key of [...form.keys()]) if (key.startsWith('address[')) form.delete(key); }
            else form.delete('address_id');
            const result = await requestQuotes(endpoint, csrf, form, controller, validQuotes);
            if (version !== this.version) return;
            this.rates = result.quotes;
            this.informational = result.informational;
            if (!this.rates.length) this.showError(failure('SHIPPING_NO_RATES', false));
        } catch (error) {
            if (version === this.version) this.showError(error?.code ? error : failure('SHIPPING_UNEXPECTED'));
        } finally {
            if (version === this.version) {
                this.busy = false;
                if (this.error) {
                    await this.$nextTick();
                    // x-show reveals content on the next animation frame.
                    requestAnimationFrame(() => requestAnimationFrame(() => {
                        if (version === this.version) this.$refs.dialog.scrollTop = this.$refs.dialog.scrollHeight;
                    }));
                }
            }
        }
    },
}));

window.Alpine = Alpine;
Alpine.start();
