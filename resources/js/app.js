import uploader from './uploader';

// This module can run before or after Livewire starts Alpine; a store added late still reaches the panel.
const registerStore = () => window.Alpine.store('uploads', uploader());

if (window.Alpine) registerStore();
else document.addEventListener('alpine:init', registerStore);

// A reload or a closed tab would drop an upload in progress (it can be resumed by adding the file again).
window.addEventListener('beforeunload', (event) => {
    if (window.Alpine?.store('uploads')?.active) event.preventDefault();
});
