// Standalone terminal entry point for the default route
import { createApp } from 'vue';
import DeveloperTerminal from './Components/DeveloperTerminal.vue';

// Wait for DOM to be ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTerminal);
} else {
    initTerminal();
}

function initTerminal() {
    const container = document.getElementById('overlord-terminal-container');
    if (!container) {
        console.error('Terminal container not found');
        return;
    }

    // Create Vue app with DeveloperTerminal component
    const app = createApp({
        components: {
            DeveloperTerminal
        },
        template: '<DeveloperTerminal :visible="true" :floating="false" />'
    });

    app.mount('#overlord-terminal-container');
}

