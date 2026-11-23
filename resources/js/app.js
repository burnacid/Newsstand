import './pwa';
import pushDevice from './push';
import reader from './reader';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('reader', reader);
    window.Alpine.data('pushDevice', pushDevice);
});
