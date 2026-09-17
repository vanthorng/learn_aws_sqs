<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Download, FileDown, Loader2, RefreshCw, RotateCcw, Search, ShieldAlert, Trash2 } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Button from '@/components/ui/button/Button.vue';
import Card from '@/components/ui/card/Card.vue';
import CardContent from '@/components/ui/card/CardContent.vue';
import CardHeader from '@/components/ui/card/CardHeader.vue';
import CardTitle from '@/components/ui/card/CardTitle.vue';
import Input from '@/components/ui/input/Input.vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

type Operation = { id: string; type: 'export' | 'void' | 'delete'; status: string; totalCount: number; processedCount: number; successCount: number; failedCount: number; percentage: number; errorSummary: string | null; createdAt: string; completedAt: string | null; canDownload: boolean };
type QboInvoice = { qboInvoiceId: string; docNumber: string; customer: string; txnDate: string | null; dueDate: string | null; total: string | number; balance: string | number; status: string; canVoid: boolean; canDelete: boolean };
type BrowseMeta = { current_page: number; last_page: number; total: number; per_page: number };

const props = defineProps<{ connected: boolean; operations: Operation[] }>();
const page = usePage<{ currentTeam: { slug: string } | null }>();
const fromDate = ref(new Date(Date.now() - 29 * 86400000).toISOString().slice(0, 10));
const toDate = ref(new Date().toISOString().slice(0, 10));
const statuses = ref<string[]>(['open', 'paid', 'voided']);
const customer = ref('');
const docNumber = ref('');
const invoices = ref<QboInvoice[]>([]);
const browseMeta = ref<BrowseMeta>({ current_page: 1, last_page: 1, total: 0, per_page: 100 });
const loadingInvoices = ref(false);
const browseError = ref('');
const exporting = ref(false);
const selectedIds = ref<string[]>([]);
const voidDialogOpen = ref(false);
const deleteDialogOpen = ref(false);
const voidConfirmation = ref('');
const deleteConfirmation = ref('');
const voiding = ref(false);
const deleting = ref(false);
let poller: ReturnType<typeof setInterval> | null = null;
const baseUrl = computed(() => `/${page.props.currentTeam?.slug}/quickbooks/data`);
const hasActiveOperations = computed(() => props.operations.some((operation) => operation.status === 'queued' || operation.status === 'processing'));

function toggleStatus(status: string): void {
    statuses.value = statuses.value.includes(status) ? statuses.value.filter((item) => item !== status) : [...statuses.value, status];
}

async function searchInvoices(pageNumber = 1): Promise<void> {
    if (!props.connected || !statuses.value.length) return;
    loadingInvoices.value = true;
    browseError.value = '';
    const query = new URLSearchParams({ from_date: fromDate.value, to_date: toDate.value, page: String(pageNumber) });
    statuses.value.forEach((status) => query.append('statuses[]', status));
    if (customer.value.trim()) query.set('customer', customer.value.trim());
    if (docNumber.value.trim()) query.set('doc_number', docNumber.value.trim());

    try {
        const response = await fetch(`${baseUrl.value}/invoices?${query.toString()}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('QuickBooks invoices could not be loaded. Refresh and try again.');
        const result = await response.json() as { data: QboInvoice[]; meta: BrowseMeta };
        invoices.value = result.data;
        browseMeta.value = result.meta;
        const visibleIds = new Set(result.data.filter((invoice) => invoice.canDelete).map((invoice) => invoice.qboInvoiceId));
        selectedIds.value = selectedIds.value.filter((id) => visibleIds.has(id));
    } catch (error) {
        browseError.value = error instanceof Error ? error.message : 'QuickBooks invoices could not be loaded.';
    } finally {
        loadingInvoices.value = false;
    }
}

function exportInvoices(): void {
    exporting.value = true;
    router.post(`${baseUrl.value}/exports`, { from_date: fromDate.value, to_date: toDate.value, statuses: statuses.value, customer: customer.value.trim() || null, doc_number: docNumber.value.trim() || null }, { preserveScroll: true, onFinish: () => { exporting.value = false; } });
}

function toggleInvoice(id: string): void {
    selectedIds.value = selectedIds.value.includes(id) ? selectedIds.value.filter((item) => item !== id) : [...selectedIds.value, id];
}

function queueVoid(): void {
    if (voidConfirmation.value !== 'VOID') return;
    voiding.value = true;
    router.post(`${baseUrl.value}/voids`, { confirmation: voidConfirmation.value, qbo_invoice_ids: selectedIds.value }, {
        preserveScroll: true,
        onSuccess: () => { selectedIds.value = []; voidConfirmation.value = ''; voidDialogOpen.value = false; searchInvoices(browseMeta.value.current_page); },
        onFinish: () => { voiding.value = false; },
    });
}

function queueDelete(): void {
    if (deleteConfirmation.value !== 'DELETE') return;
    deleting.value = true;
    router.post(`${baseUrl.value}/deletions`, { confirmation: deleteConfirmation.value, qbo_invoice_ids: selectedIds.value }, {
        preserveScroll: true,
        onSuccess: () => { selectedIds.value = []; deleteConfirmation.value = ''; deleteDialogOpen.value = false; searchInvoices(browseMeta.value.current_page); },
        onFinish: () => { deleting.value = false; },
    });
}

function downloadUrl(operation: Operation): string { return `${baseUrl.value}/exports/${operation.id}/download`; }
function formatDate(value: string): string { return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(value)); }
function formatMoney(value: string | number): string { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(Number(value)); }
function statusClass(status: string): string { return status.includes('failed') || status.includes('errors') ? 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' : status === 'completed' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300'; }

onMounted(() => {
    if (props.connected) searchInvoices();
    poller = setInterval(() => { if (hasActiveOperations.value) router.reload({ only: ['operations'] }); }, 5000);
});
onBeforeUnmount(() => { if (poller) clearInterval(poller); });
</script>

<template>
    <Head title="QuickBooks data" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-8">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div><p class="text-sm font-medium text-emerald-600">QuickBooks Online</p><h1 class="mt-1 text-2xl font-semibold">Export and manage QBO invoices</h1><p class="mt-1 text-sm text-muted-foreground">Browse live invoice data from the connected QuickBooks company, export it, void it, or permanently delete it.</p></div>
            <Button variant="outline" as-child><Link :href="`/${$page.props.currentTeam?.slug}/imports`">Back to imports</Link></Button>
        </div>

        <Card v-if="!connected"><CardContent class="flex items-center gap-3 p-6 text-sm"><ShieldAlert class="size-5 text-amber-600" /> Connect QuickBooks from the Imports page before browsing, exporting, voiding, or deleting invoices.</CardContent></Card>

        <template v-else>
            <Card>
                <CardHeader><CardTitle>Search and export QBO invoices</CardTitle></CardHeader>
                <CardContent class="grid gap-4 lg:grid-cols-6 lg:items-end">
                    <label class="grid gap-2 text-sm font-medium">From<Input v-model="fromDate" type="date" /></label>
                    <label class="grid gap-2 text-sm font-medium">To<Input v-model="toDate" type="date" /></label>
                    <label class="grid gap-2 text-sm font-medium">Customer<Input v-model="customer" placeholder="Any customer" /></label>
                    <label class="grid gap-2 text-sm font-medium">Invoice number<Input v-model="docNumber" placeholder="Any number" /></label>
                    <div class="grid gap-2"><span class="text-sm font-medium">Status</span><div class="flex flex-wrap gap-2 text-sm"><label v-for="status in ['open', 'paid', 'voided']" :key="status" class="flex items-center gap-1 capitalize"><input type="checkbox" :checked="statuses.includes(status)" @change="toggleStatus(status)" /> {{ status }}</label></div></div>
                    <div class="flex flex-wrap gap-2"><Button :disabled="loadingInvoices || !statuses.length" @click="searchInvoices()"><Search class="size-4" /> Search</Button><Button variant="outline" size="icon" :disabled="loadingInvoices" title="Refresh live QBO results" @click="searchInvoices(browseMeta.current_page)"><RefreshCw :class="['size-4', { 'animate-spin': loadingInvoices }]" /></Button><Button variant="secondary" :disabled="exporting || !statuses.length" @click="exportInvoices"><FileDown class="size-4" /> {{ exporting ? 'Queueing…' : 'Export XLSX' }}</Button></div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="flex-row items-center justify-between space-y-0 gap-4"><div><CardTitle>Live QBO invoice browser</CardTitle><p class="mt-1 text-sm font-normal text-muted-foreground">{{ browseMeta.total }} matching invoices · 100 records per page</p></div><div class="flex flex-wrap gap-2"><Button variant="outline" size="sm" :disabled="!selectedIds.length" @click="voidDialogOpen = true"><RotateCcw class="size-4" /> Void {{ selectedIds.length }} selected</Button><Button variant="destructive" size="sm" :disabled="!selectedIds.length" @click="deleteDialogOpen = true"><Trash2 class="size-4" /> Delete {{ selectedIds.length }} selected</Button></div></CardHeader>
                <CardContent class="p-0">
                    <p v-if="browseError" class="p-4 text-sm text-destructive">{{ browseError }}</p>
                    <div v-else-if="loadingInvoices" class="flex items-center justify-center gap-2 p-10 text-sm text-muted-foreground"><Loader2 class="size-4 animate-spin" /> Loading live QuickBooks invoices…</div>
                    <div v-else-if="invoices.length" class="overflow-auto"><table class="w-full text-left text-sm"><thead class="bg-muted text-xs text-muted-foreground"><tr><th class="w-10 p-3"></th><th class="p-3">Invoice</th><th class="p-3">Customer</th><th class="p-3">Date</th><th class="p-3">Status</th><th class="p-3 text-right">Total</th><th class="p-3 text-right">Balance</th></tr></thead><tbody class="divide-y"><tr v-for="invoice in invoices" :key="invoice.qboInvoiceId"><td class="p-3"><input v-if="invoice.canDelete" type="checkbox" :checked="selectedIds.includes(invoice.qboInvoiceId)" @change="toggleInvoice(invoice.qboInvoiceId)" /><span v-else class="text-xs text-muted-foreground">—</span></td><td class="p-3 font-medium">{{ invoice.docNumber || invoice.qboInvoiceId }}</td><td class="p-3">{{ invoice.customer || '—' }}</td><td class="p-3 text-muted-foreground">{{ invoice.txnDate || '—' }}</td><td class="p-3"><span class="rounded-full bg-muted px-2 py-0.5 text-xs capitalize">{{ invoice.status }}</span></td><td class="p-3 text-right">{{ formatMoney(invoice.total) }}</td><td class="p-3 text-right">{{ formatMoney(invoice.balance) }}</td></tr></tbody></table></div>
                    <p v-else class="p-8 text-center text-sm text-muted-foreground">No QBO invoices match these filters.</p>
                    <div v-if="browseMeta.last_page > 1" class="flex items-center justify-end gap-3 border-t p-3 text-sm"><span class="text-muted-foreground">Page {{ browseMeta.current_page }} of {{ browseMeta.last_page }}</span><Button variant="outline" size="sm" :disabled="loadingInvoices || browseMeta.current_page <= 1" @click="searchInvoices(browseMeta.current_page - 1)">Previous</Button><Button variant="outline" size="sm" :disabled="loadingInvoices || browseMeta.current_page >= browseMeta.last_page" @click="searchInvoices(browseMeta.current_page + 1)">Next</Button></div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle>Operations</CardTitle></CardHeader>
                <CardContent class="p-0"><div v-if="operations.length" class="divide-y"><div v-for="operation in operations" :key="operation.id" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"><div><p class="font-medium capitalize">{{ operation.type }} · <span :class="statusClass(operation.status)" class="rounded-full px-2 py-0.5 text-xs">{{ operation.status.replaceAll('_', ' ') }}</span></p><p class="mt-1 text-xs text-muted-foreground">{{ formatDate(operation.createdAt) }} · {{ operation.processedCount }}/{{ operation.totalCount }} processed · {{ operation.successCount }} succeeded · {{ operation.failedCount }} failed</p><p v-if="operation.errorSummary" class="mt-1 text-xs text-destructive">{{ operation.errorSummary }}</p></div><div class="flex items-center gap-3"><div v-if="operation.status === 'queued' || operation.status === 'processing'" class="flex items-center gap-1.5 text-sm text-muted-foreground"><Loader2 class="size-4 animate-spin" /> {{ operation.percentage }}%</div><a v-if="operation.canDownload" :href="downloadUrl(operation)" class="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"><Download class="size-4" /> Download</a></div></div></div><p v-else class="p-8 text-center text-sm text-muted-foreground">No QuickBooks operations yet.</p></CardContent>
            </Card>
        </template>
    </div>

    <Dialog :open="voidDialogOpen" @update:open="voidDialogOpen = $event"><DialogContent><DialogHeader><DialogTitle>Void {{ selectedIds.length }} QuickBooks invoices?</DialogTitle><DialogDescription>This is irreversible. The invoices remain in QuickBooks’ audit trail with a zero financial impact.</DialogDescription></DialogHeader><label class="grid gap-2 text-sm font-medium">Type <code>VOID</code> to continue<Input v-model="voidConfirmation" autocomplete="off" /></label><DialogFooter><Button variant="outline" @click="voidDialogOpen = false">Cancel</Button><Button variant="destructive" :disabled="voidConfirmation !== 'VOID' || voiding" @click="queueVoid">{{ voiding ? 'Queueing…' : 'Void invoices' }}</Button></DialogFooter></DialogContent></Dialog>
    <Dialog :open="deleteDialogOpen" @update:open="deleteDialogOpen = $event"><DialogContent><DialogHeader><DialogTitle>Permanently delete {{ selectedIds.length }} QBO invoices?</DialogTitle><DialogDescription>Deleted invoices are removed from QuickBooks reports and cannot be restored by this app. Their audit-log entries remain in QuickBooks.</DialogDescription></DialogHeader><label class="grid gap-2 text-sm font-medium">Type <code>DELETE</code> to continue<Input v-model="deleteConfirmation" autocomplete="off" /></label><DialogFooter><Button variant="outline" @click="deleteDialogOpen = false">Cancel</Button><Button variant="destructive" :disabled="deleteConfirmation !== 'DELETE' || deleting" @click="queueDelete"><Trash2 class="size-4" /> {{ deleting ? 'Queueing…' : 'Delete permanently' }}</Button></DialogFooter></DialogContent></Dialog>
</template>
