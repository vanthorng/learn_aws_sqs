<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell, CheckCheck } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type AppNotification = {
    id: string;
    title: string;
    message: string;
    url: string;
    readAt: string | null;
    createdAt: string;
};

const page = usePage<{ notifications: { unreadCount: number; items: AppNotification[] } }>();
const notifications = computed(() => page.props.notifications);

function csrfToken(): string | null {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? null;
}

function readNotification(notification: AppNotification): void {
    if (!notification.readAt) {
        fetch(`/notifications/${notification.id}/read`, {
            method: 'PATCH',
            headers: { 'X-CSRF-TOKEN': csrfToken() ?? '', Accept: 'application/json' },
            credentials: 'same-origin',
        }).finally(() => router.reload({ only: ['notifications'] }));
    }
}

function markAllRead(): void {
    fetch('/notifications/read-all', {
        method: 'PATCH',
        headers: { 'X-CSRF-TOKEN': csrfToken() ?? '', Accept: 'application/json' },
        credentials: 'same-origin',
    }).finally(() => router.reload({ only: ['notifications'] }));
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon" class="relative size-10 rounded-full" aria-label="Notifications">
                <Bell class="size-5" />
                <span v-if="notifications.unreadCount" class="absolute right-0.5 top-0.5 grid size-4 place-items-center rounded-full bg-destructive text-[10px] font-semibold text-destructive-foreground">
                    {{ notifications.unreadCount > 9 ? '9+' : notifications.unreadCount }}
                </span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-[min(24rem,calc(100vw-2rem))] p-0">
            <div class="flex items-center justify-between px-3 py-2">
                <DropdownMenuLabel class="p-0">Notifications</DropdownMenuLabel>
                <Button v-if="notifications.unreadCount" variant="ghost" size="sm" class="h-7 px-2 text-xs" @click="markAllRead">
                    <CheckCheck class="size-3.5" /> Mark all read
                </Button>
            </div>
            <DropdownMenuSeparator class="m-0" />
            <div v-if="notifications.items.length" class="max-h-96 overflow-y-auto">
                <Link v-for="item in notifications.items" :key="item.id" :href="item.url" class="block border-b px-3 py-3 text-sm last:border-0 hover:bg-muted/60" :class="!item.readAt && 'bg-primary/5'" @click="readNotification(item)">
                    <p class="font-medium">{{ item.title }}</p>
                    <p class="mt-1 text-xs leading-5 text-muted-foreground">{{ item.message }}</p>
                </Link>
            </div>
            <p v-else class="px-3 py-8 text-center text-sm text-muted-foreground">You’re all caught up.</p>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
