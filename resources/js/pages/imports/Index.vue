<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { AlertCircle, CheckCircle2, Clock, CloudUpload, ExternalLink, FileSpreadsheet, Loader2, RefreshCw, Send, Unlink, Upload } from '@lucide/vue';
import { echo } from '@/echo';
import Button from '@/components/ui/button/Button.vue';
import Card from '@/components/ui/card/Card.vue';
import CardContent from '@/components/ui/card/CardContent.vue';
import CardHeader from '@/components/ui/card/CardHeader.vue';
import CardTitle from '@/components/ui/card/CardTitle.vue';
import Input from '@/components/ui/input/Input.vue';

type QuickBooksConnection = {
    connected: boolean;
    companyName: string | null;
    realmId: string;
    environment: string;
};

type ImportSummary = {
    id: string;
    filename: string;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    totalRows: number;
    processedRows: number;
    successCount: number;
    failedCount: number;
    percentage: number;
    attemptCount: number;
    errorSummary: string | null;
    createdAt: string;
    completedAt: string | null;
    qboSyncStatus?: 'not_synced' | 'syncing' | 'synced' | 'partially_synced' | 'failed';
    qboSyncedCount?: number;
    qboFailedCount?: number;
    qboTotalInvoices?: number;
    qboCurrentMessage?: string | null;
    qboLastSyncedAt?: string | null;
    qboSyncError?: string | null;
    parseDuration?: string | null;
    qboSyncDuration?: string | null;
    totalDuration?: string | null;
};

type ImportError = { rowNumber: number; errors: Record<string, string>; payload: Record<string, string | null> };
type InvoiceRecord = {
    rowNumber: number;
    docNumber: string;
    customer: string;
    txnDate: string;
    lineItem: string;
    lineAmount: string | null;
    qboInvoiceId?: string | null;
    qboSyncedAt?: string | null;
    qboSyncError?: string | null;
};
type ImportProgressEvent = Omit<ImportSummary, 'id' | 'filename' | 'attemptCount' | 'createdAt' | 'completedAt'> & { importId: string };

const props = defineProps<{
    imports: ImportSummary[];
    canManageImports: boolean;
    quickbooksConnection?: QuickBooksConnection | null;
}>();

const page = usePage<{ currentTeam: { slug: string } | null }>();
const imports = ref<ImportSummary[]>([...props.imports]);
const selectedId = ref<string | null>(imports.value[0]?.id ?? null);
const selected = computed(() => imports.value.find((item) => item.id === selectedId.value) ?? null);
const errors = ref<ImportError[]>([]);
const errorsMeta = ref<{ current_page: number; last_page: number } | null>(null);
const records = ref<InvoiceRecord[]>([]);
const recordsMeta = ref<{ current_page: number; last_page: number } | null>(null);
const file = ref<File | null>(null);
const uploading = ref(false);
const syncingQbo = ref(false);
const connectionAvailable = ref(Boolean(echo));
const subscriptionReady = ref(false);
let poller: ReturnType<typeof setInterval> | null = null;
let subscribedId: string | null = null;
const liveDurationSeconds = ref(0);
let timerInterval: ReturnType<typeof setInterval> | null = null;

function startLiveTimer(): void {
    if (timerInterval) return;
    liveDurationSeconds.value = 0;
    timerInterval = setInterval(() => {
        liveDurationSeconds.value++;
    }, 1000);
}

function stopLiveTimer(): void {
    if (timerInterval) {
        clearInterval(timerInterval);
        timerInterval = null;
    }
}

const formattedLiveDuration = computed(() => {
    const s = liveDurationSeconds.value;
    return s >= 60 ? `${Math.floor(s / 60)}m ${String(s % 60).padStart(2, '0')}s` : `${s}s`;
});

const baseUrl = computed(() => `/${page.props.currentTeam?.slug}/imports`);
const qboUrl = computed(() => `/${page.props.currentTeam?.slug}/quickbooks`);
const isActive = computed(() => selected.value?.status === 'pending' || selected.value?.status === 'processing' || selected.value?.qboSyncStatus === 'syncing');
const canRetry = computed(() => props.canManageImports && (selected.value?.status === 'failed' || (selected.value?.status === 'completed' && selected.value.failedCount > 0)));
const canSyncQbo = computed(() => props.canManageImports && props.quickbooksConnection?.connected && selected.value?.status === 'completed' && selected.value?.qboSyncStatus !== 'syncing');
const qboPercentage = computed(() => {
    if (selected.value?.qboSyncStatus === 'synced') return 100;
    const total = Number(selected.value?.qboTotalInvoices) || Number(selected.value?.successCount) || Number(selected.value?.totalRows) || 0;
    if (total <= 0) return 0;
    const synced = Number(selected.value?.qboSyncedCount) || 0;
    return Math.min(100, Math.max(0, Math.round((synced / total) * 100)));
});

watch(
    () => props.imports,
    (nextImports) => {
        const newestImport = nextImports[0];
        const hasNewImport = newestImport !== undefined && !imports.value.some((item) => item.id === newestImport.id);
        imports.value = [...nextImports];

        if (hasNewImport || !selectedId.value || !imports.value.some((item) => item.id === selectedId.value)) {
            selectedId.value = newestImport?.id ?? null;
        }
    },
);

function formatStatus(status: string): string { return status.charAt(0).toUpperCase() + status.slice(1); }
function formatQboStatus(status?: string): string {
    if (!status || status === 'not_synced') return 'Not Synced';
    if (status === 'syncing') return 'Syncing…';
    if (status === 'synced') return 'Synced';
    if (status === 'partially_synced') return 'Partially Synced';
    if (status === 'failed') return 'Sync Failed';
    return status;
}

function mergeImport(next: Partial<ImportSummary> & { id: string }): void {
    const index = imports.value.findIndex((item) => item.id === next.id);
    if (index === -1) return;
    imports.value[index] = { ...imports.value[index], ...next } as ImportSummary;
    imports.value = [...imports.value];
}

function applyProgress(event: ImportProgressEvent): void {
    mergeImport({ ...event, id: event.importId });
}

async function loadImport(id = selectedId.value, errorPage = 1, recordPage = 1): Promise<void> {
    if (!id) return;
    const response = await fetch(`${baseUrl.value}/${id}?errors_page=${errorPage}&records_page=${recordPage}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    if (!response.ok) return;
    const data = await response.json();
    mergeImport(data.import);
    errors.value = data.errors.data;
    errorsMeta.value = data.errors;
    records.value = data.records.data;
    recordsMeta.value = data.records;
}

function subscribe(): void {
    if (!selectedId.value || !echo) return;
    if (subscribedId) echo.leave(`import.${subscribedId}`);
    subscribedId = selectedId.value;
    subscriptionReady.value = false;
    echo.private(`import.${selectedId.value}`)
        .subscribed(() => {
            subscriptionReady.value = true;
            connectionAvailable.value = true;
            void loadImport();
        })
        .listen('.import.started', (event: ImportProgressEvent) => applyProgress(event))
        .listen('.import.progress', (event: ImportProgressEvent) => applyProgress(event))
        .listen('.import.completed', (event: ImportProgressEvent) => { applyProgress(event); void loadImport(event.importId); })
        .listen('.import.failed', (event: ImportProgressEvent) => { applyProgress(event); void loadImport(event.importId); })
        .listen('.import.qbo_sync_started', (event: ImportProgressEvent) => { applyProgress(event); void loadImport(event.importId); })
        .listen('.import.qbo_sync_progress', (event: ImportProgressEvent) => { applyProgress(event); void loadImport(event.importId); })
        .listen('.import.qbo_sync_completed', (event: ImportProgressEvent) => { applyProgress(event); void loadImport(event.importId); })
        .listen('.import.qbo_sync_failed', (event: ImportProgressEvent) => { applyProgress(event); void loadImport(event.importId); })
        .error(() => { connectionAvailable.value = false; startPolling(); });
}

function startPolling(): void {
    if (poller || !isActive.value) return;
    poller = setInterval(() => { void loadImport(); }, 1500);
}
function stopPolling(): void {
    if (poller) clearInterval(poller);
    poller = null;
}


function upload(autoSyncQbo = false): void {
    if (!file.value) return;
    uploading.value = true;
    router.post(baseUrl.value, { file: file.value, auto_sync_qbo: autoSyncQbo }, { forceFormData: true, preserveScroll: true, onFinish: () => { uploading.value = false; file.value = null; } });
}

function retry(): void {
    if (!selected.value) return;
    router.post(`${baseUrl.value}/${selected.value.id}/retry`, {}, { preserveScroll: true });
}

function connectQuickBooks(): void {
    window.location.href = `${qboUrl.value}/connect`;
}

function disconnectQuickBooks(): void {
    if (confirm('Are you sure you want to disconnect QuickBooks Online?')) {
        router.delete(`${qboUrl.value}/disconnect`, { preserveScroll: true });
    }
}

function syncToQuickBooks(): void {
    if (!selected.value) return;
    syncingQbo.value = true;
    router.post(`${baseUrl.value}/${selected.value.id}/sync-qbo`, {}, {
        preserveScroll: true,
        onFinish: () => { syncingQbo.value = false; },
    });
}

watch(selectedId, (id) => {
    stopPolling();
    subscriptionReady.value = false;
    errors.value = [];
    records.value = [];
    if (id) {
        void loadImport(id);
        subscribe();
        startPolling();
    }
});
watch(isActive, (active) => {
    if (active) {
        startPolling();
        startLiveTimer();
    } else {
        stopPolling();
        stopLiveTimer();
    }
}, { immediate: true });

onMounted(() => {
    if (echo) {
        const connection = (echo.connector as { pusher?: { connection?: { bind: (name: string, callback: () => void) => void } } }).pusher?.connection;
        connection?.bind('connected', () => { connectionAvailable.value = true; void loadImport(); });
        connection?.bind('unavailable', () => { connectionAvailable.value = false; startPolling(); });
    }
    if (selectedId.value) {
        void loadImport();
        subscribe();
        if (isActive.value) {
            startPolling();
            startLiveTimer();
        }
    }
});
onBeforeUnmount(() => {
    stopPolling();
    stopLiveTimer();
    if (subscribedId && echo) echo.leave(`import.${subscribedId}`);
});

</script>

<template>
    <Head title="Invoice imports" />
    <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 md:p-8">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <FileSpreadsheet class="size-7" />
                <div>
                    <h1 class="text-2xl font-semibold">Invoice imports</h1>
                    <p class="text-muted-foreground text-sm">Upload QuickBooks Excel templates and synchronize invoices directly to QuickBooks Online.</p>
                </div>
            </div>

            <!-- QuickBooks Online Connection Status Badge & Button -->
            <div class="flex items-center gap-2">
                <template v-if="quickbooksConnection?.connected">
                    <div class="flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-1.5 text-sm text-emerald-700 dark:text-emerald-300">
                        <CheckCircle2 class="size-4 text-emerald-600 dark:text-emerald-400" />
                        <span>QuickBooks Connected <strong v-if="quickbooksConnection.companyName">({{ quickbooksConnection.companyName }})</strong></span>
                    </div>
                    <Button v-if="canManageImports" variant="ghost" size="sm" class="text-xs text-muted-foreground hover:text-destructive" title="Disconnect QuickBooks" @click="disconnectQuickBooks">
                        <Unlink class="mr-1 size-3.5" /> Disconnect
                    </Button>
                </template>
                <template v-else-if="canManageImports">
                    <Button variant="outline" size="sm" class="border-emerald-600 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950/30" @click="connectQuickBooks">
                        <CloudUpload class="mr-1.5 size-4" /> Connect QuickBooks Online
                    </Button>
                </template>
            </div>
        </div>

        <!-- Upload Card -->
        <Card v-if="canManageImports">
            <CardHeader><CardTitle>Upload an invoice workbook</CardTitle></CardHeader>
            <CardContent class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <Input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                <div class="flex items-center gap-2">
                    <Button
                        v-if="quickbooksConnection?.connected"
                        :disabled="!file || uploading"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white whitespace-nowrap"
                        @click="upload(true)"
                    >
                        <CloudUpload class="mr-1.5 size-4" />
                        {{ uploading ? 'Uploading…' : 'Start import to QBO (Queue)' }}
                    </Button>
                    <Button :disabled="!file || uploading" variant="outline" class="whitespace-nowrap" @click="upload(false)">
                        <Upload class="mr-1.5 size-4" />
                        {{ uploading ? 'Uploading…' : 'Start import' }}
                    </Button>
                </div>
            </CardContent>
        </Card>

        <!-- Main Workspace -->
        <div class="grid gap-6 lg:grid-cols-[20rem_1fr]">
            <!-- Sidebar: Recent Imports -->
            <Card>
                <CardHeader><CardTitle>Recent imports</CardTitle></CardHeader>
                <CardContent class="space-y-2">
                    <button
                        v-for="item in imports"
                        :key="item.id"
                        class="w-full rounded-md border p-3 text-left text-sm transition-colors"
                        :class="selectedId === item.id ? 'border-primary bg-muted' : 'hover:bg-muted/50'"
                        @click="selectedId = item.id"
                    >
                        <div class="truncate font-medium">{{ item.filename }}</div>
                        <div class="text-muted-foreground mt-1 flex items-center justify-between text-xs">
                            <span>{{ formatStatus(item.status) }} · {{ item.percentage }}%</span>
                            <span v-if="item.qboSyncStatus && item.qboSyncStatus !== 'not_synced'" class="font-medium text-emerald-600 dark:text-emerald-400">
                                QBO: {{ formatQboStatus(item.qboSyncStatus) }}
                            </span>
                        </div>
                    </button>
                    <p v-if="imports.length === 0" class="text-muted-foreground text-sm">No imports yet.</p>
                </CardContent>
            </Card>

            <!-- Detail Pane -->
            <Card v-if="selected">
                <CardHeader class="flex-row items-center justify-between gap-4">
                    <div>
                        <CardTitle>{{ selected.filename }}</CardTitle>
                        <p class="text-muted-foreground mt-1 text-sm">
                            {{ formatStatus(selected.status) }} · {{ selected.processedRows }} / {{ selected.totalRows }} rows
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <!-- Start Import to QBO Button -->
                        <Button
                            v-if="quickbooksConnection?.connected && canManageImports"
                            size="sm"
                            class="bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm"
                            :disabled="selected.status !== 'completed' || syncingQbo || selected.qboSyncStatus === 'syncing'"
                            @click="syncToQuickBooks"
                        >
                            <Send class="mr-1.5 size-4" />
                            <span v-if="selected.status === 'processing'">Processing ({{ selected.percentage }}%)…</span>
                            <span v-else-if="syncingQbo || selected.qboSyncStatus === 'syncing'">Importing to QBO via Queue…</span>
                            <span v-else-if="selected.qboSyncStatus === 'synced'">Re-import to QBO</span>
                            <span v-else>Start import to QBO</span>
                        </Button>

                        <Button v-if="canRetry" variant="outline" size="sm" @click="retry">
                            <RefreshCw class="mr-1.5 size-4" /> Retry
                        </Button>
                    </div>
                </CardHeader>

                <CardContent class="space-y-6">
                    <!-- QuickBooks Online Action Banner -->
                    <div class="rounded-lg border border-emerald-500/30 bg-emerald-50/60 p-4 dark:bg-emerald-950/20">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2 font-medium text-emerald-950 dark:text-emerald-100">
                                    <CloudUpload class="size-4 text-emerald-600 dark:text-emerald-400" />
                                    <span>QuickBooks Online Synchronization</span>
                                    <span v-if="quickbooksConnection?.companyName" class="text-xs font-normal text-muted-foreground">
                                        (Company: <strong>{{ quickbooksConnection.companyName }}</strong>)
                                    </span>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    Push all {{ selected.successCount }} imported invoice records directly to QuickBooks Online via SQS Queue.
                                </p>
                            </div>

                            <div class="flex items-center gap-2">
                                <template v-if="!quickbooksConnection?.connected">
                                    <Button size="sm" variant="outline" class="border-emerald-600 text-emerald-700 hover:bg-emerald-100" @click="connectQuickBooks">
                                        Connect QuickBooks
                                    </Button>
                                </template>
                                <template v-else-if="selected.status === 'completed'">
                                    <Button
                                        size="sm"
                                        class="bg-emerald-600 text-white hover:bg-emerald-700 shadow-sm"
                                        :disabled="syncingQbo || selected.qboSyncStatus === 'syncing'"
                                        @click="syncToQuickBooks"
                                    >
                                        <Send class="mr-1.5 size-4" />
                                        {{ syncingQbo || selected.qboSyncStatus === 'syncing' ? 'Queueing import to QBO…' : (selected.qboSyncStatus === 'synced' ? 'Re-import to QBO (Queue)' : `Start import to QBO (${selected.successCount} invoices)`) }}
                                    </Button>
                                </template>
                                <template v-else>
                                    <span class="rounded bg-muted px-2.5 py-1 text-xs text-muted-foreground">
                                        Excel processing ({{ selected.percentage }}%)…
                                    </span>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Progress Bar & Stats -->
                    <div>
                        <div class="mb-1 flex items-center justify-between text-xs text-muted-foreground">
                            <span>Excel File Parsing</span>
                            <span>{{ selected.percentage }}%</span>
                        </div>
                        <div class="bg-muted h-2.5 overflow-hidden rounded-full">
                            <div class="bg-primary h-full transition-all" :style="{ width: `${selected.percentage}%` }" />
                        </div>
                        <div class="mt-3 grid grid-cols-2 sm:grid-cols-5 gap-3 text-sm">
                            <div><span class="text-muted-foreground block">Parsed Rows</span>{{ selected.successCount }} / {{ selected.totalRows }}</div>
                            <div><span class="text-muted-foreground block">Parse Errors</span>{{ selected.failedCount }}</div>
                            <div>
                                <span class="text-muted-foreground block">QBO Synced</span>
                                <span :class="selected.qboSyncStatus === 'synced' ? 'text-emerald-600 dark:text-emerald-400 font-medium' : (selected.qboSyncStatus === 'failed' ? 'text-destructive font-medium' : 'text-emerald-600 dark:text-emerald-400')">
                                    {{ selected.qboSyncedCount ?? 0 }} / {{ selected.qboTotalInvoices || selected.successCount || '—' }}
                                </span>
                            </div>
                            <div>
                                <span class="text-muted-foreground block">Total Duration</span>
                                <span class="font-medium text-foreground">
                                    <template v-if="isActive">
                                        <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
                                            <Loader2 class="size-3 animate-spin inline" /> {{ formattedLiveDuration }}
                                        </span>
                                    </template>
                                    <template v-else>
                                        {{ selected.totalDuration || selected.qboSyncDuration || selected.parseDuration || '—' }}
                                    </template>
                                </span>
                            </div>
                            <div>
                                <span class="text-muted-foreground block">Live Status</span>
                                {{ subscriptionReady ? 'Websocket Live' : (connectionAvailable ? 'Connecting…' : 'Active Polling (1.5s)') }}
                            </div>
                        </div>
                    </div>

                    <!-- QuickBooks Online Live Progress & Status Ticker Card -->
                    <div v-if="selected.qboSyncStatus === 'syncing' || (selected.qboSyncedCount && selected.qboSyncedCount > 0) || selected.qboCurrentMessage" class="rounded-lg border border-emerald-500/40 bg-emerald-50/70 p-4 dark:bg-emerald-950/30 space-y-3">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-sm">
                            <div class="flex items-center gap-2 font-medium text-emerald-900 dark:text-emerald-100">
                                <span v-if="selected.qboSyncStatus === 'syncing'" class="relative flex h-2.5 w-2.5">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                                </span>
                                <CloudUpload class="size-4 text-emerald-600 dark:text-emerald-400" />
                                <span>QuickBooks Online Push: <strong>{{ selected.qboSyncedCount ?? 0 }} / {{ selected.qboTotalInvoices || selected.successCount || selected.totalRows || 0 }}</strong> Invoices Synced</span>
                            </div>

                            <div class="flex items-center gap-3">
                                <span class="flex items-center gap-1 text-xs text-emerald-800 dark:text-emerald-300">
                                    <Clock class="size-3.5" />
                                    <span v-if="isActive">Time: <strong>{{ formattedLiveDuration }}</strong></span>
                                    <span v-else-if="selected.totalDuration || selected.qboSyncDuration">Time: <strong>{{ selected.totalDuration || selected.qboSyncDuration }}</strong></span>
                                    <span v-if="!isActive && selected.parseDuration && selected.qboSyncDuration" class="text-muted-foreground font-normal">
                                        (Parse: {{ selected.parseDuration }}, QBO: {{ selected.qboSyncDuration }})
                                    </span>
                                </span>
                                <span class="font-semibold text-emerald-700 dark:text-emerald-300 text-sm">{{ qboPercentage }}%</span>
                            </div>
                        </div>


                        <!-- Green QBO Progress Bar -->
                        <div class="bg-emerald-200/60 dark:bg-emerald-900/50 h-3 overflow-hidden rounded-full">
                            <div class="bg-emerald-600 h-full transition-all duration-300 ease-out" :style="{ width: `${qboPercentage}%` }" />
                        </div>

                        <!-- Live Status Message Ticker -->
                        <div v-if="selected.qboCurrentMessage" class="flex items-center gap-2 text-xs text-emerald-950 dark:text-emerald-100 bg-white/80 dark:bg-black/40 p-2.5 rounded border border-emerald-500/20 shadow-xs">
                            <Loader2 v-if="selected.qboSyncStatus === 'syncing'" class="size-4 animate-spin text-emerald-600 shrink-0" />
                            <CheckCircle2 v-else-if="selected.qboSyncStatus === 'synced'" class="size-4 text-emerald-600 shrink-0" />
                            <AlertCircle v-else class="size-4 text-amber-500 shrink-0" />
                            <span class="font-mono text-xs leading-relaxed">{{ selected.qboCurrentMessage }}</span>
                        </div>
                    </div>

                    <p v-if="selected.errorSummary" class="rounded-md border border-destructive/30 p-3 text-sm text-destructive">
                        {{ selected.errorSummary }}
                    </p>

                    <p v-if="selected.qboSyncError" class="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
                        <strong>QuickBooks Sync Notice:</strong> {{ selected.qboSyncError }}
                    </p>

                    <!-- Imported Records Table -->
                    <div v-if="records.length">
                        <div class="mb-3 flex items-center justify-between">
                            <h2 class="font-medium">Imported records</h2>
                            <span v-if="recordsMeta" class="text-muted-foreground text-xs">
                                Showing page {{ recordsMeta.current_page }} of {{ recordsMeta.last_page }}
                            </span>
                        </div>
                        <div class="overflow-x-auto rounded-md border">
                            <table class="w-full min-w-[52rem] text-left text-sm">
                                <thead class="bg-muted text-muted-foreground">
                                    <tr>
                                        <th class="p-3">Row</th>
                                        <th class="p-3">Document</th>
                                        <th class="p-3">Customer</th>
                                        <th class="p-3">Date</th>
                                        <th class="p-3">Line item</th>
                                        <th class="p-3 text-right">Amount</th>
                                        <th class="p-3 text-center">QBO Sync</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="record in records" :key="record.rowNumber" class="border-t">
                                        <td class="p-3">{{ record.rowNumber }}</td>
                                        <td class="p-3 font-medium">{{ record.docNumber }}</td>
                                        <td class="p-3">{{ record.customer }}</td>
                                        <td class="p-3">{{ record.txnDate }}</td>
                                        <td class="p-3">{{ record.lineItem }}</td>
                                        <td class="p-3 text-right font-mono">{{ record.lineAmount ?? '—' }}</td>
                                        <td class="p-3 text-center">
                                            <span v-if="record.qboInvoiceId" class="inline-flex items-center gap-1 rounded bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                                                <CheckCircle2 class="size-3" /> #{{ record.qboInvoiceId }}
                                            </span>
                                            <span v-else-if="record.qboSyncError" class="text-xs text-destructive" :title="record.qboSyncError">
                                                Failed
                                            </span>
                                            <span v-else class="text-xs text-muted-foreground">—</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3 flex gap-2" v-if="recordsMeta && recordsMeta.last_page > 1">
                            <Button variant="outline" size="sm" :disabled="recordsMeta.current_page <= 1" @click="loadImport(selected.id, errorsMeta?.current_page ?? 1, recordsMeta!.current_page - 1)">Previous</Button>
                            <Button variant="outline" size="sm" :disabled="recordsMeta.current_page >= recordsMeta.last_page" @click="loadImport(selected.id, errorsMeta?.current_page ?? 1, recordsMeta!.current_page + 1)">Next</Button>
                        </div>
                    </div>

                    <!-- Row Errors -->
                    <div v-if="errors.length">
                        <h2 class="mb-3 font-medium">Row errors</h2>
                        <div v-for="error in errors" :key="error.rowNumber" class="mb-2 rounded-md border p-3 text-sm">
                            <strong>Row {{ error.rowNumber }}</strong>
                            <ul class="text-destructive mt-1 list-disc pl-5">
                                <li v-for="(message, field) in error.errors" :key="field">{{ field }}: {{ message }}</li>
                            </ul>
                        </div>
                        <div class="flex gap-2" v-if="errorsMeta && errorsMeta.last_page > 1">
                            <Button variant="outline" size="sm" :disabled="errorsMeta.current_page <= 1" @click="loadImport(selected.id, errorsMeta!.current_page - 1)">Previous</Button>
                            <Button variant="outline" size="sm" :disabled="errorsMeta.current_page >= errorsMeta.last_page" @click="loadImport(selected.id, errorsMeta!.current_page + 1)">Next</Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
