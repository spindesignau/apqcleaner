// Live job timer. The server owns the start time; the phone only displays elapsed time,
// corrected for clock drift, so closing the browser or losing signal never affects the record.
export default (startedAtIso, serverNowIso) => ({
    skew: Date.parse(serverNowIso) - Date.now(),
    started: Date.parse(startedAtIso),
    display: '00:00:00',
    handle: null,
    init() {
        const pad = (n) => String(n).padStart(2, '0');
        const tick = () => {
            const s = Math.max(0, Math.floor((Date.now() + this.skew - this.started) / 1000));
            this.display = `${pad(Math.floor(s / 3600))}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`;
        };
        tick();
        this.handle = setInterval(tick, 1000);
        document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && tick());
    },
    destroy() {
        clearInterval(this.handle);
    },
});
