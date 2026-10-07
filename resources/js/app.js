import uploader from './uploader';

// This module can run before or after Livewire starts Alpine; a store added late still reaches the panel.
const registerStore = () => window.Alpine.store('uploads', uploader());

if (window.Alpine) registerStore();
else document.addEventListener('alpine:init', registerStore);

// A reload or a closed tab would drop an upload in progress (it can be resumed by adding the file again).
window.addEventListener('beforeunload', (event) => {
    if (window.Alpine?.store('uploads')?.active) event.preventDefault();
});

// Drag a file or folder row onto a folder row (or ".." ) to move it. Rows carry `data-node-id` (draggable) and
// `data-drop-id` (target; empty means the root). Delegated, so it survives Livewire re-renders.
const NODE = 'application/x-ferrite-node';
const clearOver = () => document.querySelectorAll('[data-over]').forEach((el) => delete el.dataset.over);
const dropTarget = (event) => (event.dataTransfer?.types.includes(NODE) ? event.target.closest?.('[data-drop-id]') : null);

document.addEventListener('dragstart', (event) => {
    const row = event.target.closest?.('[data-node-id]');
    if (!row) return;

    event.dataTransfer.setData(NODE, row.dataset.nodeId);
    event.dataTransfer.effectAllowed = 'move';
});

document.addEventListener('dragover', (event) => {
    const target = dropTarget(event);
    clearOver();
    if (!target) return;

    event.preventDefault();
    target.dataset.over = '';
});

document.addEventListener('dragend', clearOver);

document.addEventListener('drop', (event) => {
    const target = dropTarget(event);
    clearOver();
    if (!target) return;

    event.preventDefault();
    const id = Number(event.dataTransfer.getData(NODE));
    const destination = target.dataset.dropId === '' ? null : Number(target.dataset.dropId);
    const root = target.closest('[wire\\:id]');

    if (id && root) window.Livewire.find(root.getAttribute('wire:id')).call('dropMove', id, destination);
});
