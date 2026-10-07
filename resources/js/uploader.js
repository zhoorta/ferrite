const MAX_RETRIES = 5;

/**
 * Alpine store (`$store.uploads`) that uploads files and folders in chunks, one file at a time.
 * It lives outside the pages, and its panel is persisted in the layout, so an upload carries on
 * while you navigate. A failed chunk is retried after asking the server how much it already has.
 *
 * Once the last chunk is in, the server copies the file to its disk in a background job (slow on
 * remote disks). The item is then "processing": the next file starts uploading and a poll on
 * `GET /uploads/{id}` reports done or failed.
 */
export default function uploader() {
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

    const request = (base, method, path, { json, body, headers = {} } = {}) =>
        fetch(`${base}${path}`, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf(),
                ...(json ? { 'Content-Type': 'application/json' } : {}),
                ...headers,
            },
            body: json ? JSON.stringify(json) : body,
        });

    const errorMessage = async (response) => {
        try {
            const data = await response.json();
            return Object.values(data.errors ?? {}).flat()[0] ?? data.message ?? `Error ${response.status}`;
        } catch {
            return `Error ${response.status}`;
        }
    };

    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    // Recursively read a dropped directory entry into { file, path } pairs.
    const readEntry = async (entry, prefix = '') => {
        if (entry.isFile) {
            const file = await new Promise((resolve, reject) => entry.file(resolve, reject));
            return [{ file, path: prefix + entry.name }];
        }

        const reader = entry.createReader();
        const children = [];

        for (;;) {
            const batch = await new Promise((resolve, reject) => reader.readEntries(resolve, reject));
            if (batch.length === 0) break;
            children.push(...batch);
        }

        const nested = await Promise.all(children.map((child) => readEntry(child, `${prefix}${entry.name}/`)));
        return nested.flat();
    };

    return {
        items: [],
        running: false,
        // Something finished since the browsers on screen were last told to reload.
        dirty: false,

        get active() {
            return this.items.some((item) => ['queued', 'uploading'].includes(item.status));
        },

        // Overall progress across every item that is not cancelled.
        get total() {
            const items = this.items.filter((item) => item.status !== 'cancelled');
            const bytes = items.reduce((sum, item) => sum + item.file.size, 0);
            const sent = items.reduce((sum, item) => sum + (item.status === 'done' ? item.file.size : item.sent), 0);
            const done = items.filter((item) => item.status === 'done').length;

            return { files: items.length, done, bytes, sent, percent: bytes ? Math.round((sent / bytes) * 100) : (done === items.length ? 100 : 0) };
        },

        // `target` is { parentId, baseUrl }: where the files go, captured when they are added.
        // From a file input: folder inputs expose webkitRelativePath.
        pick(fileList, target) {
            this.enqueue([...fileList].map((file) => ({ file, path: file.webkitRelativePath || file.name })), target);
        },

        async drop(event, target) {
            // Entries must be taken synchronously, before the first await.
            const entries = [...(event.dataTransfer?.items ?? [])].map((item) => item.webkitGetAsEntry?.()).filter(Boolean);

            if (entries.length === 0) {
                return this.pick(event.dataTransfer?.files ?? [], target);
            }

            this.enqueue((await Promise.all(entries.map((entry) => readEntry(entry)))).flat(), target);
        },

        enqueue(files, { parentId, baseUrl }) {
            for (const { file, path } of files) {
                this.items.push({
                    key: crypto.randomUUID(),
                    file,
                    path,
                    parentId,
                    base: baseUrl.replace(/\/+$/, ''),
                    status: 'queued',
                    sent: 0,
                    error: null,
                    uploadId: null,
                });
            }

            this.run();
        },

        async run() {
            if (this.running) return;
            this.running = true;

            for (const item of this.items) {
                if (item.status !== 'queued') continue;

                await this.send(item);
            }

            this.running = false;
            this.finishBatch();
        },

        // Once nothing is left to send or store, lets a file browser on screen reload its list.
        finishBatch() {
            if (!this.dirty || this.items.some((item) => ['queued', 'uploading', 'processing'].includes(item.status))) return;

            this.dirty = false;
            window.dispatchEvent(new CustomEvent('ferrite-uploaded'));
        },

        // The server's word on an upload: still receiving, handed to the storing job, or stored.
        settle(item, state) {
            if (state.status === 'processing') {
                item.status = 'processing';
                this.watch(item);
            } else if (['done', 'failed'].includes(state.status)) {
                this.conclude(item, state);
            }
        },

        // A final answer: show it, then drop the record, which only exists for this answer.
        conclude(item, state) {
            if (state.status === 'done') {
                item.status = 'done';
                this.dirty = true;
            } else {
                item.status = 'error';
                item.error = state.error ?? 'The file could not be stored.';
            }

            request(item.base, 'DELETE', `/uploads/${item.uploadId}`).catch(() => {});
        },

        // Polls a file that is being stored until the server reports the outcome.
        async watch(item) {
            let failures = 0;

            for (let polls = 0; item.status === 'processing'; polls++) {
                await sleep(Math.min(5000, 1000 + polls * 500));

                try {
                    const response = await request(item.base, 'GET', `/uploads/${item.uploadId}`);

                    if (response.status === 404) throw { fatal: true, message: 'The server lost track of this upload. Check the folder or upload it again.' };
                    if (!response.ok) throw new Error(`Error ${response.status}`);

                    const state = await response.json();
                    failures = 0;

                    if (['done', 'failed'].includes(state.status)) this.conclude(item, state);
                } catch (error) {
                    if (error.fatal || ++failures > 30) {
                        item.status = 'error';
                        item.error = error.fatal ? error.message : 'Lost contact with the server while it was storing the file. Reload to see whether it arrived.';
                    }
                }
            }

            this.finishBatch();
        },

        async send(item) {
            item.status = 'uploading';

            try {
                const started = await request(item.base, 'POST', '/uploads', {
                    json: {
                        parent_id: item.parentId,
                        path: item.path,
                        size: item.file.size,
                        fingerprint: String(item.file.lastModified),
                    },
                });

                if (!started.ok) throw { fatal: true, message: await errorMessage(started) };

                let state = await started.json();
                item.uploadId = state.id;
                item.sent = state.offset;

                let failures = 0;

                while (item.status === 'uploading') {
                    const end = Math.min(state.offset + state.chunk_size, item.file.size);

                    try {
                        const response = await request(item.base, 'PATCH', `/uploads/${state.id}`, {
                            body: item.file.slice(state.offset, end),
                            headers: { 'Content-Type': 'application/octet-stream', 'Upload-Offset': String(state.offset) },
                        });

                        if (response.status === 409) {
                            // Out of step with the server: carry on from where it is (or, if it has
                            // everything already, from its verdict).
                            state = { ...state, ...(await response.json()) };
                            item.sent = state.offset;
                            this.settle(item, state);
                            continue;
                        }

                        if (response.status === 422) {
                            // Stored synchronously (no queue worker) and failed: a final answer like any other.
                            const body = await response.clone().json().catch(() => ({}));
                            if (body.status === 'failed') {
                                this.conclude(item, { status: 'failed', error: body.message });
                                continue;
                            }
                        }

                        if (!response.ok) {
                            if (response.status < 500) throw { fatal: true, message: await errorMessage(response) };
                            throw new Error(`Error ${response.status}`);
                        }

                        state = { ...state, ...(await response.json()) };
                        item.sent = state.offset;
                        failures = 0;

                        this.settle(item, state);
                    } catch (error) {
                        if (error.fatal || ++failures > MAX_RETRIES) throw error;

                        await sleep(1000 * 2 ** (failures - 1));

                        const check = await request(item.base, 'GET', `/uploads/${state.id}`).catch(() => null);
                        if (check?.ok) {
                            state = { ...state, ...(await check.json()) };
                            this.settle(item, state);
                        }
                    }
                }
            } catch (error) {
                if (item.status !== 'cancelled') {
                    item.status = 'error';
                    item.error = error.message ?? 'Upload failed';
                }
            }
        },

        async cancel(item) {
            const wasUploading = item.status === 'uploading';
            item.status = 'cancelled';

            if (item.uploadId && wasUploading) {
                await request(item.base, 'DELETE', `/uploads/${item.uploadId}`).catch(() => {});
            }
        },

        // Queued files are only marked; the one in flight also has its partial upload deleted.
        cancelAll() {
            this.items.filter((item) => ['queued', 'uploading'].includes(item.status)).forEach((item) => this.cancel(item));
        },

        retry(item) {
            item.status = 'queued';
            item.error = null;
            this.run();
        },

        clear() {
            this.items = this.items.filter((item) => ['queued', 'uploading', 'processing'].includes(item.status));
        },
    };
}
