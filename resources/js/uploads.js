const ACCEPTED = /\.(csv|txt)$/i;
const POLL_DELAY_MS = 5000;
const POLL_EVERY_MS = 3000;
const CLOCK_EVERY_MS = 30000;

export const formatBytes = (bytes) => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
};

export const formatDuration = (ms) => {
    if (ms === null || ms === undefined) return '—';
    return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`;
};

export const relative = (iso, now) => {
    const seconds = Math.max(0, Math.round((now - new Date(iso).getTime()) / 1000));
    if (seconds < 60) return 'just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} min ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours} h ago`;
    return `${Math.floor(hours / 24)} d ago`;
};

export const absolute = (iso) => new Date(iso).toLocaleString();

const BADGES = {
    pending: 'bg-slate-200 text-slate-800',
    processing: 'bg-blue-100 text-blue-900',
    completed: 'bg-green-100 text-green-900',
    failed: 'bg-red-100 text-red-900',
};

export default () => ({
    store: {},
    loading: true,
    live: false,
    dragging: false,
    current: null,
    error: '',
    announcement: '',
    now: Date.now(),
    maxKb: 0,
    pollTimer: null,
    graceTimer: null,
    formatBytes,
    formatDuration,
    relative,
    absolute,

    get rows() {
        return Object.values(this.store).sort(
            (a, b) => new Date(b.created_at) - new Date(a.created_at) || (a.id < b.id ? 1 : -1),
        );
    },

    get maxLabel() {
        return formatBytes(this.maxKb * 1024);
    },

    badgeClass: (status) => BADGES[status] ?? BADGES.pending,
    shortId: (id) => String(id).slice(0, 8),

    init() {
        this.maxKb = Number(this.$el.dataset.maxKb) || 0;
        setInterval(() => (this.now = Date.now()), CLOCK_EVERY_MS);
        this.fetchList().finally(() => (this.loading = false));
        this.watchConnection();
        window.Echo?.channel('uploads').listen('.upload.updated', (payload) => this.upsertRow(payload));
    },

    // The single write path into the store, used by Echo, polling and the upload response.
    upsertRow(row) {
        const data = row?.data ?? row;
        if (!data?.id) return;
        const previous = this.store[data.id];
        this.store = { ...this.store, [data.id]: { ...previous, ...data } };
        if (previous && previous.status !== data.status) {
            this.announcement = `${data.file_name}: ${data.status_label}`;
        }
    },

    async fetchList() {
        try {
            const response = await fetch('/uploads', { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const body = await response.json();
            (body.data ?? []).forEach((row) => this.upsertRow(row));
        } catch {
            // Network hiccup: the next poll or event will catch up.
        }
    },

    watchConnection() {
        const connection = window.Echo?.connector?.pusher?.connection;
        if (!connection) {
            this.startPolling();
            return;
        }
        const apply = (state) => {
            clearTimeout(this.graceTimer);
            if (state === 'connected') {
                this.live = true;
                this.stopPolling();
                this.fetchList(); // catch up on anything missed while offline
                return;
            }
            this.live = false;
            this.graceTimer = setTimeout(() => this.startPolling(), POLL_DELAY_MS);
        };
        connection.bind('state_change', ({ current }) => apply(current));
        apply(connection.state);
    },

    startPolling() {
        if (this.pollTimer) return;
        this.pollTimer = setInterval(() => this.fetchList(), POLL_EVERY_MS);
    },

    stopPolling() {
        clearInterval(this.pollTimer);
        this.pollTimer = null;
    },

    pick(file) {
        this.error = '';
        if (!file) return;
        if (!ACCEPTED.test(file.name)) {
            this.error = 'Only .csv or .txt files are accepted.';
            return;
        }
        if (this.maxKb && file.size > this.maxKb * 1024) {
            this.error = `File is too large (${formatBytes(file.size)}). Maximum is ${this.maxLabel}.`;
            return;
        }
        this.send(file);
    },

    send(file) {
        this.current = { name: file.name, size: file.size, percent: 0 };
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/uploads');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]')?.content ?? '');
        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable && this.current) {
                this.current.percent = Math.round((event.loaded / event.total) * 100);
            }
        };
        xhr.onload = () => {
            let body = {};
            try {
                body = JSON.parse(xhr.responseText);
            } catch {
                // non-JSON error page
            }
            if (xhr.status >= 200 && xhr.status < 300) {
                this.upsertRow(body);
                this.announcement = `${file.name} uploaded, processing will follow.`;
                this.current = null;
            } else {
                this.fail(body.message ?? `Upload failed (HTTP ${xhr.status}).`);
            }
        };
        xhr.onerror = () => this.fail('Network error during upload.');
        const form = new FormData();
        form.append('file', file);
        form.append('website', this.$refs.website?.value ?? '');
        xhr.send(form);
    },

    fail(message) {
        this.error = message;
        this.current = null;
    },
});
