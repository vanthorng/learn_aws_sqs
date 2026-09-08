<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { FileSpreadsheet, RefreshCw, Upload } from '@lucide/vue';
import { echo } from '@/echo';
import Button from '@/components/ui/button/Button.vue';
import Card from '@/components/ui/card/Card.vue';
import CardContent from '@/components/ui/card/CardContent.vue';
import CardHeader from '@/components/ui/card/CardHeader.vue';
import CardTitle from '@/components/ui/card/CardTitle.vue';
import Input from '@/components/ui/input/Input.vue';

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
};

type ImportError = { rowNumber: number; errors: Record<string, string>; payload: Record<string, string | null> };
type InvoiceRecord = { rowNumber: number; docNumber: string; customer: string; txnDate: string; lineItem: string; lineAmount: string | null };
type ImportProgressEvent = Omit<ImportSummary, 'id' | 'filename' | 'attemptCount' | 'createdAt' | 'completedAt'> & { importId: string };

const props = defineProps<{ imports: ImportSummary[]; canManageImports: boolean }>();
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
const connectionAvailable = ref(Boolean(echo));
const subscriptionReady = ref(false);
let poller: ReturnType<typeof setInterval> | null = null;
let subscribedId: string | null = null;

const baseUrl = computed(() => `/${page.props.currentTeam?.slug}/imports`);
const isActive = computed(() => selected.value?.status === 'pending' || selected.value?.status === 'processing');
const canRetry = computed(() => props.canManageImports && (selected.value?.status === 'failed' || (selected.value?.status === 'completed' && selected.value.failedCount > 0)));

// Inertia reuses this page after the upload redirect. Keep local realtime state
// in sync with the refreshed server-side import list so a manual browser reload
// is never required before subscribing to the new import.
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
function mergeImport(next: Partial<ImportSummary> & { id: string }): void {
    const index = imports.value.findIndex((item) => item.id === next.id);
    if (index === -1) return;
    imports.value[index] = { ...imports.value[index], ...next } as ImportSummary;
}
function applyProgress(event: ImportProgressEvent): void {
    mergeImport({ ...event, id: event.importId });
}

async function loadImport(id = selectedId.value, errorPage = 1, recordPage = 1): Promise<void> {
    if (!id) return;
    const response = await fetch(`${baseUrl.value}/${id}?errors_page=${errorPage}&records_page=${recordPage}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
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
            stopPolling();
            void loadImport();
        })
        .listen('.import.started', (event: ImportProgressEvent) => applyProgress(event))
        .listen('.import.progress', (event: ImportProgressEvent) => applyProgress(event))
        .listen('.import.completed', (event: ImportProgressEvent) => { applyProgress(event); void loadImport(event.importId); })
        .listen('.import.failed', (event: ImportProgressEvent) => { applyProgress(event); void loadImport(event.importId); })
        .error(() => { connectionAvailable.value = false; startPolling(); });
}

function startPolling(): void {
    if (poller || !isActive.value) return;
    poller = setInterval(() => { void loadImport(); }, 5000);
}
function stopPolling(): void {
    if (poller) clearInterval(poller);
    poller = null;
}

function upload(): void {
    if (!file.value) return;
    uploading.value = true;
    router.post(baseUrl.value, { file: file.value }, { forceFormData: true, preserveScroll: true, onFinish: () => { uploading.value = false; file.value = null; } });
}
function retry(): void {
    if (!selected.value) return;
    router.post(`${baseUrl.value}/${selected.value.id}/retry`, {}, { preserveScroll: true });
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
watch(isActive, (active) => { if (active && !subscriptionReady.value) startPolling(); if (!active) stopPolling(); });

onMounted(() => {
    if (echo) {
        const connection = (echo.connector as { pusher?: { connection?: { bind: (name: string, callback: () => void) => void } } }).pusher?.connection;
        connection?.bind('connected', () => { connectionAvailable.value = true; void loadImport(); });
        connection?.bind('unavailable', () => { connectionAvailable.value = false; startPolling(); });
    }
    if (selectedId.value) { void loadImport(); subscribe(); startPolling(); }
});
onBeforeUnmount(() => { stopPolling(); if (subscribedId && echo) echo.leave(`import.${subscribedId}`); });
</script>

<template>
    <Head title="Invoice imports" />
    <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 md:p-8">
        <div class="flex items-center gap-3">
            <FileSpreadsheet class="size-7" />
            <div><h1 class="text-2xl font-semibold">Invoice imports</h1><p class="text-muted-foreground text-sm">Upload the approved Invoice.xlsx format and follow processing in real time.</p></div>
        </div>
        <Card v-if="canManageImports">
            <CardHeader><CardTitle>Upload an invoice workbook</CardTitle></CardHeader>
            <CardContent class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <Input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                <Button :disabled="!file || uploading" @click="upload"><Upload class="mr-2 size-4" />{{ uploading ? 'Uploading…' : 'Start import' }}</Button>
            </CardContent>
        </Card>
        <div class="grid gap-6 lg:grid-cols-[20rem_1fr]">
            <Card><CardHeader><CardTitle>Recent imports</CardTitle></CardHeader><CardContent class="space-y-2">
                <button v-for="item in imports" :key="item.id" class="w-full rounded-md border p-3 text-left text-sm" :class="selectedId === item.id ? 'border-primary bg-muted' : ''" @click="selectedId = item.id">
                    <div class="truncate font-medium">{{ item.filename }}</div><div class="text-muted-foreground mt-1">{{ formatStatus(item.status) }} · {{ item.percentage }}%</div>
                </button>
                <p v-if="imports.length === 0" class="text-muted-foreground text-sm">No imports yet.</p>
            </CardContent></Card>
            <Card v-if="selected"><CardHeader class="flex-row items-center justify-between gap-4"><div><CardTitle>{{ selected.filename }}</CardTitle><p class="text-muted-foreground mt-1 text-sm">{{ formatStatus(selected.status) }} · {{ selected.processedRows }} / {{ selected.totalRows }} rows</p></div><Button v-if="canRetry" variant="outline" @click="retry"><RefreshCw class="mr-2 size-4" />Retry</Button></CardHeader>
                <CardContent class="space-y-6"><div><div class="bg-muted h-3 overflow-hidden rounded-full"><div class="bg-primary h-full transition-all" :style="{ width: `${selected.percentage}%` }" /></div><div class="mt-3 grid grid-cols-3 gap-3 text-sm"><div><span class="text-muted-foreground block">Imported</span>{{ selected.successCount }}</div><div><span class="text-muted-foreground block">Errors</span>{{ selected.failedCount }}</div><div><span class="text-muted-foreground block">Connection</span>{{ subscriptionReady ? 'Live' : (connectionAvailable ? 'Connecting…' : 'Status fallback') }}</div></div></div>
                    <p v-if="selected.errorSummary" class="rounded-md border border-destructive/30 p-3 text-sm text-destructive">{{ selected.errorSummary }}</p>
                    <div v-if="records.length"><h2 class="mb-3 font-medium">Imported records</h2><div class="overflow-x-auto rounded-md border"><table class="w-full min-w-[48rem] text-left text-sm"><thead class="bg-muted text-muted-foreground"><tr><th class="p-3">Row</th><th class="p-3">Document</th><th class="p-3">Customer</th><th class="p-3">Date</th><th class="p-3">Line item</th><th class="p-3 text-right">Amount</th></tr></thead><tbody><tr v-for="record in records" :key="record.rowNumber" class="border-t"><td class="p-3">{{ record.rowNumber }}</td><td class="p-3">{{ record.docNumber }}</td><td class="p-3">{{ record.customer }}</td><td class="p-3">{{ record.txnDate }}</td><td class="p-3">{{ record.lineItem }}</td><td class="p-3 text-right">{{ record.lineAmount ?? '—' }}</td></tr></tbody></table></div><div class="mt-3 flex gap-2" v-if="recordsMeta && recordsMeta.last_page > 1"><Button variant="outline" size="sm" :disabled="recordsMeta.current_page <= 1" @click="loadImport(selected.id, errorsMeta?.current_page ?? 1, recordsMeta!.current_page - 1)">Previous</Button><Button variant="outline" size="sm" :disabled="recordsMeta.current_page >= recordsMeta.last_page" @click="loadImport(selected.id, errorsMeta?.current_page ?? 1, recordsMeta!.current_page + 1)">Next</Button></div></div>
                    <div v-if="errors.length"><h2 class="mb-3 font-medium">Row errors</h2><div v-for="error in errors" :key="error.rowNumber" class="mb-2 rounded-md border p-3 text-sm"><strong>Row {{ error.rowNumber }}</strong><ul class="text-destructive mt-1 list-disc pl-5"><li v-for="(message, field) in error.errors" :key="field">{{ field }}: {{ message }}</li></ul></div><div class="flex gap-2" v-if="errorsMeta && errorsMeta.last_page > 1"><Button variant="outline" size="sm" :disabled="errorsMeta.current_page <= 1" @click="loadImport(selected.id, errorsMeta!.current_page - 1)">Previous</Button><Button variant="outline" size="sm" :disabled="errorsMeta.current_page >= errorsMeta.last_page" @click="loadImport(selected.id, errorsMeta!.current_page + 1)">Next</Button></div></div>
                </CardContent></Card>
        </div>
    </div>
</template>
