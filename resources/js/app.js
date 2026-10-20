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

window.Alpine = Alpine;
Alpine.start();
