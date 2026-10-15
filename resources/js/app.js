import './bootstrap';
import monitorCanvas from './monitor-builder';
import './confirm-modal';

// Livewire ships Alpine; register the builder canvas before or after it has started.
const register = () => window.Alpine.data('monitorCanvas', monitorCanvas);
if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
