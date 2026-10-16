<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, CalendarClock, CheckCircle2, Clock3, FileSpreadsheet, Plus } from '@lucide/vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import Button from '@/components/ui/button/Button.vue';
import Card from '@/components/ui/card/Card.vue';
import CardContent from '@/components/ui/card/CardContent.vue';
import CardHeader from '@/components/ui/card/CardHeader.vue';
import CardTitle from '@/components/ui/card/CardTitle.vue';
import { dashboard } from '@/routes';
import type { DashboardInvitation, Team } from '@/types';

type Overview = { queuedImports: number; completedImports: number; upcomingSchedules: number };
type RecentImport = { id: string; filename: string; status: 'pending' | 'processing' | 'completed' | 'failed'; percentage: number; createdAt: string };
type NextSchedule = { id: string; original_filename: string; scheduled_for: string } | null;

const props = defineProps<{
    pendingInvitations?: DashboardInvitation[];
    overview: Overview;
    recentImports: RecentImport[];
    nextSchedule: NextSchedule;
}>();

function formatDate(value: string): string {
    return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(value));
}

function statusClass(status: RecentImport['status']): string {
    return {
        pending: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
        processing: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
        completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
        failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    }[status];
}

defineOptions({
    layout: (pageProps: { currentTeam?: Team | null }) => ({
        breadcrumbs: [{ title: 'Dashboard', href: pageProps.currentTeam ? dashboard(pageProps.currentTeam.slug) : '/' }],
    }),
});
</script>

<template>
    <Head title="Dashboard" />
    <PendingInvitationsModal v-if="pendingInvitations?.length" :invitations="pendingInvitations" />

    <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8">
        <section class="flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
            <div>
                <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">Import operations</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight">Your team’s invoice workspace</h1>
                <p class="mt-2 text-sm text-muted-foreground">Track imports, resolve issues, and plan work around your schedule.</p>
            </div>
            <div class="flex gap-2">
                <Button variant="outline" as-child><Link :href="`/${$page.props.currentTeam?.slug}/schedule`"><CalendarClock class="size-4" /> Schedule</Link></Button>
                <Button as-child><Link :href="`/${$page.props.currentTeam?.slug}/imports`"><Plus class="size-4" /> New import</Link></Button>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-3">
            <Card><CardContent class="flex items-center gap-4 p-5"><span class="grid size-10 place-items-center rounded-xl bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300"><Clock3 class="size-5" /></span><div><p class="text-2xl font-semibold">{{ overview.queuedImports }}</p><p class="text-sm text-muted-foreground">Active imports</p></div></CardContent></Card>
            <Card><CardContent class="flex items-center gap-4 p-5"><span class="grid size-10 place-items-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"><CheckCircle2 class="size-5" /></span><div><p class="text-2xl font-semibold">{{ overview.completedImports }}</p><p class="text-sm text-muted-foreground">Completed imports</p></div></CardContent></Card>
            <Card class="border-indigo-200/70 bg-indigo-50/40 dark:border-indigo-900/60 dark:bg-indigo-950/20"><CardContent class="flex items-center gap-4 p-5"><span class="grid size-10 place-items-center rounded-xl bg-indigo-600 text-white"><CalendarClock class="size-5" /></span><div><p class="text-2xl font-semibold">{{ overview.upcomingSchedules }}</p><p class="text-sm text-muted-foreground">Scheduled imports</p></div></CardContent></Card>
        </section>

        <section class="grid gap-5 lg:grid-cols-[1.6fr_1fr]">
            <Card>
                <CardHeader class="flex-row items-center justify-between space-y-0 border-b pb-4"><CardTitle>Recent imports</CardTitle><Link :href="`/${$page.props.currentTeam?.slug}/imports`" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">View all <ArrowRight class="size-4" /></Link></CardHeader>
                <CardContent class="p-0">
                    <div v-if="recentImports.length" class="divide-y">
                        <Link v-for="item in recentImports" :key="item.id" :href="`/${$page.props.currentTeam?.slug}/imports`" class="flex items-center gap-3 px-5 py-4 transition-colors hover:bg-muted/40">
                            <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-muted"><FileSpreadsheet class="size-4 text-muted-foreground" /></span>
                            <div class="min-w-0 flex-1"><p class="truncate text-sm font-medium">{{ item.filename }}</p><p class="mt-1 text-xs text-muted-foreground">{{ formatDate(item.createdAt) }}</p></div>
                            <div class="text-right"><span :class="statusClass(item.status)" class="rounded-full px-2 py-1 text-xs font-medium">{{ item.status }}</span><p v-if="item.status === 'processing'" class="mt-1 text-xs text-muted-foreground">{{ item.percentage }}% complete</p></div>
                        </Link>
                    </div>
                    <div v-else class="px-6 py-12 text-center"><FileSpreadsheet class="mx-auto size-8 text-muted-foreground" /><p class="mt-3 text-sm font-medium">No imports yet</p><p class="mt-1 text-sm text-muted-foreground">Upload your first workbook to begin.</p></div>
                </CardContent>
            </Card>
            <Card class="overflow-hidden">
                <CardHeader class="bg-gradient-to-br from-indigo-600 to-violet-700 text-white"><CardTitle class="text-base">Next scheduled run</CardTitle></CardHeader>
                <CardContent class="p-5">
                    <template v-if="nextSchedule"><span class="grid size-10 place-items-center rounded-xl bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300"><CalendarClock class="size-5" /></span><p class="mt-4 truncate font-medium">{{ nextSchedule.original_filename }}</p><p class="mt-1 text-sm text-muted-foreground">{{ formatDate(nextSchedule.scheduled_for) }}</p><Button variant="outline" size="sm" class="mt-5" as-child><Link :href="`/${$page.props.currentTeam?.slug}/schedule`">Manage schedule <ArrowRight class="size-4" /></Link></Button></template>
                    <template v-else><p class="text-sm font-medium">Keep the queue moving</p><p class="mt-2 text-sm leading-6 text-muted-foreground">Schedule a workbook now and it will enter your import queue at the time you choose.</p><Button size="sm" class="mt-5" as-child><Link :href="`/${$page.props.currentTeam?.slug}/schedule`">Create schedule <CalendarClock class="size-4" /></Link></Button></template>
                </CardContent>
            </Card>
        </section>
    </div>
</template>
