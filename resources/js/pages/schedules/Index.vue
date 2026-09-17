<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { CalendarClock, CheckCircle2, Clock3, FileSpreadsheet, Loader2, Plus, XCircle } from '@lucide/vue';
import Button from '@/components/ui/button/Button.vue';
import Card from '@/components/ui/card/Card.vue';
import CardContent from '@/components/ui/card/CardContent.vue';
import CardDescription from '@/components/ui/card/CardDescription.vue';
import CardHeader from '@/components/ui/card/CardHeader.vue';
import CardTitle from '@/components/ui/card/CardTitle.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import Input from '@/components/ui/input/Input.vue';
import Label from '@/components/ui/label/Label.vue';
import { dashboard } from '@/routes';
import type { Team } from '@/types';

type ScheduledImport = {
    id: string;
    filename: string;
    status: 'scheduled' | 'dispatched' | 'cancelled';
    scheduledFor: string;
    autoSyncQbo: boolean;
    dispatchedAt: string | null;
    invoiceImportId: string | null;
};

const props = defineProps<{
    schedules: ScheduledImport[];
    canManageImports: boolean;
}>();

const page = usePage<{ currentTeam: { slug: string } | null }>();
const open = ref(false);
const file = ref<File | null>(null);
const scheduledFor = ref(defaultScheduleTime());
const autoSyncQbo = ref(false);
const submitting = ref(false);
const errors = ref<Record<string, string>>({});

const scheduleUrl = computed(() => `/${page.props.currentTeam?.slug}/schedule`);
const upcoming = computed(() => props.schedules.filter((item) => item.status === 'scheduled'));
const history = computed(() => props.schedules.filter((item) => item.status !== 'scheduled'));

defineOptions({
    layout: (pageProps: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            { title: 'Dashboard', href: pageProps.currentTeam ? dashboard(pageProps.currentTeam.slug) : '/' },
            { title: 'Schedule', href: pageProps.currentTeam ? `/${pageProps.currentTeam.slug}/schedule` : '/' },
        ],
    }),
});

function defaultScheduleTime(): string {
    const date = new Date(Date.now() + 60 * 60 * 1000);
    date.setMinutes(0, 0, 0);
    const offset = date.getTimezoneOffset();
    return new Date(date.getTime() - offset * 60_000).toISOString().slice(0, 16);
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat(undefined, {
        weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
    }).format(new Date(value));
}

function relativeTime(value: string): string {
    const minutes = Math.round((new Date(value).getTime() - Date.now()) / 60_000);
    if (minutes < 1) return 'Due now';
    if (minutes < 60) return `In ${minutes} min`;
    if (minutes < 1_440) return `In ${Math.round(minutes / 60)} hr`;
    return `In ${Math.round(minutes / 1_440)} days`;
}

function selectFile(event: Event): void {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function closeDialog(): void {
    open.value = false;
    file.value = null;
    autoSyncQbo.value = false;
    scheduledFor.value = defaultScheduleTime();
    errors.value = {};
}

function submit(): void {
    if (!file.value || !scheduledFor.value) return;

    submitting.value = true;
    errors.value = {};
    router.post(scheduleUrl.value, {
        file: file.value,
        scheduled_for: new Date(scheduledFor.value).toISOString(),
        auto_sync_qbo: autoSyncQbo.value,
    }, {
        forceFormData: true,
        preserveScroll: true,
        onError: (nextErrors) => { errors.value = nextErrors; },
        onSuccess: closeDialog,
        onFinish: () => { submitting.value = false; },
    });
}

function cancel(schedule: ScheduledImport): void {
    router.delete(`${scheduleUrl.value}/${schedule.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Schedule imports" />

    <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-7 p-4 sm:p-6 lg:p-8">
        <section class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-950 px-6 py-7 text-white shadow-sm sm:px-8">
            <div class="absolute -right-12 -top-16 size-64 rounded-full bg-indigo-400/15 blur-3xl" />
            <div class="relative flex flex-col justify-between gap-6 sm:flex-row sm:items-end">
                <div class="max-w-2xl">
                    <div class="mb-3 flex items-center gap-2 text-sm font-medium text-indigo-200">
                        <CalendarClock class="size-4" /> Automated processing
                    </div>
                    <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Schedule invoice imports with confidence.</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-300">Upload a validated workbook now. We’ll add it to your normal import queue at exactly the time you choose.</p>
                </div>
                <Dialog v-if="canManageImports" :open="open" @update:open="(value) => value ? open = true : closeDialog()">
                    <DialogTrigger as-child>
                        <Button class="shrink-0 bg-white text-slate-950 hover:bg-slate-100"><Plus class="size-4" /> Schedule import</Button>
                    </DialogTrigger>
                    <DialogContent class="sm:max-w-xl">
                        <form class="space-y-5" @submit.prevent="submit">
                            <DialogHeader>
                                <DialogTitle>Schedule an invoice import</DialogTitle>
                                <DialogDescription>Choose a workbook and when it should be handed to the import queue.</DialogDescription>
                            </DialogHeader>
                            <div class="grid gap-2">
                                <Label for="schedule-file">Invoice workbook</Label>
                                <Input id="schedule-file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" @change="selectFile" />
                                <p class="text-xs text-muted-foreground">XLSX only · up to 20 MB · uses the same template checks as a manual import.</p>
                                <p v-if="errors.file" class="text-sm text-destructive">{{ errors.file }}</p>
                            </div>
                            <div class="grid gap-2">
                                <Label for="scheduled-for">Run date and time</Label>
                                <Input id="scheduled-for" v-model="scheduledFor" type="datetime-local" :min="defaultScheduleTime()" />
                                <p v-if="errors.scheduled_for" class="text-sm text-destructive">{{ errors.scheduled_for }}</p>
                            </div>
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border bg-muted/40 p-3.5">
                                <input v-model="autoSyncQbo" type="checkbox" class="mt-0.5 size-4 rounded border-input accent-primary" />
                                <span>
                                    <span class="block text-sm font-medium">Sync to QuickBooks after importing</span>
                                    <span class="mt-1 block text-xs leading-5 text-muted-foreground">If your team is connected, successfully imported invoices will be sent automatically.</span>
                                </span>
                            </label>
                            <DialogFooter class="gap-2 sm:gap-2">
                                <Button type="button" variant="outline" @click="closeDialog">Cancel</Button>
                                <Button type="submit" :disabled="submitting || !file || !scheduledFor">
                                    <Loader2 v-if="submitting" class="size-4 animate-spin" />
                                    <CalendarClock v-else class="size-4" />
                                    Schedule import
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-3">
            <Card class="border-indigo-200/70 bg-indigo-50/50 dark:border-indigo-900/60 dark:bg-indigo-950/20">
                <CardContent class="flex items-center gap-4 p-5">
                    <span class="grid size-10 place-items-center rounded-xl bg-indigo-600 text-white"><Clock3 class="size-5" /></span>
                    <div><p class="text-2xl font-semibold">{{ upcoming.length }}</p><p class="text-sm text-muted-foreground">Upcoming imports</p></div>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="flex items-center gap-4 p-5">
                    <span class="grid size-10 place-items-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"><CheckCircle2 class="size-5" /></span>
                    <div><p class="text-2xl font-semibold">{{ schedules.filter((item) => item.status === 'dispatched').length }}</p><p class="text-sm text-muted-foreground">Handed to queue</p></div>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="flex items-center gap-4 p-5">
                    <span class="grid size-10 place-items-center rounded-xl bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300"><FileSpreadsheet class="size-5" /></span>
                    <div><p class="text-2xl font-semibold">Every minute</p><p class="text-sm text-muted-foreground">Scheduler checks due work</p></div>
                </CardContent>
            </Card>
        </section>

        <Card>
            <CardHeader class="border-b pb-5">
                <CardTitle>Upcoming queue</CardTitle>
                <CardDescription>Times are shown in your browser’s local timezone.</CardDescription>
            </CardHeader>
            <CardContent class="p-0">
                <div v-if="upcoming.length" class="divide-y">
                    <article v-for="schedule in upcoming" :key="schedule.id" class="flex flex-col gap-4 p-5 transition-colors hover:bg-muted/30 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 items-start gap-3.5">
                            <span class="mt-0.5 grid size-10 shrink-0 place-items-center rounded-lg bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300"><FileSpreadsheet class="size-5" /></span>
                            <div class="min-w-0">
                                <p class="truncate font-medium">{{ schedule.filename }}</p>
                                <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm text-muted-foreground">
                                    <span>{{ formatDate(schedule.scheduledFor) }}</span>
                                    <span v-if="schedule.autoSyncQbo">QuickBooks sync enabled</span>
                                </div>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-3 sm:justify-end">
                            <span class="rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">{{ relativeTime(schedule.scheduledFor) }}</span>
                            <Button v-if="canManageImports" variant="ghost" size="sm" class="text-muted-foreground hover:text-destructive" @click="cancel(schedule)"><XCircle class="size-4" /> Cancel</Button>
                        </div>
                    </article>
                </div>
                <div v-else class="grid min-h-56 place-items-center px-6 text-center">
                    <div><span class="mx-auto grid size-12 place-items-center rounded-full bg-muted"><CalendarClock class="size-6 text-muted-foreground" /></span><h2 class="mt-4 font-medium">Nothing is scheduled</h2><p class="mt-1 max-w-sm text-sm text-muted-foreground">Plan an import for a quieter time and it will enter the same reliable processing queue automatically.</p></div>
                </div>
            </CardContent>
        </Card>

        <Card v-if="history.length">
            <CardHeader><CardTitle>Recent activity</CardTitle></CardHeader>
            <CardContent class="p-0">
                <div class="divide-y">
                    <div v-for="schedule in history" :key="schedule.id" class="flex items-center justify-between gap-4 px-5 py-4 text-sm">
                        <div class="min-w-0"><p class="truncate font-medium">{{ schedule.filename }}</p><p class="mt-1 text-muted-foreground">Scheduled for {{ formatDate(schedule.scheduledFor) }}</p></div>
                        <span :class="schedule.status === 'dispatched' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-muted text-muted-foreground'" class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium">{{ schedule.status === 'dispatched' ? 'Dispatched' : 'Cancelled' }}</span>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
