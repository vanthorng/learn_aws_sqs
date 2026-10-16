<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, CalendarClock, CheckCircle2, FileSpreadsheet, ShieldCheck, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import Button from '@/components/ui/button/Button.vue';
import { dashboard, login, register } from '@/routes';

const page = usePage();
const appName = computed(() => page.props.name ?? 'InvoiceFlow');
const dashboardUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/',
);
</script>

<template>
    <Head title="Invoice imports, on your schedule" />

    <main class="min-h-screen overflow-hidden bg-slate-50 text-slate-950 dark:bg-slate-950 dark:text-slate-50">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-[34rem] bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-indigo-100 via-slate-50 to-transparent dark:from-indigo-950/70 dark:via-slate-950 dark:to-transparent" />
        <div class="relative mx-auto flex min-h-screen max-w-7xl flex-col px-5 sm:px-8">
            <header class="flex h-20 items-center justify-between">
                <Link href="/" class="flex items-center gap-2.5 font-semibold tracking-tight">
                    <span class="grid size-9 place-items-center rounded-xl bg-slate-950 text-white shadow-sm dark:bg-white dark:text-slate-950"><AppLogoIcon class="size-5 fill-current" /></span>
                    <span>{{ appName }}</span>
                </Link>
                <nav class="flex items-center gap-2 sm:gap-3">
                    <template v-if="$page.props.auth.user">
                        <Button as-child><Link :href="dashboardUrl">Open workspace <ArrowRight class="size-4" /></Link></Button>
                    </template>
                    <template v-else>
                        <Button variant="ghost" as-child><Link :href="login()">Log in</Link></Button>
                        <Button as-child><Link :href="register()">Get started</Link></Button>
                    </template>
                </nav>
            </header>

            <section class="grid flex-1 items-center gap-12 py-14 lg:grid-cols-[1fr_0.95fr] lg:py-20">
                <div class="max-w-2xl">
                    <div class="inline-flex items-center gap-2 rounded-full border border-indigo-200 bg-white/80 px-3 py-1.5 text-sm font-medium text-indigo-700 shadow-sm backdrop-blur dark:border-indigo-900 dark:bg-slate-900/80 dark:text-indigo-300">
                        <Sparkles class="size-3.5" /> Built for reliable invoice operations
                    </div>
                    <h1 class="mt-6 text-4xl font-semibold tracking-[-0.04em] text-balance sm:text-5xl lg:text-6xl">Invoices in. <span class="text-indigo-600 dark:text-indigo-400">Busywork out.</span></h1>
                    <p class="mt-6 max-w-xl text-lg leading-8 text-slate-600 dark:text-slate-300">Upload, validate, schedule, and track invoice work from one calm workspace. Your team stays in control while the queue does the heavy lifting.</p>
                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <Button size="lg" class="h-11 px-5" as-child>
                            <Link :href="$page.props.auth.user ? dashboardUrl : register()">{{ $page.props.auth.user ? 'Open your workspace' : 'Start importing free' }} <ArrowRight class="size-4" /></Link>
                        </Button>
                        <Button size="lg" variant="outline" class="h-11 px-5" as-child><Link :href="$page.props.auth.user ? dashboardUrl : login()">{{ $page.props.auth.user ? 'View dashboard' : 'Log in to your team' }}</Link></Button>
                    </div>
                    <div class="mt-9 flex flex-wrap gap-x-6 gap-y-3 text-sm text-slate-600 dark:text-slate-300">
                        <span class="inline-flex items-center gap-2"><CheckCircle2 class="size-4 text-emerald-600 dark:text-emerald-400" /> XLSX template validation</span>
                        <span class="inline-flex items-center gap-2"><CheckCircle2 class="size-4 text-emerald-600 dark:text-emerald-400" /> Team permissions</span>
                        <span class="inline-flex items-center gap-2"><CheckCircle2 class="size-4 text-emerald-600 dark:text-emerald-400" /> Private file storage</span>
                    </div>
                </div>

                <div class="relative mx-auto w-full max-w-xl">
                    <div class="absolute -inset-5 rounded-[2rem] bg-indigo-300/30 blur-3xl dark:bg-indigo-700/20" />
                    <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-indigo-950/10 dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/30">
                        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 dark:border-slate-800"><div class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-rose-400" /><span class="size-2.5 rounded-full bg-amber-400" /><span class="size-2.5 rounded-full bg-emerald-400" /></div><span class="text-xs font-medium text-slate-400">Import workspace</span></div>
                        <div class="p-5 sm:p-6">
                            <div class="flex items-start justify-between gap-4"><div><p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">This week</p><h2 class="mt-1 text-xl font-semibold">Everything is on track.</h2></div><span class="grid size-10 place-items-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"><CheckCircle2 class="size-5" /></span></div>
                            <div class="mt-6 grid grid-cols-3 gap-3"><div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/70"><p class="text-xl font-semibold">24</p><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Completed</p></div><div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/70"><p class="text-xl font-semibold">2</p><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">In queue</p></div><div class="rounded-xl bg-indigo-50 p-3 dark:bg-indigo-950/50"><p class="text-xl font-semibold text-indigo-700 dark:text-indigo-300">3</p><p class="mt-1 text-xs text-indigo-600/80 dark:text-indigo-300/80">Scheduled</p></div></div>
                            <div class="mt-5 rounded-xl border border-indigo-100 bg-indigo-50/70 p-4 dark:border-indigo-900/70 dark:bg-indigo-950/30"><div class="flex items-center gap-3"><span class="grid size-9 place-items-center rounded-lg bg-indigo-600 text-white"><CalendarClock class="size-4" /></span><div class="min-w-0 flex-1"><p class="truncate text-sm font-medium">September invoices.xlsx</p><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Scheduled for Friday, 9:00 AM</p></div><span class="rounded-full bg-white px-2 py-1 text-[11px] font-medium text-indigo-700 shadow-sm dark:bg-slate-900 dark:text-indigo-300">Upcoming</span></div></div>
                            <div class="mt-4 flex items-center gap-3 rounded-xl border border-slate-100 p-4 dark:border-slate-800"><span class="grid size-9 place-items-center rounded-lg bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300"><FileSpreadsheet class="size-4" /></span><div class="min-w-0 flex-1"><p class="truncate text-sm font-medium">August invoices.xlsx</p><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">1,248 rows processed successfully</p></div><CheckCircle2 class="size-5 text-emerald-600 dark:text-emerald-400" /></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid gap-4 border-t border-slate-200 py-10 sm:grid-cols-3 dark:border-slate-800">
                <div class="flex gap-3"><span class="grid size-9 shrink-0 place-items-center rounded-lg bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300"><FileSpreadsheet class="size-4" /></span><div><h2 class="font-medium">Validate before processing</h2><p class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-400">Catch template issues before they affect your books.</p></div></div>
                <div class="flex gap-3"><span class="grid size-9 shrink-0 place-items-center rounded-lg bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300"><CalendarClock class="size-4" /></span><div><h2 class="font-medium">Schedule around your team</h2><p class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-400">Upload today and run imports at the right time.</p></div></div>
                <div class="flex gap-3"><span class="grid size-9 shrink-0 place-items-center rounded-lg bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300"><ShieldCheck class="size-4" /></span><div><h2 class="font-medium">Stay informed</h2><p class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-400">Follow progress and resolve only the exceptions.</p></div></div>
            </section>
        </div>
    </main>
</template>
