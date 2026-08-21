<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\ReworkRecord;
use App\Models\PurchaseQueueItem;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use App\Models\WorkflowEvent;
use App\Models\Supplier;
use App\Services\QuantityCalculationService;
use App\Services\HierarchyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(
        protected QuantityCalculationService $quantityService = new QuantityCalculationService(),
        protected HierarchyService $hierarchyService = new HierarchyService()
    ) {}

    public function projectHierarchy(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $projectId = $request->query('project_id') ? (int) $request->query('project_id') : null;
        $filters = [
            'side' => $request->query('side'),
            'search' => $request->query('search'),
            'status_filter' => $request->query('status_filter', 'all'),
        ];

        $hierarchy = $this->hierarchyService->getDepartmentHierarchy('manager', $projectId, $filters);
        return response()->json($hierarchy);
    }

    public function summary(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $projectId = $request->query('project_id');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $side = $request->query('side');
        $supplierId = $request->query('supplier_id');

        $filters = [
            'project_id' => $projectId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'side' => $side,
            'supplier_id' => $supplierId,
        ];

        // Authoritative canonical calculations
        $canonicalSummary = $this->quantityService->calculateDashboardSummary($filters);
        $canonicalProjectsProgress = $this->quantityService->calculateProjectsProgress($filters);
        $topProjectsNearCompletion = $this->quantityService->getTopProjectsNearCompletion($filters);
        $healthDistribution = $this->quantityService->calculateProjectHealthDistribution($filters);

        // Query builders for contextual widgets
        $recQuery = ReceiptItem::query()->whereIn('status', QuantityCalculationService::VALID_RECEIPT_STATUSES);
        $qcQuery = QcInspection::query();
        $reworkQuery = ReworkRecord::query();

        if ($projectId) {
            $recQuery->whereHas('bomItem', fn($q) => $q->where('project_id', $projectId));
            $qcQuery->whereHas('bomItem', fn($q) => $q->where('project_id', $projectId));
            $reworkQuery->whereHas('bomItem', fn($q) => $q->where('project_id', $projectId));
        }

        if ($side) {
            $recQuery->where('side', $side);
            $qcQuery->where('side', $side);
            $reworkQuery->where('side', $side);
        }

        if ($supplierId) {
            $recQuery->whereHas('bomItem', fn($q) => $q->where('supplier_id', $supplierId));
            $qcQuery->whereHas('bomItem', fn($q) => $q->where('supplier_id', $supplierId));
            $reworkQuery->whereHas('bomItem', fn($q) => $q->where('supplier_id', $supplierId));
        }

        if ($dateFrom) {
            $recQuery->where('created_at', '>=', $dateFrom);
            $qcQuery->where('inspection_date', '>=', $dateFrom);
            $reworkQuery->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $recQuery->where('created_at', '<=', $dateTo);
            $qcQuery->where('inspection_date', '<=', $dateTo);
            $reworkQuery->where('created_at', '<=', $dateTo);
        }

        // Part Status Distribution
        $statusDistribution = (clone $recQuery)
            ->select('status', DB::raw('SUM(received_quantity) as total_qty'), DB::raw('COUNT(*) as total_items'))
            ->groupBy('status')
            ->get()
            ->pluck('total_qty', 'status');

        // Delayed Parts (> 3 days in current status without progress)
        $delayedParts = ReceiptItem::query()
            ->with(['bomItem.project'])
            ->whereNotIn('status', ['assembly_completed', 'qc_rejected', 'reverted', 'scrapped', 'returned_to_vendor'])
            ->where('updated_at', '<', now()->subDays(3))
            ->orderBy('updated_at', 'asc')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'standard_part_no' => $item->bomItem?->standard_part_no ?? 'Part #' . $item->id,
                    'project' => $item->bomItem?->project?->name ?? 'N/A',
                    'side' => $item->side,
                    'status' => $item->status,
                    'waiting_since' => $item->updated_at->toDateTimeString(),
                    'duration_days' => round(now()->diffInHours($item->updated_at) / 24, 1),
                ];
            });

        // Quality Trend (30 Days)
        $qualityTrend = QcInspection::query()
            ->select(
                DB::raw('DATE(inspection_date) as date'),
                DB::raw('SUM(approved_quantity) as approved'),
                DB::raw('SUM(rework_quantity) as rework'),
                DB::raw('SUM(rejected_quantity) as rejected')
            )
            ->where('inspection_date', '>=', now()->subDays(30))
            ->groupBy(DB::raw('DATE(inspection_date)'))
            ->orderBy('date', 'asc')
            ->get();

        // Recent Workflow Activity Log
        $recentEvents = WorkflowEvent::query()
            ->with(['bomItem.project', 'user'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // Supplier Quality & Delivery Metrics
        $supplierPerformance = Supplier::where('is_active', true)
            ->with(['bomItems.receiptItems', 'bomItems.qcInspections'])
            ->get()
            ->map(function ($sup) {
                $recCount = $sup->bomItems->flatMap->receiptItems->sum('received_quantity');
                $appCount = $sup->bomItems->flatMap->qcInspections->sum('approved_quantity');
                $rejCount = $sup->bomItems->flatMap->qcInspections->sum('rejected_quantity');
                $rewCount = $sup->bomItems->flatMap->qcInspections->sum('rework_quantity');
                $totalInspected = $appCount + $rejCount + $rewCount;
                $passRate = $totalInspected > 0 ? round(($appCount / $totalInspected) * 100, 1) : 100;
                return [
                    'id' => $sup->id,
                    'name' => $sup->name,
                    'code' => $sup->code,
                    'received_qty' => $recCount,
                    'approved_qty' => $appCount,
                    'rework_qty' => $rewCount,
                    'rejected_qty' => $rejCount,
                    'pass_rate' => $passRate,
                ];
            })
            ->filter(fn($s) => $s['received_qty'] > 0)
            ->values();

        // 1. Today's Departmental Throughput Metrics
        $todayStart = now()->startOfDay();
        $todayThroughput = [
            'store_received' => (int) ReceiptItem::where('created_at', '>=', $todayStart)->sum('received_quantity'),
            'qc_approved' => (int) QcInspection::where('inspection_date', '>=', $todayStart)->sum('approved_quantity'),
            'rework_completed' => (int) ReworkRecord::where('updated_at', '>=', $todayStart)->whereIn('status', ['completed', 'returned_to_qc'])->sum('quantity'),
            'paint_completed' => (int) PaintRecord::where('created_at', '>=', $todayStart)->where('status', 'completed')->sum('quantity'),
            'assembly_completed' => (int) AssemblyRecord::where('created_at', '>=', $todayStart)->where('status', 'completed')->sum('quantity'),
        ];

        // 2. Stagnant Parts Bottleneck Watchlist (parts waiting in current state)
        $stagnantParts = ReceiptItem::query()
            ->with(['bomItem.project', 'bomItem.supplier'])
            ->whereNotIn('status', ['assembly_completed', 'qc_rejected', 'reverted'])
            ->orderBy('updated_at', 'asc')
            ->limit(8)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'standard_part_no' => $item->bomItem?->standard_part_no ?? 'Part #' . $item->id,
                    'project_name' => $item->bomItem?->project?->name ?? 'N/A',
                    'project_code' => $item->bomItem?->project?->project_code ?? 'N/A',
                    'side' => $item->side,
                    'quantity' => $item->received_quantity,
                    'status' => $item->status,
                    'supplier' => $item->bomItem?->supplier?->name ?? $item->bomItem?->supplier_name_raw ?? '—',
                    'updated_at' => $item->updated_at->toDateTimeString(),
                    'hours_waiting' => round(now()->diffInHours($item->updated_at), 1),
                ];
            });

        // 3. Defect & Quality Issue Pareto
        $defectPareto = QcInspection::query()
            ->whereIn('result', ['rejected', 'rework', 'partial'])
            ->with(['bomItem.project', 'bomItem.supplier'])
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->map(function ($q) {
                return [
                    'id' => $q->id,
                    'standard_part_no' => $q->bomItem?->standard_part_no ?? '—',
                    'project' => $q->bomItem?->project?->project_code ?? ($q->bomItem?->project?->name ?? '—'),
                    'result' => $q->result,
                    'quantity' => $q->result === 'rejected' ? $q->rejected_quantity : ($q->rework_quantity ?: $q->inspected_quantity),
                    'reason' => $q->rejection_reason ?: ($q->rework_reason ?: ($q->remarks ?: 'Quality Non-conformance')),
                    'supplier' => $q->bomItem?->supplier?->name ?? $q->bomItem?->supplier_name_raw ?? '—',
                    'date' => $q->created_at->toDateTimeString(),
                ];
            });

        return response()->json([
            'summary' => $canonicalSummary,
            'status_distribution' => $statusDistribution,
            'delayed_parts' => $delayedParts,
            'quality_trend' => $qualityTrend,
            'recent_events' => $recentEvents,
            'projects_progress' => $canonicalProjectsProgress,
            'top_projects' => $topProjectsNearCompletion,
            'health_distribution' => $healthDistribution,
            'supplier_performance' => $supplierPerformance,
            'today_throughput' => $todayThroughput,
            'stagnant_parts' => $stagnantParts,
            'defect_pareto' => $defectPareto,
        ]);
    }

    /**
     * Retrieve all parts/records belonging to a specific clicked Dashboard data block.
     */
    public function blockDetails(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $block = $request->query('block', 'total_parts');
        $projectId = $request->query('project_id') ? (int) $request->query('project_id') : null;

        $activeProjectsQuery = Project::query();
        if ($projectId) {
            $activeProjectsQuery->where('id', $projectId);
        } else {
            $activeProjectsQuery->where('status', 'active');
        }
        $activeProjectIds = $activeProjectsQuery->pluck('id')->toArray();

        $title = '';
        $items = [];
        $columns = [];
        $totalQuantity = 0;

        switch ($block) {
            case 'active_projects':
                $title = 'Active Projects Overview';
                $projects = Project::where('status', 'active')->orderBy('name')->get();
                $columns = [
                    ['label' => 'Project Code', 'key' => 'code'],
                    ['label' => 'Project Name', 'key' => 'name'],
                    ['label' => 'Required (pcs)', 'key' => 'required', 'align' => 'center'],
                    ['label' => 'Received (pcs)', 'key' => 'received', 'align' => 'center'],
                    ['label' => 'Pending (pcs)', 'key' => 'pending', 'align' => 'center'],
                    ['label' => 'Progress %', 'key' => 'completion_pct', 'align' => 'center'],
                    ['label' => 'Status', 'key' => 'status', 'align' => 'center'],
                ];
                foreach ($projects as $p) {
                    $m = $this->quantityService->calculateProjectMetrics($p);
                    $totalQuantity += $m['required_qty'];
                    $items[] = [
                        'id' => $p->id,
                        'code' => $p->project_code,
                        'name' => $p->name,
                        'required' => $m['required_qty'],
                        'received' => $m['received_qty'],
                        'pending' => $m['pending_qty'],
                        'completion_pct' => $m['completion_pct'] . '%',
                        'status' => 'ACTIVE',
                    ];
                }
                break;

            case 'completed_projects':
                $title = 'Completed Projects';
                $projects = Project::where('status', 'completed')->orderBy('name')->get();
                $columns = [
                    ['label' => 'Project Code', 'key' => 'code'],
                    ['label' => 'Project Name', 'key' => 'name'],
                    ['label' => 'Total Parts', 'key' => 'total_parts', 'align' => 'center'],
                    ['label' => 'Completion', 'key' => 'completion_pct', 'align' => 'center'],
                    ['label' => 'Status', 'key' => 'status', 'align' => 'center'],
                ];
                foreach ($projects as $p) {
                    $m = $this->quantityService->calculateProjectMetrics($p);
                    $totalQuantity += $m['received_qty'];
                    $items[] = [
                        'id' => $p->id,
                        'code' => $p->project_code,
                        'name' => $p->name,
                        'total_parts' => $m['required_qty'],
                        'completion_pct' => '100%',
                        'status' => 'COMPLETED',
                    ];
                }
                break;

            case 'delayed_projects':
                $title = 'Delayed Projects (>14d Inactive & <80% Complete)';
                $delayedQuery = Project::where('status', 'active')
                    ->where('created_at', '<', now()->subDays(14))
                    ->whereDoesntHave('bomItems.receiptItems', fn($q) => $q->where('updated_at', '>=', now()->subDays(14)))
                    ->get();
                $columns = [
                    ['label' => 'Project Code', 'key' => 'code'],
                    ['label' => 'Project Name', 'key' => 'name'],
                    ['label' => 'Required', 'key' => 'required', 'align' => 'center'],
                    ['label' => 'Received', 'key' => 'received', 'align' => 'center'],
                    ['label' => 'Pending', 'key' => 'pending', 'align' => 'center'],
                    ['label' => 'Progress %', 'key' => 'completion_pct', 'align' => 'center'],
                    ['label' => 'Status', 'key' => 'status', 'align' => 'center'],
                ];
                foreach ($delayedQuery as $p) {
                    $m = $this->quantityService->calculateProjectMetrics($p);
                    $totalQuantity += $m['pending_qty'];
                    $items[] = [
                        'id' => $p->id,
                        'code' => $p->project_code,
                        'name' => $p->name,
                        'required' => $m['required_qty'],
                        'received' => $m['received_qty'],
                        'pending' => $m['pending_qty'],
                        'completion_pct' => $m['completion_pct'] . '%',
                        'status' => 'DELAYED',
                    ];
                }
                break;

            case 'total_parts':
                $title = 'Total Required BOM Parts';
                $receiptsGrouped = ReceiptItem::query()
                    ->whereIn('status', QuantityCalculationService::VALID_RECEIPT_STATUSES)
                    ->whereHas('bomItem', fn($q) => $q->whereIn('project_id', $activeProjectIds))
                    ->get()
                    ->groupBy('bom_item_id');

                $bomQuery = BomItem::query()
                    ->with(['project', 'supplier', 'requirements'])
                    ->whereIn('project_id', $activeProjectIds)
                    ->orderBy('standard_part_no');
                $columns = [
                    ['label' => 'Part Number', 'key' => 'part_number'],
                    ['label' => 'Part No', 'key' => 'part_no'],
                    ['label' => 'Item No', 'key' => 'item_no'],
                    ['label' => 'Project', 'key' => 'project'],
                    ['label' => 'JIG / Unit', 'key' => 'jig_unit'],
                    ['label' => 'Side', 'key' => 'side', 'align' => 'center'],
                    ['label' => 'Supplier', 'key' => 'supplier'],
                    ['label' => 'Required', 'key' => 'required', 'align' => 'center'],
                    ['label' => 'Received', 'key' => 'received', 'align' => 'center'],
                    ['label' => 'Pending', 'key' => 'pending', 'align' => 'center'],
                    ['label' => 'Quantity', 'key' => 'quantity', 'align' => 'center'],
                ];
                foreach ($bomQuery->get() as $b) {
                    $jig = $b->jig_no ?? '';
                    $unitNo = $b->unit_no ?? '';
                    $partNo = $b->standard_part_no ?? '';
                    $projCode = $b->project?->project_code ?? ($b->project?->name ?? '—');
                    $jigUnit = ($jig ? $jig : '') . ($unitNo ? ' / ' . $unitNo : '');
                    $supplierName = $b->supplier?->name ?? $b->supplier_name_raw ?? '—';
                    $itemReceipts = $receiptsGrouped->get($b->id, collect());

                    if ($b->requirements->isNotEmpty()) {
                        foreach ($b->requirements as $req) {
                            $side = $req->side ?: 'COMMON';
                            $reqQty = (int) $req->required_quantity;
                            $rawRec = (int) $itemReceipts->where('side', $side)->sum('received_quantity');
                            $recQty = min($rawRec, $reqQty);
                            $pendQty = max(0, $reqQty - $recQty);
                            $partNumber = trim($jig) . trim($unitNo) . trim($partNo) . trim($side);

                            $totalQuantity += $reqQty;
                            $items[] = [
                                'id' => $req->id,
                                'part_number' => $partNumber,
                                'part_no' => $partNo,
                                'item_no' => $b->item_no ?? '—',
                                'project' => $projCode,
                                'jig_unit' => $jigUnit,
                                'side' => $side,
                                'supplier' => $supplierName,
                                'required' => $reqQty,
                                'received' => $recQty,
                                'pending' => $pendQty,
                                'quantity' => $reqQty,
                            ];
                        }
                    } else {
                        $side = $b->side ?: 'COMMON';
                        $reqQty = (int) ($b->total_required ?? 0);
                        $rawRec = (int) $itemReceipts->where('side', $side)->sum('received_quantity');
                        $recQty = min($rawRec, $reqQty);
                        $pendQty = max(0, $reqQty - $recQty);
                        $partNumber = trim($jig) . trim($unitNo) . trim($partNo) . trim($side);

                        $totalQuantity += $reqQty;
                        $items[] = [
                            'id' => $b->id,
                            'part_number' => $partNumber,
                            'part_no' => $partNo,
                            'item_no' => $b->item_no ?? '—',
                            'project' => $projCode,
                            'jig_unit' => $jigUnit,
                            'side' => $side,
                            'supplier' => $supplierName,
                            'required' => $reqQty,
                            'received' => $recQty,
                            'pending' => $pendQty,
                            'quantity' => $reqQty,
                        ];
                    }
                }
                break;

            case 'total_parts_received':
                $title = 'Total Parts Received (In-Plant)';
                $recQuery = ReceiptItem::query()
                    ->with(['bomItem.project', 'bomItem.supplier', 'receipt'])
                    ->whereIn('status', QuantityCalculationService::VALID_RECEIPT_STATUSES)
                    ->whereHas('bomItem', fn($q) => $q->whereIn('project_id', $activeProjectIds))
                    ->orderByDesc('created_at');
                $columns = [
                    ['label' => 'Part Number', 'key' => 'part_number'],
                    ['label' => 'Part No', 'key' => 'part_no'],
                    ['label' => 'Item No', 'key' => 'item_no'],
                    ['label' => 'Project', 'key' => 'project'],
                    ['label' => 'JIG / Unit', 'key' => 'jig_unit'],
                    ['label' => 'Side', 'key' => 'side', 'align' => 'center'],
                    ['label' => 'Received Qty', 'key' => 'quantity', 'align' => 'center'],
                    ['label' => 'Current Status', 'key' => 'status', 'align' => 'center'],
                    ['label' => 'Supplier', 'key' => 'supplier'],
                    ['label' => 'Intake Date', 'key' => 'date', 'align' => 'center'],
                ];
                foreach ($recQuery->get() as $r) {
                    $qty = (int) ($r->received_quantity ?? 0);
                    $jig = $r->bomItem?->jig_no ?? '';
                    $unitNo = $r->bomItem?->unit_no ?? '';
                    $partNo = $r->bomItem?->standard_part_no ?? '';
                    $side = $r->side ?? 'COMMON';
                    $partNumber = trim($jig) . trim($unitNo) . trim($partNo) . trim($side);

                    $totalQuantity += $qty;
                    $items[] = [
                        'id' => $r->id,
                        'part_number' => $partNumber,
                        'part_no' => $partNo ?: '—',
                        'item_no' => $r->bomItem?->item_no ?? '—',
                        'project' => $r->bomItem?->project?->project_code ?? ($r->bomItem?->project?->name ?? '—'),
                        'jig_unit' => ($jig ? $jig : '') . ($unitNo ? ' / ' . $unitNo : ''),
                        'side' => $side,
                        'quantity' => $qty,
                        'status' => strtoupper(str_replace('_', ' ', $r->status)),
                        'supplier' => $r->bomItem?->supplier?->name ?? $r->bomItem?->supplier_name_raw ?? '—',
                        'date' => $r->created_at?->format('d-M-Y H:i') ?? '—',
                    ];
                }
                break;

            case 'parts_pending':
                $title = 'Parts Pending Intake';
                $receiptsGrouped = ReceiptItem::query()
                    ->whereIn('status', QuantityCalculationService::VALID_RECEIPT_STATUSES)
                    ->whereHas('bomItem', fn($q) => $q->whereIn('project_id', $activeProjectIds))
                    ->get()
                    ->groupBy('bom_item_id');

                $bomQuery = BomItem::query()
                    ->with(['project', 'supplier', 'requirements'])
                    ->whereIn('project_id', $activeProjectIds)
                    ->orderBy('standard_part_no');
                $columns = [
                    ['label' => 'Part Number', 'key' => 'part_number'],
                    ['label' => 'Part No', 'key' => 'part_no'],
                    ['label' => 'Item No', 'key' => 'item_no'],
                    ['label' => 'Project', 'key' => 'project'],
                    ['label' => 'JIG / Unit', 'key' => 'jig_unit'],
                    ['label' => 'Side', 'key' => 'side', 'align' => 'center'],
                    ['label' => 'Supplier', 'key' => 'supplier'],
                    ['label' => 'Required', 'key' => 'required', 'align' => 'center'],
                    ['label' => 'Received', 'key' => 'received', 'align' => 'center'],
                    ['label' => 'Pending Qty', 'key' => 'pending', 'align' => 'center'],
                    ['label' => 'Quantity', 'key' => 'quantity', 'align' => 'center'],
                ];
                foreach ($bomQuery->get() as $b) {
                    $jig = $b->jig_no ?? '';
                    $unitNo = $b->unit_no ?? '';
                    $partNo = $b->standard_part_no ?? '';
                    $projCode = $b->project?->project_code ?? ($b->project?->name ?? '—');
                    $jigUnit = ($jig ? $jig : '') . ($unitNo ? ' / ' . $unitNo : '');
                    $supplierName = $b->supplier?->name ?? $b->supplier_name_raw ?? '—';
                    $itemReceipts = $receiptsGrouped->get($b->id, collect());

                    if ($b->requirements->isNotEmpty()) {
                        foreach ($b->requirements as $req) {
                            $side = $req->side ?: 'COMMON';
                            $reqQty = (int) $req->required_quantity;
                            $rawRec = (int) $itemReceipts->where('side', $side)->sum('received_quantity');
                            $recQty = min($rawRec, $reqQty);
                            $pendQty = max(0, $reqQty - $recQty);
                            $partNumber = trim($jig) . trim($unitNo) . trim($partNo) . trim($side);

                            if ($pendQty > 0) {
                                $totalQuantity += $pendQty;
                                $items[] = [
                                    'id' => $req->id,
                                    'part_number' => $partNumber,
                                    'part_no' => $partNo,
                                    'item_no' => $b->item_no ?? '—',
                                    'project' => $projCode,
                                    'jig_unit' => $jigUnit,
                                    'side' => $side,
                                    'supplier' => $supplierName,
                                    'required' => $reqQty,
                                    'received' => $recQty,
                                    'pending' => $pendQty,
                                    'quantity' => $pendQty,
                                ];
                            }
                        }
                    } else {
                        $side = $b->side ?: 'COMMON';
                        $reqQty = (int) ($b->total_required ?? 0);
                        $rawRec = (int) $itemReceipts->where('side', $side)->sum('received_quantity');
                        $recQty = min($rawRec, $reqQty);
                        $pendQty = max(0, $reqQty - $recQty);
                        $partNumber = trim($jig) . trim($unitNo) . trim($partNo) . trim($side);

                        if ($pendQty > 0) {
                            $totalQuantity += $pendQty;
                            $items[] = [
                                'id' => $b->id,
                                'part_number' => $partNumber,
                                'part_no' => $partNo,
                                'item_no' => $b->item_no ?? '—',
                                'project' => $projCode,
                                'jig_unit' => $jigUnit,
                                'side' => $side,
                                'supplier' => $supplierName,
                                'required' => $reqQty,
                                'received' => $recQty,
                                'pending' => $pendQty,
                                'quantity' => $pendQty,
                            ];
                        }
                    }
                }
                break;

            case 'store':
                $title = 'Parts in Store Intake';
                $storeQuery = ReceiptItem::query()
                    ->with(['bomItem.project', 'bomItem.supplier'])
                    ->whereIn('status', ['received', 'returned_to_store'])
                    ->whereHas('bomItem', fn($q) => $q->whereIn('project_id', $activeProjectIds))
                    ->orderByDesc('created_at');
                $columns = [
                    ['label' => 'Part Number', 'key' => 'part_number'],
                    ['label' => 'Part No', 'key' => 'part_no'],
                    ['label' => 'Item No', 'key' => 'item_no'],
                    ['label' => 'Project', 'key' => 'project'],
                    ['label' => 'JIG / Unit', 'key' => 'jig_unit'],
                    ['label' => 'Side', 'key' => 'side', 'align' => 'center'],
                    ['label' => 'Store Qty', 'key' => 'quantity', 'align' => 'center'],
                    ['label' => 'Supplier', 'key' => 'supplier'],
                    ['label' => 'Location / Status', 'key' => 'status', 'align' => 'center'],
                    ['label' => 'Received At', 'key' => 'date', 'align' => 'center'],
                ];
                foreach ($storeQuery->get() as $s) {
                    $qty = (int) ($s->received_quantity ?? 0);
                    $jig = $s->bomItem?->jig_no ?? '';
                    $unitNo = $s->bomItem?->unit_no ?? '';
                    $partNo = $s->bomItem?->standard_part_no ?? '';
                    $side = $s->side ?? 'COMMON';
                    $partNumber = trim($jig) . trim($unitNo) . trim($partNo) . trim($side);

                    $totalQuantity += $qty;
                    $items[] = [
                        'id' => $s->id,
                        'part_number' => $partNumber,
                        'part_no' => $partNo ?: '—',
                        'item_no' => $s->bomItem?->item_no ?? '—',
                        'project' => $s->bomItem?->project?->project_code ?? ($s->bomItem?->project?->name ?? '—'),
                        'jig_unit' => ($jig ? $jig : '') . ($unitNo ? ' / ' . $unitNo : ''),
                        'side' => $side,
                        'quantity' => $qty,
                        'supplier' => $s->bomItem?->supplier?->name ?? $s->bomItem?->supplier_name_raw ?? '—',
                        'status' => 'STORE INTAKE',
                        'date' => $s->created_at?->format('d-M-Y H:i') ?? '—',
                    ];
                }
                break;

            case 'qc':
                $title = 'Parts in QC Inspection Queue';
                $qcQuery = ReceiptItem::query()
                    ->with(['bomItem.project', 'bomItem.supplier'])
                    ->whereIn('status', ['sent_to_qc', 'qc_received'])
                    ->whereHas('bomItem', fn($q) => $q->whereIn('project_id', $activeProjectIds))
                    ->orderByDesc('updated_at');
                $columns = [
                    ['label' => 'Part Number', 'key' => 'part_number'],
                    ['label' => 'Part No', 'key' => 'part_no'],
                    ['label' => 'Item No', 'key' => 'item_no'],
                    ['label' => 'Project', 'key' => 'project'],
                    ['label' => 'JIG / Unit', 'key' => 'jig_unit'],
                    ['label' => 'Side', 'key' => 'side', 'align' => 'center'],
                    ['label' => 'QC Queue Qty', 'key' => 'quantity', 'align' => 'center'],
                    ['label' => 'Status', 'key' => 'status', 'align' => 'center'],
                    ['label' => 'Supplier', 'key' => 'supplier'],
                    ['label' => 'Sent to QC', 'key' => 'date', 'align' => 'center'],
                ];
                foreach ($qcQuery->get() as $q) {
                    $qty = (int) ($q->received_quantity ?? 0);
                    $jig = $q->bomItem?->jig_no ?? '';
                    $unitNo = $q->bomItem?->unit_no ?? '';
                    $partNo = $q->bomItem?->standard_part_no ?? '';
                    $side = $q->side ?? 'COMMON';
                    $partNumber = trim($jig) . trim($unitNo) . trim($partNo) . trim($side);

                    $totalQuantity += $qty;
                    $items[] = [
                        'id' => $q->id,
                        'part_number' => $partNumber,
                        'part_no' => $partNo ?: '—',
                        'item_no' => $q->bomItem?->item_no ?? '—',
                        'project' => $q->bomItem?->project?->project_code ?? ($q->bomItem?->project?->name ?? '—'),
                        'jig_unit' => ($jig ? $jig : '') . ($unitNo ? ' / ' . $unitNo : ''),
                        'side' => $side,
                        'quantity' => $qty,
                        'status' => strtoupper(str_replace('_', ' ', $q->status)),
                        'supplier' => $q->bomItem?->supplier?->name ?? $q->bomItem?->supplier_name_raw ?? '—',
                        'date' => $q->updated_at?->format('d-M-Y H:i') ?? '—',
                    ];
                }
                break;

            case 'rework':
                $title = 'Parts in Rework Queue';
                $reworkQuery = ReworkRecord::query()
                    ->with(['bomItem.project', 'bomItem.supplier'])
                    ->whereIn('status', ['pending', 'in_progress'])
                    ->whereHas('bomItem', fn($q) => $q->whereIn('project_id', $activeProjectIds))
                    ->orderByDesc('created_at');
                $columns = [
                    ['label' => 'Part Number', 'key' => 'part_number'],
                    ['label' => 'Part No', 'key' => 'part_no'],
                    ['label' => 'Item No', 'key' => 'item_no'],
                    ['label' => 'Project', 'key' => 'project'],
                    ['label' => 'JIG / Unit', 'key' => 'jig_unit'],
                    ['label' => 'Side', 'key' => 'side', 'align' => 'center'],
                    ['label' => 'Rework Qty', 'key' => 'quantity', 'align' => 'center'],
                    ['label' => 'Rework Reason / Defect', 'key' => 'reason'],
                    ['label' => 'Status', 'key' => 'status', 'align' => 'center'],
                    ['label' => 'Date', 'key' => 'date', 'align' => 'center'],
                ];
                foreach ($reworkQuery->get() as $rw) {
                    $qty = (int) ($rw->quantity ?? 0);
                    $jig = $rw->bomItem?->jig_no ?? '';
                    $unitNo = $rw->bomItem?->unit_no ?? '';
                    $partNo = $rw->bomItem?->standard_part_no ?? '';
                    $side = $rw->side ?? 'COMMON';
                    $partNumber = trim($jig) . trim($unitNo) . trim($partNo) . trim($side);

                    $totalQuantity += $qty;
                    $items[] = [
                        'id' => $rw->id,
                        'part_number' => $partNumber,
                        'part_no' => $partNo ?: '—',
                        'item_no' => $rw->bomItem?->item_no ?? '—',
                        'project' => $rw->bomItem?->project?->project_code ?? ($rw->bomItem?->project?->name ?? '—'),
                        'jig_unit' => ($jig ? $jig : '') . ($unitNo ? ' / ' . $unitNo : ''),
                        'side' => $side,
                        'quantity' => $qty,
                        'reason' => $rw->rework_reason ?: ($rw->remarks ?: 'Rework Required'),
                        'status' => strtoupper(str_replace('_', ' ', $rw->status)),
                        'date' => $rw->created_at?->format('d-M-Y H:i') ?? '—',
                    ];
                }
                break;

            case 'paint':
                $title = 'Parts in Paint Shop Queue';
                $columns = [
                    ['label' => 'Part Number', 'key' => 'part_number'],
                    ['label' => 'Part No', 'key' => 'part_no'],
                    ['label' => 'Item No', 'key' => 'item_no'],
                    ['label' => 'Project', 'key' => 'project'],
                    ['label' => 'JIG / Unit', 'key' => 'jig_unit'],
                    ['label' => 'Side', 'key' => 'side', 'align' => 'center'],
                    ['label' => 'Paint Qty', 'key' => 'quantity', 'align' => 'center'],
                    ['label' => 'Supplier', 'key' => 'supplier'],
                    ['label' => 'Process Type / Notes', 'key' => 'process_type'],
                    ['label' => 'Status', 'key' => 'status', 'align' => 'center'],
                    ['label' => 'Date', 'key' => 'date', 'align' => 'center'],
                ];

                // 1. QC Approved inspections waiting for painting
                $qcPaintQuery = QcInspection::query()
                    ->with(['bomItem.project', 'bomItem.supplier'])
                    ->where('approved_quantity', '>', 0)
                    ->where(function ($q) {
                        $q->where('destination', 'PAINT')->orWhereNull('destination');
                    })
                    ->whereRaw('(approved_quantity - (SELECT COALESCE(SUM(quantity), 0) FROM paint_records WHERE paint_records.qc_inspection_id = qc_inspections.id)) > 0')
                    ->whereHas('bomItem', fn($q) => $q->whereIn('project_id', $activeProjectIds))
                    ->orderByDesc('created_at');

                foreach ($qcPaintQuery->get() as $qc) {
                    $paintedQty = (int) PaintRecord::where('qc_inspection_id', $qc->id)->sum('quantity');
                    $availQty = max(0, (int)$qc->approved_quantity - $paintedQty);
                    if ($availQty > 0) {
                        $jig = $qc->bomItem?->jig_no ?? '';
                        $unitNo = $qc->bomItem?->unit_no ?? '';
                        $partNo = $qc->bomItem?->standard_part_no ?? '';
                        $side = $qc->side ?? 'COMMON';
                        $partNumber = trim($jig) . trim($unitNo) . trim($partNo) . trim($side);

                        $totalQuantity += $availQty;
                        $items[] = [
                            'id' => 'qc_' . $qc->id,
                            'part_number' => $partNumber,
                            'part_no' => $partNo ?: '—',
                            'item_no' => $qc->bomItem?->item_no ?? '—',
                            'project' => $qc->bomItem?->project?->project_code ?? ($qc->bomItem?->project?->name ?? '—'),
                            'jig_unit' => ($jig ? $jig : '') . ($unitNo ? ' / ' . $unitNo : ''),
                            'side' => $side,
                            'quantity' => $availQty,
                            'supplier' => $qc->bomItem?->supplier?->name ?? $qc->bomItem?->supplier_name_raw ?? '—',
                            'process_type' => 'QC Approved (Awaiting Paint)',
                            'status' => 'PAINT QUEUE',
                            'date' => $qc->created_at?->format('d-M-Y H:i') ?? '—',
                        ];
                    }
                }

                // 2. Active Paint records (pending / in_progress / ready)
                $paintActiveQuery = PaintRecord::query()
                    ->with(['bomItem.project', 'bomItem.supplier'])
                    ->whereIn('status', ['pending', 'in_progress', 'ready'])
                    ->whereHas('bomItem', fn($q) => $q->whereIn('project_id', $activeProjectIds))
                    ->orderByDesc('created_at');

                foreach ($paintActiveQuery->get() as $pt) {
                    $qty = (int) ($pt->quantity ?? 0);
                    $jig = $pt->bomItem?->jig_no ?? '';
                    $unitNo = $pt->bomItem?->unit_no ?? '';
                    $partNo = $pt->bomItem?->standard_part_no ?? '';
                    $side = $pt->side ?? 'COMMON';
                    $partNumber = trim($jig) . trim($unitNo) . trim($partNo) . trim($side);

                    $totalQuantity += $qty;
                    $items[] = [
                        'id' => 'paint_' . $pt->id,
                        'part_number' => $partNumber,
                        'part_no' => $partNo ?: '—',
                        'item_no' => $pt->bomItem?->item_no ?? '—',
                        'project' => $pt->bomItem?->project?->project_code ?? ($pt->bomItem?->project?->name ?? '—'),
                        'jig_unit' => ($jig ? $jig : '') . ($unitNo ? ' / ' . $unitNo : ''),
                        'side' => $side,
                        'quantity' => $qty,
                        'supplier' => $pt->bomItem?->supplier?->name ?? $pt->bomItem?->supplier_name_raw ?? '—',
                        'process_type' => $pt->remarks ?: 'Surface Primer & Paint',
                        'status' => strtoupper(str_replace('_', ' ', $pt->status)),
                        'date' => $pt->created_at?->format('d-M-Y H:i') ?? '—',
                    ];
                }
                break;

            case 'assembly':
                $title = 'Parts in Assembly Shop Queue';
                $columns = [
                    ['label' => 'Part Number', 'key' => 'part_number'],
                    ['label' => 'Part No', 'key' => 'part_no'],
                    ['label' => 'Item No', 'key' => 'item_no'],
                    ['label' => 'Project', 'key' => 'project'],
                    ['label' => 'JIG / Unit', 'key' => 'jig_unit'],
                    ['label' => 'Side', 'key' => 'side', 'align' => 'center'],
                    ['label' => 'Assembly Qty', 'key' => 'quantity', 'align' => 'center'],
                    ['label' => 'Supplier', 'key' => 'supplier'],
                    ['label' => 'Source Stage', 'key' => 'source'],
                    ['label' => 'Status', 'key' => 'status', 'align' => 'center'],
                    ['label' => 'Date', 'key' => 'date', 'align' => 'center'],
                ];

                // 1. Completed Paint records waiting for assembly
                $paintAsmQuery = PaintRecord::query()
                    ->with(['bomItem.project', 'bomItem.supplier'])
                    ->whereIn('status', ['completed', 'assembled'])
                    ->whereRaw('(quantity - (SELECT COALESCE(SUM(quantity), 0) FROM assembly_records WHERE assembly_records.paint_record_id = paint_records.id)) > 0')
                    ->whereHas('bomItem', fn($q) => $q->whereIn('project_id', $activeProjectIds))
                    ->orderByDesc('created_at');

                foreach ($paintAsmQuery->get() as $pt) {
                    $alreadyAssembled = (int) AssemblyRecord::where('paint_record_id', $pt->id)->sum('quantity');
                    $availQty = max(0, (int)$pt->quantity - $alreadyAssembled);
                    if ($availQty > 0) {
                        $jig = $pt->bomItem?->jig_no ?? '';
                        $unitNo = $pt->bomItem?->unit_no ?? '';
                        $partNo = $pt->bomItem?->standard_part_no ?? '';
                        $side = $pt->side ?? 'COMMON';
                        $partNumber = trim($jig) . trim($unitNo) . trim($partNo) . trim($side);

                        $totalQuantity += $availQty;
                        $items[] = [
                            'id' => 'paint_' . $pt->id,
                            'part_number' => $partNumber,
                            'part_no' => $partNo ?: '—',
                            'item_no' => $pt->bomItem?->item_no ?? '—',
                            'project' => $pt->bomItem?->project?->project_code ?? ($pt->bomItem?->project?->name ?? '—'),
                            'jig_unit' => ($jig ? $jig : '') . ($unitNo ? ' / ' . $unitNo : ''),
                            'side' => $side,
                            'quantity' => $availQty,
                            'supplier' => $pt->bomItem?->supplier?->name ?? $pt->bomItem?->supplier_name_raw ?? '—',
                            'source' => 'Paint Shop (Completed)',
                            'status' => 'READY FOR ASSEMBLY',
                            'date' => $pt->created_at?->format('d-M-Y H:i') ?? '—',
                        ];
                    }
                }

                // 2. Direct QC inspections (destination = ASSEMBLY) waiting for assembly
                $qcDirectAsmQuery = QcInspection::query()
                    ->with(['bomItem.project', 'bomItem.supplier'])
                    ->where('approved_quantity', '>', 0)
                    ->where('destination', 'ASSEMBLY')
                    ->whereRaw('(approved_quantity - (SELECT COALESCE(SUM(quantity), 0) FROM assembly_records WHERE assembly_records.qc_inspection_id = qc_inspections.id)) > 0')
                    ->whereHas('bomItem', fn($q) => $q->whereIn('project_id', $activeProjectIds))
                    ->orderByDesc('created_at');

                foreach ($qcDirectAsmQuery->get() as $qc) {
                    $alreadyAssembled = (int) AssemblyRecord::where('qc_inspection_id', $qc->id)->sum('quantity');
                    $availQty = max(0, (int)$qc->approved_quantity - $alreadyAssembled);
                    if ($availQty > 0) {
                        $jig = $qc->bomItem?->jig_no ?? '';
                        $unitNo = $qc->bomItem?->unit_no ?? '';
                        $partNo = $qc->bomItem?->standard_part_no ?? '';
                        $side = $qc->side ?? 'COMMON';
                        $partNumber = trim($jig) . trim($unitNo) . trim($partNo) . trim($side);

                        $totalQuantity += $availQty;
                        $items[] = [
                            'id' => 'qc_' . $qc->id,
                            'part_number' => $partNumber,
                            'part_no' => $partNo ?: '—',
                            'item_no' => $qc->bomItem?->item_no ?? '—',
                            'project' => $qc->bomItem?->project?->project_code ?? ($qc->bomItem?->project?->name ?? '—'),
                            'jig_unit' => ($jig ? $jig : '') . ($unitNo ? ' / ' . $unitNo : ''),
                            'side' => $side,
                            'quantity' => $availQty,
                            'supplier' => $qc->bomItem?->supplier?->name ?? $qc->bomItem?->supplier_name_raw ?? '—',
                            'source' => 'Direct from QC (Bypass Paint)',
                            'status' => 'READY FOR ASSEMBLY',
                            'date' => $qc->created_at?->format('d-M-Y H:i') ?? '—',
                        ];
                    }
                }

                // 3. Active Assembly records (pending / in_progress / ready)
                $asmActiveQuery = AssemblyRecord::query()
                    ->with(['bomItem.project', 'bomItem.supplier'])
                    ->whereIn('status', ['pending', 'in_progress', 'ready'])
                    ->whereHas('bomItem', fn($q) => $q->whereIn('project_id', $activeProjectIds))
                    ->orderByDesc('created_at');

                foreach ($asmActiveQuery->get() as $as) {
                    $qty = (int) ($as->quantity ?? 0);
                    $jig = $as->bomItem?->jig_no ?? '';
                    $unitNo = $as->bomItem?->unit_no ?? '';
                    $partNo = $as->bomItem?->standard_part_no ?? '';
                    $side = $as->side ?? 'COMMON';
                    $partNumber = trim($jig) . trim($unitNo) . trim($partNo) . trim($side);

                    $totalQuantity += $qty;
                    $items[] = [
                        'id' => 'asm_' . $as->id,
                        'part_number' => $partNumber,
                        'part_no' => $partNo ?: '—',
                        'item_no' => $as->bomItem?->item_no ?? '—',
                        'project' => $as->bomItem?->project?->project_code ?? ($as->bomItem?->project?->name ?? '—'),
                        'jig_unit' => ($jig ? $jig : '') . ($unitNo ? ' / ' . $unitNo : ''),
                        'side' => $side,
                        'quantity' => $qty,
                        'supplier' => $as->bomItem?->supplier?->name ?? $as->bomItem?->supplier_name_raw ?? '—',
                        'source' => 'Assembly Shop',
                        'status' => strtoupper(str_replace('_', ' ', $as->status)),
                        'date' => $as->created_at?->format('d-M-Y H:i') ?? '—',
                    ];
                }
                break;
        }

        return response()->json([
            'block' => $block,
            'title' => $title,
            'project_id' => $projectId,
            'columns' => $columns,
            'items' => $items,
            'total_quantity' => $totalQuantity,
            'total_records' => count($items),
        ]);
    }

    public function bottleneck(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER']) ?: abort(403);

        $stages = [
            'store_to_qc' => ['start' => 'store_received', 'end' => 'sent_to_qc'],
            'qc_inspection' => ['start' => 'sent_to_qc', 'end' => 'qc_inspected'],
            'rework_cycle' => ['start' => 'qc_rework', 'end' => 'rework_completed'],
            'paint_shop' => ['start' => 'qc_approved', 'end' => 'paint_completed'],
            'assembly_shop' => ['start' => 'paint_completed', 'end' => 'assembly_completed'],
        ];

        $bottlenecks = [];
        $hasSufficientData = false;

        $diffExpr = 'AVG(EXTRACT(EPOCH FROM (e2.created_at - e1.created_at)) / 86400)';

        foreach ($stages as $key => $events) {
            $avgDays = DB::table('workflow_events as e1')
                ->join('workflow_events as e2', function ($join) use ($events) {
                    $join->on('e1.bom_item_id', '=', 'e2.bom_item_id')
                         ->on('e1.side', '=', 'e2.side')
                         ->where('e1.event_type', '=', $events['start'])
                         ->where('e2.event_type', '=', $events['end'])
                         ->whereColumn('e2.created_at', '>', 'e1.created_at');
                })
                ->select(DB::raw("{$diffExpr} as avg_days"), DB::raw('COUNT(*) as sample_count'))
                ->first();

            $days = $avgDays && $avgDays->sample_count >= 1 ? round((float) $avgDays->avg_days, 1) : null;
            if ($days !== null) {
                $hasSufficientData = true;
            }

            $bottlenecks[$key] = [
                'stage' => ucwords(str_replace('_', ' ', $key)),
                'avg_days' => $days,
                'sample_count' => $avgDays ? (int) $avgDays->sample_count : 0,
            ];
        }

        return response()->json([
            'sufficient_data' => $hasSufficientData,
            'stages' => $bottlenecks,
        ]);
    }

    /**
     * Rolling 5-Active-Day Department Parts Movement Matrix with History Navigation
     */
    public function dailyMovement(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $projectId = $request->input('project_id');
        $side = $request->input('side');
        $quickRange = $request->input('quick_range', 'last_5_active');
        $windowOffset = max(0, (int) $request->input('window_offset', 0));
        $windowSize = max(1, (int) $request->input('window_size', 5));

        // Base query for workflow events
        $baseQuery = WorkflowEvent::query()
            ->when($projectId, fn($q) => $q->where('project_id', $projectId))
            ->when($side, fn($q) => $q->where('side', $side));

        // Get all distinct active dates containing movements in descending order
        $dateSql = DB::getDriverName() === 'sqlite' ? "strftime('%Y-%m-%d', created_at)" : "to_char(created_at, 'YYYY-MM-DD')";
        $allActiveDates = (clone $baseQuery)
            ->selectRaw("{$dateSql} as date_key")
            ->groupBy('date_key')
            ->orderBy('date_key', 'desc')
            ->pluck('date_key')
            ->toArray();

        $totalActiveDays = count($allActiveDates);

        // Determine target active dates based on quick range or rolling window
        $targetDates = [];
        $displayedPeriodLabel = '';

        if ($quickRange === 'last_5_active') {
            $targetDates = array_slice($allActiveDates, $windowOffset, $windowSize);
            $hasPrev = ($windowOffset + $windowSize) < $totalActiveDays;
            $hasNext = $windowOffset > 0;
            if (count($targetDates) > 0) {
                $firstFormatted = date('d-M-Y', strtotime(end($targetDates)));
                $lastFormatted = date('d-M-Y', strtotime($targetDates[0]));
                $displayedPeriodLabel = count($targetDates) === 1 ? $firstFormatted : "{$firstFormatted} to {$lastFormatted}";
            } else {
                $displayedPeriodLabel = "No active movement days recorded";
            }
        } elseif ($quickRange === 'last_10_days') {
            $startDate = now()->subDays(10)->format('Y-m-d');
            $targetDates = array_values(array_filter($allActiveDates, fn($d) => $d >= $startDate));
            $hasPrev = false;
            $hasNext = false;
            $displayedPeriodLabel = "Last 10 Days";
        } elseif ($quickRange === 'this_week') {
            $startDate = now()->startOfWeek()->format('Y-m-d');
            $targetDates = array_values(array_filter($allActiveDates, fn($d) => $d >= $startDate));
            $hasPrev = false;
            $hasNext = false;
            $displayedPeriodLabel = "This Week";
        } elseif ($quickRange === 'last_week') {
            $startDate = now()->subWeek()->startOfWeek()->format('Y-m-d');
            $endDate = now()->subWeek()->endOfWeek()->format('Y-m-d');
            $targetDates = array_values(array_filter($allActiveDates, fn($d) => $d >= $startDate && $d <= $endDate));
            $hasPrev = false;
            $hasNext = false;
            $displayedPeriodLabel = "Last Week";
        } elseif ($quickRange === 'this_month') {
            $startDate = now()->startOfMonth()->format('Y-m-d');
            $targetDates = array_values(array_filter($allActiveDates, fn($d) => $d >= $startDate));
            $hasPrev = false;
            $hasNext = false;
            $displayedPeriodLabel = "This Month";
        } elseif ($quickRange === 'custom' && $request->filled('date_from') && $request->filled('date_to')) {
            $from = $request->input('date_from');
            $to = $request->input('date_to');
            $targetDates = array_values(array_filter($allActiveDates, fn($d) => $d >= $from && $d <= $to));
            $hasPrev = false;
            $hasNext = false;
            $displayedPeriodLabel = "{$from} to {$to}";
        } else {
            $targetDates = array_slice($allActiveDates, $windowOffset, $windowSize);
            $hasPrev = ($windowOffset + $windowSize) < $totalActiveDays;
            $hasNext = $windowOffset > 0;
            $displayedPeriodLabel = "5-Day Active Window";
        }

        // Fetch events strictly within the target dates
        $events = [];
        if (!empty($targetDates)) {
            $events = (clone $baseQuery)
                ->with(['bomItem.project', 'user'])
                ->whereIn(DB::raw($dateSql), $targetDates)
                ->orderBy('created_at', 'desc')
                ->get();
        }

        $grouped = [];
        $totals = [
            'store_received' => 0,
            'qc_inspected' => 0,
            'rework' => 0,
            'paint' => 0,
            'assembly' => 0,
            'grand_total' => 0,
        ];

        // Pre-populate target dates so empty dates in window still show up in order
        foreach ($targetDates as $dKey) {
            $grouped[$dKey] = [
                'date' => $dKey,
                'formatted_date' => date('d-M-y', strtotime($dKey)),
                'store_received' => 0,
                'qc_inspected' => 0,
                'rework' => 0,
                'paint' => 0,
                'assembly' => 0,
                'total_day' => 0,
                'parts' => [],
            ];
        }

        foreach ($events as $evt) {
            $dateKey = $evt->created_at->format('Y-m-d');
            if (!isset($grouped[$dateKey])) continue;

            $qty = $evt->quantity;
            $type = $evt->event_type;

            if ($type === 'store_received') {
                $grouped[$dateKey]['store_received'] += $qty;
                $totals['store_received'] += $qty;
            } elseif ($type === 'store_receipt_reverted') {
                $grouped[$dateKey]['store_received'] += $qty;
                $totals['store_received'] += $qty;
            } elseif ($type === 'qc_inspected') {
                $grouped[$dateKey]['qc_inspected'] += $qty;
                $totals['qc_inspected'] += $qty;
                if ($evt->new_state === 'rework') {
                    $grouped[$dateKey]['rework'] += $qty;
                    $totals['rework'] += $qty;
                }
            } elseif (in_array($type, ['qc_rework', 'rework_started', 'rework_completed'])) {
                $grouped[$dateKey]['rework'] += $qty;
                $totals['rework'] += $qty;
            } elseif ($type === 'paint_completed') {
                $grouped[$dateKey]['paint'] += $qty;
                $totals['paint'] += $qty;
            } elseif ($type === 'assembly_completed') {
                $grouped[$dateKey]['assembly'] += $qty;
                $totals['assembly'] += $qty;
            }

            $eventLabel = strtoupper(str_replace('_', ' ', $type));
            if ($type === 'qc_inspected' && !empty($evt->new_state)) {
                $eventLabel = 'QC INSPECTED (' . strtoupper($evt->new_state) . ')';
            }

            $grouped[$dateKey]['parts'][] = [
                'id' => $evt->id,
                'standard_part_no' => $evt->bomItem?->standard_part_no ?? 'Part #' . $evt->bom_item_id,
                'project' => $evt->bomItem?->project?->name ?? 'N/A',
                'side' => $evt->side,
                'quantity' => $evt->quantity,
                'department_event' => $eventLabel,
                'user' => $evt->user?->name ?? 'System',
                'date' => $evt->created_at->format('d-M-y'),
                'time' => $evt->created_at->format('h:i:s A'),
                'created_at_iso' => $evt->created_at->toIso8601String(),
            ];
        }

        foreach ($grouped as &$day) {
            $day['total_day'] = $day['store_received'] + $day['qc_inspected'] + $day['rework'] + $day['paint'] + $day['assembly'];
        }
        unset($day);

        $totals['grand_total'] = $totals['store_received'] + $totals['qc_inspected'] + $totals['rework'] + $totals['paint'] + $totals['assembly'];

        return response()->json([
            'matrix' => array_values($grouped),
            'totals' => $totals,
            'pagination' => [
                'window_offset' => $windowOffset,
                'window_size' => $windowSize,
                'total_active_days' => $totalActiveDays,
                'has_previous_window' => $hasPrev ?? false,
                'has_next_window' => $hasNext ?? false,
                'displayed_period_label' => $displayedPeriodLabel,
                'quick_range' => $quickRange,
            ],
        ]);
    }

    /**
     * Pipeline Transparency: Returns all receipt items with their current stage.
     */
    public function pipelineStatus(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $items = ReceiptItem::query()
            ->with(['bomItem.project', 'bomItem.supplier'])
            ->orderByDesc('updated_at')
            ->get();

        $stageMap = [
            'pending'          => ['label' => '⏳ Pending Arrival',       'color' => 'secondary', 'dept' => 'STORE'],
            'received'         => ['label' => '📦 Received in Store',      'color' => 'success',   'dept' => 'STORE'],
            'sent_to_qc'       => ['label' => '🚚 Dispatched to QC',      'color' => 'info',      'dept' => 'QC'],
            'qc_received'      => ['label' => '🔬 In QC (Physical Rcvd)',  'color' => 'info',      'dept' => 'QC'],
            'qc_approved'      => ['label' => '✅ QC Approved → Paint',    'color' => 'primary',   'dept' => 'PAINT'],
            'qc_rework'        => ['label' => '⚙️ Sent to Rework',         'color' => 'warning',   'dept' => 'REWORK'],
            'qc_rejected'      => ['label' => '❌ QC Rejected → Reorder',  'color' => 'danger',    'dept' => 'PURCHASE'],
            'qc_inspected'     => ['label' => '🔬 QC Inspected',           'color' => 'info',      'dept' => 'QC'],
            'paint_completed'  => ['label' => '🎨 Paint Complete',         'color' => 'purple',    'dept' => 'PAINT'],
            'assembly_completed' => ['label' => '🔩 Assembled',             'color' => 'teal',      'dept' => 'ASSEMBLY'],
        ];

        $counts = [
            'store' => 0,
            'qc' => 0,
            'rework' => 0,
            'paint' => 0,
            'assembly' => 0,
            'purchase' => 0,
        ];

        $list = $items->map(function ($item) use ($stageMap, &$counts) {
            $st = $item->status ?? 'pending';
            $info = $stageMap[$st] ?? ['label' => strtoupper($st), 'color' => 'secondary', 'dept' => 'STORE'];
            $dept = strtolower($info['dept']);
            if (isset($counts[$dept])) {
                $counts[$dept] += $item->received_quantity;
            }

            return [
                'id' => $item->id,
                'standard_part_no' => $item->bomItem?->standard_part_no ?? 'N/A',
                'project_name' => $item->bomItem?->project?->name ?? 'N/A',
                'supplier_name' => $item->bomItem?->supplier?->name ?? 'N/A',
                'side' => $item->side,
                'quantity' => $item->received_quantity,
                'status' => $st,
                'stage_label' => $info['label'],
                'stage_color' => $info['color'],
                'department' => $info['dept'],
                'updated_at' => $item->updated_at?->format('d-M-Y H:i'),
            ];
        });

        return response()->json([
            'items' => $list,
            'department_counts' => $counts,
            'total_in_pipeline' => $items->sum('received_quantity'),
        ]);
    }

    /**
     * Parts Priority Intelligence: Groups BOM parts by JIG and Unit number,
     * calculates completion %, and assigns Priority Tiers so managers know which parts to order urgently.
     */
    public function priorityMap(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $projectId = $request->query('project_id');

        $bomQuery = BomItem::query()
            ->with(['project', 'supplier', 'requirements', 'receiptItems', 'assemblyRecords'])
            ->whereNotNull('standard_part_no');

        if ($projectId) {
            $bomQuery->where('project_id', $projectId);
        }

        $items = $bomQuery->get();

        $unitsMap = [];

        foreach ($items as $item) {
            $jigName = !empty($item->jig_no) ? strtoupper(trim($item->jig_no)) : 'GENERAL';
            $unitNo = !empty($item->unit_no) ? (str_starts_with(strtoupper(trim($item->unit_no)), 'UNIT') ? trim($item->unit_no) : 'Unit ' . trim($item->unit_no)) : 'Unit 00';
            $unitKey = ($item->project_id ?? 0) . '_' . $jigName . '_' . $unitNo;

            if (!isset($unitsMap[$unitKey])) {
                $unitsMap[$unitKey] = [
                    'key' => $unitKey,
                    'project_id' => $item->project_id,
                    'project_name' => $item->project?->name ?? 'N/A',
                    'project_code' => $item->project?->project_code ?? 'N/A',
                    'jig_name' => $jigName,
                    'unit_no' => $unitNo,
                    'total_required' => 0,
                    'total_received' => 0,
                    'total_assembled' => 0,
                    'pending_quantity' => 0,
                    'parts_count' => 0,
                    'parts' => [],
                    'pending_parts' => [],
                ];
            }

            // Iterate over each distinct side requirement (RH, LH, COMMON) separately
            $requirements = $item->requirements;
            if ($requirements->isEmpty()) {
                $requirements = collect([
                    (object) [
                        'side' => 'COMMON',
                        'required_quantity' => 0,
                    ]
                ]);
            }

            foreach ($requirements as $requirement) {
                $side = $requirement->side ?? 'COMMON';
                $reqQty = (int) ($requirement->required_quantity ?? 0);

                // Calculate received and assembled strictly matching the exact side
                $recQty = (int) $item->receiptItems->where('side', $side)->sum('received_quantity');
                $asmQty = (int) $item->assemblyRecords->where('status', 'completed')->where('side', $side)->sum('quantity');

                $unitsMap[$unitKey]['total_required'] += $reqQty;
                $unitsMap[$unitKey]['total_received'] += min($recQty, $reqQty);
                $unitsMap[$unitKey]['total_assembled'] += $asmQty;
                $unitsMap[$unitKey]['parts_count']++;

                $partObj = [
                    'id' => $item->id . '_' . $side,
                    'bom_item_id' => $item->id,
                    'standard_part_no' => $item->standard_part_no,
                    'side' => $side,
                    'required' => $reqQty,
                    'received' => $recQty,
                    'assembled' => $asmQty,
                    'pending' => max(0, $reqQty - $recQty),
                    'supplier' => $item->supplier?->name ?? 'Standard Supplier',
                    'is_assembled' => ($reqQty > 0 && $asmQty >= $reqQty),
                ];

                $unitsMap[$unitKey]['parts'][] = $partObj;
                if ($partObj['pending'] > 0) {
                    $unitsMap[$unitKey]['pending_parts'][] = $partObj;
                }
            }
        }

        $summaryCounts = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'complete' => 0,
            'CRITICAL' => 0,
            'HIGH' => 0,
            'MEDIUM' => 0,
            'LOW' => 0,
            'COMPLETE' => 0,
            'total_units' => count($unitsMap),
        ];

        $unitsList = [];

        foreach ($unitsMap as $u) {
            $req = $u['total_required'];
            $rec = $u['total_received'];
            $pending = max(0, $req - $rec);
            $u['pending_quantity'] = $pending;
            $pct = $req > 0 ? min(100, round(($rec / $req) * 100, 1)) : 100;

            if ($pct >= 100 || $pending === 0) {
                $tier = 'COMPLETE';
                $tierClass = 'success';
                $tierOrder = 5;
                $summaryCounts['complete']++;
                $summaryCounts['COMPLETE']++;
            } elseif ($pct >= 70 && $pending > 0) {
                $tier = 'CRITICAL';
                $tierClass = 'danger';
                $tierOrder = 1;
                $summaryCounts['critical']++;
                $summaryCounts['CRITICAL']++;
            } elseif ($pct >= 40) {
                $tier = 'HIGH';
                $tierClass = 'warning';
                $tierOrder = 2;
                $summaryCounts['high']++;
                $summaryCounts['HIGH']++;
            } elseif ($pct >= 20) {
                $tier = 'MEDIUM';
                $tierClass = 'info';
                $tierOrder = 3;
                $summaryCounts['medium']++;
                $summaryCounts['MEDIUM']++;
            } else {
                $tier = 'LOW';
                $tierClass = 'secondary';
                $tierOrder = 4;
                $summaryCounts['low']++;
                $summaryCounts['LOW']++;
            }

            $u['completion_pct'] = $pct;
            $u['priority_tier'] = $tier;
            $u['tier_class'] = $tierClass;
            $u['tier_order'] = $tierOrder;
            $u['badge_color'] = $tierClass;
            $u['priority_label'] = $tier;

            $unitsList[] = $u;
        }

        usort($unitsList, function ($a, $b) {
            if ($a['tier_order'] !== $b['tier_order']) {
                return $a['tier_order'] <=> $b['tier_order'];
            }
            return $b['completion_pct'] <=> $a['completion_pct'];
        });

        $chartUnits = array_filter($unitsList, fn($u) => $u['priority_tier'] !== 'COMPLETE');
        usort($chartUnits, fn($a, $b) => $b['completion_pct'] <=> $a['completion_pct']);
        $chartUnits = array_slice($chartUnits, 0, 10);

        $projects = Project::orderBy('name')->get(['id', 'name', 'project_code']);

        return response()->json([
            'units' => $unitsList,
            'summary_counts' => $summaryCounts,
            'projects' => $projects,
            'chart' => [
                'labels' => array_column($chartUnits, 'unit_no'),
                'jigs' => array_column($chartUnits, 'jig_name'),
                'percentages' => array_column($chartUnits, 'completion_pct'),
                'tiers' => array_column($chartUnits, 'priority_tier'),
            ],
        ]);
    }

    /**
     * Executive Manager Lower Dashboard: 10 Industry-Grade Real-Data KPIs and Analytics
     */
    public function managementAnalytics(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $projectId = $request->query('project_id');

        // 1. Project Readiness Index (PRI) - 4-Stage Weighted Normalized Stage Readiness
        // Independent RH & LH stage calculation with Total Required BOM Quantity as true denominator
        $rhReq = (int) BomRequirement::where('side', 'RH')->when($projectId, fn($q) => $q->whereHas('bomItem', fn($b) => $b->where('project_id', $projectId)))->sum('required_quantity');
        $rhRec = (int) ReceiptItem::where('side', 'RH')->when($projectId, fn($q) => $q->whereHas('bomItem', fn($b) => $b->where('project_id', $projectId)))->sum('received_quantity');
        $rhQc  = (int) QcInspection::where('side', 'RH')->when($projectId, fn($q) => $q->whereHas('bomItem', fn($b) => $b->where('project_id', $projectId)))->sum('approved_quantity');
        $rhPnt = (int) PaintRecord::where('side', 'RH')->when($projectId, fn($q) => $q->whereHas('bomItem', fn($b) => $b->where('project_id', $projectId)))->where('status', 'completed')->sum('quantity');
        $rhAsm = (int) AssemblyRecord::where('side', 'RH')->when($projectId, fn($q) => $q->whereHas('bomItem', fn($b) => $b->where('project_id', $projectId)))->where('status', 'completed')->sum('quantity');

        $lhReq = (int) BomRequirement::where('side', 'LH')->when($projectId, fn($q) => $q->whereHas('bomItem', fn($b) => $b->where('project_id', $projectId)))->sum('required_quantity');
        $lhRec = (int) ReceiptItem::where('side', 'LH')->when($projectId, fn($q) => $q->whereHas('bomItem', fn($b) => $b->where('project_id', $projectId)))->sum('received_quantity');
        $lhQc  = (int) QcInspection::where('side', 'LH')->when($projectId, fn($q) => $q->whereHas('bomItem', fn($b) => $b->where('project_id', $projectId)))->sum('approved_quantity');
        $lhPnt = (int) PaintRecord::where('side', 'LH')->when($projectId, fn($q) => $q->whereHas('bomItem', fn($b) => $b->where('project_id', $projectId)))->where('status', 'completed')->sum('quantity');
        $lhAsm = (int) AssemblyRecord::where('side', 'LH')->when($projectId, fn($q) => $q->whereHas('bomItem', fn($b) => $b->where('project_id', $projectId)))->where('status', 'completed')->sum('quantity');

        // Common parts if any
        $comReq = (int) BomRequirement::where('side', 'COMMON')->when($projectId, fn($q) => $q->whereHas('bomItem', fn($b) => $b->where('project_id', $projectId)))->sum('required_quantity');
        $comRec = (int) ReceiptItem::where('side', 'COMMON')->when($projectId, fn($q) => $q->whereHas('bomItem', fn($b) => $b->where('project_id', $projectId)))->sum('received_quantity');
        $comQc  = (int) QcInspection::where('side', 'COMMON')->when($projectId, fn($q) => $q->whereHas('bomItem', fn($b) => $b->where('project_id', $projectId)))->sum('approved_quantity');
        $comPnt = (int) PaintRecord::where('side', 'COMMON')->when($projectId, fn($q) => $q->whereHas('bomItem', fn($b) => $b->where('project_id', $projectId)))->where('status', 'completed')->sum('quantity');
        $comAsm = (int) AssemblyRecord::where('side', 'COMMON')->when($projectId, fn($q) => $q->whereHas('bomItem', fn($b) => $b->where('project_id', $projectId)))->where('status', 'completed')->sum('quantity');

        $totalReqAll = $rhReq + $lhReq + $comReq;
        $totalRecAll = $rhRec + $lhRec + $comRec;
        $totalQcAll  = $rhQc + $lhQc + $comQc;
        $totalPntAll = $rhPnt + $lhPnt + $comPnt;
        $totalAsmAll = $rhAsm + $lhAsm + $comAsm;

        // Stage fulfillment % against Total Required Quantity
        $storeIntakePct = $totalReqAll > 0 ? min(100, round(($totalRecAll / $totalReqAll) * 100, 1)) : 0;
        $qcPassPct      = $totalReqAll > 0 ? min(100, round(($totalQcAll / $totalReqAll) * 100, 1)) : 0;
        $paintDonePct    = $totalReqAll > 0 ? min(100, round(($totalPntAll / $totalReqAll) * 100, 1)) : 0;
        $assemblyDonePct = $totalReqAll > 0 ? min(100, round(($totalAsmAll / $totalReqAll) * 100, 1)) : 0;

        // Weighted PRI Formula: 25% Store + 25% QC + 25% Paint + 25% Assembly
        $overallReadinessPct = $totalReqAll > 0
            ? round(($storeIntakePct * 0.25) + ($qcPassPct * 0.25) + ($paintDonePct * 0.25) + ($assemblyDonePct * 0.25), 1)
            : 0;

        $rhReadinessPct = $rhReq > 0
            ? round(
                (min(100, ($rhRec / $rhReq) * 100) * 0.25) +
                (min(100, ($rhQc / $rhReq) * 100) * 0.25) +
                (min(100, ($rhPnt / $rhReq) * 100) * 0.25) +
                (min(100, ($rhAsm / $rhReq) * 100) * 0.25), 1)
            : 0;

        $lhReadinessPct = $lhReq > 0
            ? round(
                (min(100, ($lhRec / $lhReq) * 100) * 0.25) +
                (min(100, ($lhQc / $lhReq) * 100) * 0.25) +
                (min(100, ($lhPnt / $lhReq) * 100) * 0.25) +
                (min(100, ($lhAsm / $lhReq) * 100) * 0.25), 1)
            : 0;

        $projectReadinessIndex = [
            'readiness_score' => $overallReadinessPct,
            'rh_readiness_score' => $rhReadinessPct,
            'lh_readiness_score' => $lhReadinessPct,
            'total_required' => $totalReqAll,
            'total_assembled' => $totalAsmAll,
            'has_data' => $totalReqAll > 0,
            'info' => [
                'name' => 'Project Readiness Index (PRI)',
                'meaning' => 'Measures how close the project is to final completion based on progress through key workflow stages.',
                'use_case' => 'Allows management to identify projects that are progressing well versus projects that still have major work remaining.',
                'formula' => 'Weighted average of (Stage Quantity / Total Required BOM Quantity × 100) across Store (25%), QC (25%), Paint (25%), and Assembly (25%).',
                'visual' => 'Gauge/Radial',
            ],
            'breakdown' => [
                ['stage' => 'Store Intake', 'percent' => $storeIntakePct, 'color' => '#2563eb', 'count' => $totalRecAll],
                ['stage' => 'QC Cleared', 'percent' => $qcPassPct, 'color' => '#10b981', 'count' => $totalQcAll],
                ['stage' => 'Surface Painted', 'percent' => $paintDonePct, 'color' => '#7c3aed', 'count' => $totalPntAll],
                ['stage' => 'Final Assembled', 'percent' => $assemblyDonePct, 'color' => '#0d9488', 'count' => $totalAsmAll],
            ],
        ];

        // 2. Production Conversion Rate (PCR) Funnel
        $conversionRate = [
            'store_intake' => $totalRecAll,
            'qc_approved' => $totalQcAll,
            'paint_completed' => $totalPntAll,
            'final_assembled' => $totalAsmAll,
            'qc_conversion_pct' => $totalRecAll > 0 ? round(($totalQcAll / $totalRecAll) * 100, 1) : 0,
            'paint_conversion_pct' => $totalQcAll > 0 ? round(($totalPntAll / $totalQcAll) * 100, 1) : 0,
            'assembly_conversion_pct' => $totalPntAll > 0 ? round(($totalAsmAll / $totalPntAll) * 100, 1) : 0,
            'overall_yield_pct' => $totalRecAll > 0 ? round(($totalAsmAll / $totalRecAll) * 100, 1) : 0,
            'info' => [
                'name' => 'Production Conversion Rate',
                'meaning' => 'Percentage of received parts that ultimately become completed assembly parts.',
                'use_case' => 'Shows overall efficiency from material receipt to final production completion.',
                'formula' => '(Assembly Completed / Store Received) × 100',
                'visual' => 'Funnel',
            ],
        ];

        // 3. Project Completion Velocity (Daily Completed Parts + 7-Day Moving Avg)
        $daysRange = 14;
        $velocityData = [];
        for ($i = $daysRange - 1; $i >= 0; $i--) {
            $d = now()->subDays($i)->format('Y-m-d');
            $cnt = (int) AssemblyRecord::when($projectId, fn($q) => $q->whereHas('bomItem', fn($b) => $b->where('project_id', $projectId)))
                ->where('status', 'completed')
                ->whereDate('created_at', $d)
                ->sum('quantity');
            $velocityData[] = [
                'date' => $d,
                'label' => now()->subDays($i)->format('d M'),
                'completed' => $cnt,
            ];
        }
        foreach ($velocityData as $idx => &$item) {
            $startIdx = max(0, $idx - 6);
            $slice = array_slice($velocityData, $startIdx, $idx - $startIdx + 1);
            $sum = array_sum(array_column($slice, 'completed'));
            $item['moving_avg'] = round($sum / count($slice), 1);
        }
        unset($item);

        $projectVelocity = [
            'series' => $velocityData,
            'info' => [
                'name' => 'Project Completion Velocity',
                'meaning' => 'Measures how quickly project parts are being completed over time.',
                'use_case' => 'Shows whether project throughput is increasing, decreasing or stable.',
                'formula' => 'Daily finished assembly parts count with 7-day trailing moving average.',
                'visual' => 'Line chart',
            ],
        ];

        // 4. Supplier Fill Accuracy (RH vs LH Measured Independently)
        $topSuppliers = Supplier::where('is_active', true)->with(['bomItems.requirements', 'bomItems.receiptItems'])->limit(5)->get();
        $supplierAccuracyList = $topSuppliers->map(function ($sup) {
            $rhReq = (int) $sup->bomItems->flatMap->requirements->where('side', 'RH')->sum('required_quantity');
            $rhRec = (int) $sup->bomItems->flatMap->receiptItems->where('side', 'RH')->sum('received_quantity');
            $lhReq = (int) $sup->bomItems->flatMap->requirements->where('side', 'LH')->sum('required_quantity');
            $lhRec = (int) $sup->bomItems->flatMap->receiptItems->where('side', 'LH')->sum('received_quantity');
            
            return [
                'supplier_id' => $sup->id,
                'supplier_name' => $sup->name,
                'rh_required' => $rhReq,
                'rh_received' => $rhRec,
                'rh_accuracy_pct' => $rhReq > 0 ? min(100, round(($rhRec / $rhReq) * 100, 1)) : ($rhRec > 0 ? 100 : 0),
                'lh_required' => $lhReq,
                'lh_received' => $lhRec,
                'lh_accuracy_pct' => $lhReq > 0 ? min(100, round(($lhRec / $lhReq) * 100, 1)) : ($lhRec > 0 ? 100 : 0),
            ];
        })->values();

        $supplierFillAccuracy = [
            'suppliers' => $supplierAccuracyList,
            'info' => [
                'name' => 'Supplier Fill Accuracy',
                'meaning' => 'Measures whether suppliers deliver the quantities actually required by the BOM.',
                'use_case' => 'Identifies suppliers responsible for incomplete or partial deliveries.',
                'formula' => '(Received Quantity / BOM Required Quantity) × 100, measured independently for RH and LH.',
                'visual' => 'Grouped bar chart',
            ],
        ];

        // 5. Quality Cost Pressure Score
        $reworkCount = (int) ReworkRecord::count();
        $rejectedCount = (int) QcInspection::sum('rejected_quantity');
        $pressureScore = min(100, round(($reworkCount * 2.5) + ($rejectedCount * 4.0), 0));

        $qualityCostPressure = [
            'pressure_score' => $pressureScore,
            'severity' => $pressureScore > 60 ? 'HIGH' : ($pressureScore > 30 ? 'MODERATE' : 'LOW'),
            'rework_events' => $reworkCount,
            'scrap_rejections' => $rejectedCount,
            'trend' => $pressureScore > 50 ? 'Deteriorating' : ($pressureScore > 0 ? 'Controlled' : 'Optimal / Zero Defect'),
            'info' => [
                'name' => 'Quality Cost Pressure',
                'meaning' => 'Operational pressure created by rework, rejection and repeated rework.',
                'use_case' => 'Identifies where quality problems are consuming additional production capacity.',
                'formula' => 'Normalized index derived from Active Rework Work-orders (weight: 2.5) + Scrap Rejections (weight: 4.0).',
                'visual' => 'KPI + Trend',
            ],
        ];

        return response()->json([
            'project_readiness_index' => $projectReadinessIndex,
            'conversion_rate' => $conversionRate,
            'velocity_series' => $velocityData,
            'project_velocity' => $projectVelocity,
            'supplier_fill_accuracy' => $supplierFillAccuracy,
            'quality_cost_pressure' => $qualityCostPressure,
        ]);
    }
}
