import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Resolve from window.__PUSHER_CONFIG__ (injected by Laravel), Vite env, or fallback defaults
const windowConfig = typeof window !== 'undefined'
    ? (window as unknown as { __PUSHER_CONFIG__?: { key?: string; cluster?: string } }).__PUSHER_CONFIG__
    : undefined;

const envKey = import.meta.env.VITE_PUSHER_APP_KEY;
const envCluster = import.meta.env.VITE_PUSHER_APP_CLUSTER;

const key = windowConfig?.key && !/[${}]/.test(windowConfig.key)
    ? windowConfig.key
    : (envKey && !/[${}]/.test(envKey) ? envKey : 'b74832fd3df724e15486');

const cluster = windowConfig?.cluster && !/[${}]/.test(windowConfig.cluster)
    ? windowConfig.cluster
    : (envCluster && !/[${}]/.test(envCluster) ? envCluster : 'ap1');

export const echo = (key && cluster)
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

