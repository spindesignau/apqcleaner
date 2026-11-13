import Alpine from 'alpinejs';

// Disable submit buttons once a form is sent, so double taps never post twice.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form.dataset.allowResubmit !== undefined) return;
    queueMicrotask(() => {
        if (event.defaultPrevented) return;
        form.querySelectorAll('button[type="submit"]').forEach((b) => {
            b.disabled = true;
            b.setAttribute('aria-busy', 'true');
        });
    });
});

window.Alpine = Alpine;
Alpine.start();
