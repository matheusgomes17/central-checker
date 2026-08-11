import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/*
 * Conexão com o Reverb.
 *
 * Só é criada quando há chave configurada. Sem isso, uma instalação sem
 * WebSocket ficaria tentando reconectar para sempre em segundo plano — e o
 * erro apareceria no console de quem não pediu tempo real nenhum.
 */
const key = import.meta.env.VITE_REVERB_APP_KEY;

if (key) {
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
