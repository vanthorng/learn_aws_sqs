import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const key = import.meta.env.VITE_PUSHER_APP_KEY;

export const echo = key
    ? new Echo({
          broadcaster: 'pusher',
          key,
          cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
          forceTLS: true,
          authEndpoint: '/broadcasting/auth',
          withCredentials: true,
          Pusher,
      })
    : null;
