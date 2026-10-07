import uploader from './uploader';

document.addEventListener('alpine:init', () => {
    window.Alpine.store('uploads', uploader());
});

// A reload or a closed tab would drop an upload in progress (it can be resumed by adding the file again).
window.addEventListener('beforeunload', (event) => {
    if (window.Alpine?.store('uploads')?.active) event.preventDefault();
});
