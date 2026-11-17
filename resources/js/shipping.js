// One transport/error boundary for every informational shipping calculator.
export const messages = {
    SHIPPING_UNEXPECTED: 'Non siamo riusciti a calcolare la spedizione. Riprova; se il problema persiste, contatta l’assistenza.',
    SHIPPING_NETWORK: 'Non è stato possibile comunicare con il servizio di calcolo delle spedizioni. Riprova. Se il problema persiste, contatta l’assistenza.',
    SHIPPING_TIMEOUT: 'Il calcolo della spedizione sta impiegando troppo tempo. Riprova tra qualche istante.',
    SHIPPING_SESSION_EXPIRED: 'La sessione è scaduta. Ricarica la pagina e accedi nuovamente prima di calcolare la spedizione.',
    SHIPPING_FORBIDDEN: 'Non puoi calcolare questa spedizione con l’account attuale. Verifica l’accesso o contatta l’assistenza.',
    SHIPPING_RATE_LIMITED: 'Sono state richieste troppe tariffe. Attendi prima di riprovare.',
    SHIPPING_INVALID_RESPONSE: 'Il servizio di spedizione ha restituito una risposta non utilizzabile. Riprova più tardi.',
    SHIPPING_NO_RATES: 'Al momento non sono disponibili tariffe per questa spedizione. Verifica i dati o prova un altro indirizzo.',
};
export function failure(code, retryable = true) {
    return { code, message: messages[code] || messages.SHIPPING_UNEXPECTED, retryable, fields: [], reference: null };
}
function markFields(root, fields) {
    if (!root?.querySelectorAll) return;
    for (const input of root.querySelectorAll('input, select, textarea')) {
        const normalized = input.name.replace(/\[([^\]]+)\]/g, '.$1');
        const invalid = fields.includes(normalized) || fields.includes(normalized.replace(/^address\./, ''));
        if (invalid) {
            input.setAttribute('aria-invalid', 'true');
            input.classList.add('border-danger', 'ring-2', 'ring-danger');
            input.dataset.shippingInvalid = 'true';
        } else if (input.dataset.shippingInvalid) {
            input.removeAttribute('aria-invalid');
            input.classList.remove('border-danger', 'ring-2', 'ring-danger');
            delete input.dataset.shippingInvalid;
        }
    }
}
export function quoteState() {
    return {
        busy: false, error: '', retryable: false, reference: '', fieldErrors: {}, version: 0, controller: null,
        invalidate() {
            this.version++;
            this.controller?.abort();
            this.busy = false;
            this.error = ''; this.retryable = false; this.reference = ''; this.fieldErrors = {};
            markFields(this.$root, []);
        },
        showError(error) {
            this.error = error.message;
            this.retryable = error.retryable === true;
            this.reference = error.reference || '';
            markFields(this.$root, error.fields || []);
            for (const field of error.fields || []) this.fieldErrors[field.replace(/^address\./, '')] = error.message;
        },
    };
}
export async function requestQuotes(endpoint, csrf, form, controller, validate, timeout = 55000) {
    let timedOut = false;
    const timer = setTimeout(() => { timedOut = true; controller.abort(); }, timeout);
    try {
        const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', redirect: 'error', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: form, signal: controller.signal });
        if ([401, 419].includes(response.status)) throw failure('SHIPPING_SESSION_EXPIRED', false);
        if (response.status === 403) throw failure('SHIPPING_FORBIDDEN', false);
        if (response.status === 429) throw failure('SHIPPING_RATE_LIMITED');
        let result;
        try { result = await response.json(); } catch { throw failure('SHIPPING_INVALID_RESPONSE'); }
        if (!response.ok || result?.success === false) {
            const error = result?.error;
            if (error && /^SHIPPING_[A-Z_]+$/.test(error.code) && typeof error.message === 'string' && Array.isArray(error.fields) && error.fields.every(f => typeof f === 'string')) throw error;
            throw failure('SHIPPING_UNEXPECTED');
        }
        if (!validate(result)) throw failure('SHIPPING_INVALID_RESPONSE');
        return result;
    } catch (error) {
        if (timedOut) throw failure('SHIPPING_TIMEOUT');
        if (controller.signal.aborted) throw error;
        if (error?.code?.startsWith('SHIPPING_')) throw error;
        throw failure('SHIPPING_NETWORK');
    } finally { clearTimeout(timer); }
}
const validRates = rates => Array.isArray(rates) && rates.every(q => q && typeof q.id === 'string' && q.rate && Number.isInteger(q.rate.final_cents) && typeof q.rate.carrier === 'string' && typeof q.rate.service === 'string');
export const validQuotes = result => result && validRates(result.quotes);
export const validSides = result => result && result.sides && validRates(result.sides.a) && validRates(result.sides.b);

export function exchangeShipping({ endpoint, csrf }) {
    return {
        ...quoteState(), sides: { a: [], b: [] }, selected: { a: '', b: '' },
        resetShipping() { this.invalidate(); this.sides = { a: [], b: [] }; this.selected = { a: '', b: '' }; },
        async quoteShipping() {
            if (this.busy) return;
            this.resetShipping();
            const version = this.version;
            const controller = this.controller = new AbortController();
            this.busy = true;
            try {
                const data = await requestQuotes(endpoint, csrf, new FormData(this.$refs.exchangeForm), controller, validSides);
                if (version !== this.version) return;
                this.sides = data.sides;
            } catch (error) {
                if (version === this.version) this.showError(error?.code ? error : failure('SHIPPING_UNEXPECTED'));
            } finally { if (version === this.version) this.busy = false; }
        },
    };
}
