import './pwa';
import reader from './reader';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('reader', reader);
});
