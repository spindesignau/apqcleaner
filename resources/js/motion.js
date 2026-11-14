// Motion & feedback helpers. Everything respects "reduce motion" (CSS shortens animations; JS skips count-ups).
const reduceMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Thin loading bar at the top of the page while the next page loads. */
export function progressBar() {
    const bar = document.createElement('div');
    bar.id = 'apq-progress';
    bar.setAttribute('aria-hidden', 'true');
    document.body.prepend(bar);
    let timer;
    const start = () => {
        bar.classList.remove('is-done');
        void bar.offsetWidth;   // restart the transition
        bar.classList.add('is-active');
        clearTimeout(timer);
        timer = setTimeout(done, 12000);   // never stay stuck (e.g. a download that doesn't leave the page)
    };
    const done = () => { bar.classList.remove('is-active'); bar.classList.add('is-done'); };

    document.addEventListener('click', (e) => {
        const a = e.target.closest('a[href]');
        if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const url = new URL(a.href, location.href);
        if (a.target || a.hasAttribute('download') || a.dataset.noProgress !== undefined || url.origin !== location.origin
            || url.hash && url.pathname === location.pathname || url.searchParams.get('format') === 'csv') return;
        start();
    });
    document.addEventListener('submit', (e) => queueMicrotask(() => { if (!e.defaultPrevented) start(); }));
    window.addEventListener('pageshow', done);   // back/forward cache
}

/** Forms with data-confirm="Message" ask first, in an animated APQ dialog instead of the browser's box. */
export function confirmForms() {
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!form.dataset.confirm || form.dataset.confirmed) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        window.dispatchEvent(new CustomEvent('apq-confirm', { detail: {
            message: form.dataset.confirm,
            okLabel: form.dataset.confirmOk || null,
            danger: form.dataset.confirmDanger !== undefined,
            onConfirm: () => { form.dataset.confirmed = '1'; form.requestSubmit(); },
        } }));
    }, true);
}

/** Alpine component for the dialog (see components/confirm-dialog.blade.php). */
export const confirmDialog = () => ({
    open: false, message: '', okLabel: null, danger: false, onConfirm: null, lastFocus: null,
    show({ detail }) {
        Object.assign(this, { message: detail.message, okLabel: detail.okLabel, danger: detail.danger, onConfirm: detail.onConfirm, open: true });
        this.lastFocus = document.activeElement;
        this.$nextTick(() => this.$refs.ok?.focus());
    },
    confirm() { this.open = false; const cb = this.onConfirm; this.onConfirm = null; cb && cb(); },
    cancel() { this.open = false; this.onConfirm = null; this.lastFocus?.focus?.(); },
});

/** A short buzz on START / STOP / SUBMIT so cleaners feel the tap registered (Android; ignored elsewhere). */
export function haptics() {
    document.addEventListener('submit', (e) => {
        if (e.target.dataset.haptic === undefined) return;
        queueMicrotask(() => { if (!e.defaultPrevented && navigator.vibrate) navigator.vibrate(30); });
    });
}

/** Numbers in dashboard tiles count up from 0. */
export function countUp() {
    if (reduceMotion()) return;
    document.querySelectorAll('[data-count-up]').forEach((el) => {
        const target = parseInt(el.textContent.replace(/[^\d-]/g, ''), 10);
        if (!Number.isFinite(target) || target === 0) return;
        const started = performance.now();
        const tick = (now) => {
            const t = Math.min(1, (now - started) / 600);
            el.textContent = Math.round(target * (1 - Math.pow(1 - t, 3))).toLocaleString();
            if (t < 1) requestAnimationFrame(tick);
        };
        el.textContent = '0';
        requestAnimationFrame(tick);
    });
}

/** Alpine component: shows a banner while the phone has no signal. */
export const connection = () => ({
    online: navigator.onLine,
    init() {
        window.addEventListener('online', () => (this.online = true));
        window.addEventListener('offline', () => (this.online = false));
    },
});
