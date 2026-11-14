import Alpine from 'alpinejs';
import jobTimer from './job-timer';
import { confirmDialog, confirmForms, connection, countUp, haptics, progressBar } from './motion';

// Confirm dialogs must run first so a cancelled form never shows a spinner or loading bar.
confirmForms();

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

// Forms marked data-gps try to attach the phone's location (max 3 s), then submit regardless.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form.dataset.gps === undefined || form.dataset.gpsDone || !navigator.geolocation) return;
    event.preventDefault();
    if (form.dataset.gpsPending) return;   // ignore extra taps while we wait for the location
    form.dataset.gpsPending = '1';
    form.querySelectorAll('button[type="submit"]').forEach((b) => b.setAttribute('aria-busy', 'true'));
    const send = () => { form.dataset.gpsDone = '1'; form.requestSubmit(); };
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            form.querySelector('[name=lat]').value = pos.coords.latitude;
            form.querySelector('[name=lng]').value = pos.coords.longitude;
            send();
        },
        send,
        { enableHighAccuracy: false, timeout: 3000, maximumAge: 300000 },
    );
}, true);

haptics();

Alpine.data('jobTimer', jobTimer);
Alpine.data('confirmDialog', confirmDialog);
Alpine.data('connection', connection);
window.Alpine = Alpine;
Alpine.start();

const ready = () => { progressBar(); countUp(); };
document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', ready, { once: true }) : ready();
