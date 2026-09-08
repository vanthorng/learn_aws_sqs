import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const key = import.meta.env.VITE_PUSHER_APP_KEY;
const cluster = import.meta.env.VITE_PUSHER_APP_CLUSTER;

// Vite embeds these values into the browser bundle during the build. Do not
// attempt a Pusher connection when a deployment contains an unresolved env
// placeholder (for example, "${PUSHER_APP_KEY}").
const hasPusherConfiguration =
    Boolean(key && cluster) && !/[${}]/.test(key) && !/[${}]/.test(cluster);

export const echo = hasPusherConfiguration
    ? new Echo({
          broadcaster: 'pusher',
          key,
          cluster,
          forceTLS: true,
          authEndpoint: '/broadcasting/auth',
          withCredentials: true,
          Pusher,
      })
    : null;
