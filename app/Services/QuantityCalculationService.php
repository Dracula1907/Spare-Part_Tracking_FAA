<?php

namespace App\Services;

use App\Models\Project;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\ReworkRecord;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use App\Models\PurchaseQueueItem;
use App\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class QuantityCalculationService
{
    /**
     * Active/Valid Receipt Statuses that represent valid physical inventory
     * (excludes reverted, returned_to_vendor, scrapped).
     */
    public const VALID_RECEIPT_STATUSES = [
        'received',
        'sent_to_qc',
        'qc_received',
        'qc_approved',
        'qc_rework',
        'qc_rejected',
        'qc_inspected',
        'paint_completed',
        'assembly_completed',
        'returned_to_store'
    ];

    /**
     * Calculate authoritative metrics for a single project.
     * Enforces mathematical consistency across all hierarchy levels.
     *
     * @param int|Project $project
     * @param string|null $sideFilter 'RH' | 'LH' | 'COMMON' | null
     * @param array $filters Additional filters: ['supplier_id', 'date_from', 'date_to']
     * @return array
     */
    public function calculateProjectMetrics(int|Project $project, ?string $sideFilter = null, array $filters = []): array
    {
        $proj = $project instanceof Project ? $project : Project::findOrFail($project);

        $bomItemsQuery = BomItem::query()
            ->with(['requirements', 'supplier'])
            ->where('project_id', $proj->id);

        if (!empty($filters['supplier_id'])) {
            $bomItemsQuery->where('supplier_id', $filters['supplier_id']);
        }

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $bomItemsQuery->where(function ($q) use ($search) {
                $q->where('standard_part_no', 'LIKE', "%{$search}%")
                  ->orWhere('item_no', 'LIKE', "%{$search}%");
            });
        }

        $bomItems = $bomItemsQuery->orderBy('standard_part_no')->get();

        if ($bomItems->isEmpty()) {
            return $this->formatProjectSummaryResult($proj, [
                'total_required' => 0,
                'total_received' => 0,
                'raw_received' => 0,
                'excess_received' => 0,
                'total_pending' => 0,
                'completion_pct' => 0,
                'parts_in_store' => 0,
                'parts_in_qc' => 0,
                'parts_in_rework' => 0,
                'parts_in_paint' => 0,
                'parts_in_assembly' => 0,
                'awaiting_qc' => 0,
                'qc_approved' => 0,
                'qc_rejected' => 0,
                'qc_rework' => 0,
                'rework_pending' => 0,
                'rework_in_progress' => 0,
                'rework_completed' => 0,
                'paint_ready' => 0,
                'paint_completed' => 0,
                'assembly_ready' => 0,
                'assembly_completed' => 0,
                'total_items' => 0,
            ]);
        }

        $bomItemIds = $bomItems->pluck('id')->toArray();

        // Bulk load all related operational records strictly for this project's items
        $recQuery = ReceiptItem::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->whereIn('status', self::VALID_RECEIPT_STATUSES);

        $qcQuery = QcInspection::query()->whereIn('bom_item_id', $bomItemIds);
        $reworkQuery = ReworkRecord::query()->whereIn('bom_item_id', $bomItemIds);
        $paintQuery = PaintRecord::query()->whereIn('bom_item_id', $bomItemIds);
        $asmQuery = AssemblyRecord::query()->whereIn('bom_item_id', $bomItemIds);

        if (!empty($filters['date_from'])) {
            $recQuery->where('created_at', '>=', $filters['date_from']);
            $qcQuery->where('inspection_date', '>=', $filters['date_from']);
            $reworkQuery->where('created_at', '>=', $filters['date_from']);
            $paintQuery->where('created_at', '>=', $filters['date_from']);
            $asmQuery->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $recQuery->where('created_at', '<=', $filters['date_to']);
            $qcQuery->where('inspection_date', '<=', $filters['date_to']);
            $reworkQuery->where('created_at', '<=', $filters['date_to']);
            $paintQuery->where('created_at', '<=', $filters['date_to']);
            $asmQuery->where('created_at', '<=', $filters['date_to']);
        }

        $receiptsGrouped = $recQuery->get()->groupBy('bom_item_id');
        $qcGrouped = $qcQuery->get()->groupBy('bom_item_id');
        $reworkGrouped = $reworkQuery->get()->groupBy('bom_item_id');
        $paintGrouped = $paintQuery->get()->groupBy('bom_item_id');
        $asmGrouped = $asmQuery->get()->groupBy('bom_item_id');

        $metrics = [
            'total_required' => 0,
            'total_received' => 0,      // Canonical: capped at min(received, required) per part+side
            'raw_received' => 0,        // Total physical receipts sum
            'excess_received' => 0,     // Total over-receipt sum (raw_received - total_received)
            'total_pending' => 0,       // Canonical: max(0, required - total_received)
            'parts_in_store' => 0,      // Currently residing in Store (status: received, returned_to_store)
            'parts_in_qc' => 0,         // Currently residing in QC (status: sent_to_qc, qc_received)
            'parts_in_rework' => 0,     // Currently residing in Rework (rework pending/in_progress)
            'parts_in_paint' => 0,      // Currently residing in Paint (qc_approved destined for paint minus paint_completed)
            'parts_in_assembly' => 0,   // Currently residing in Assembly (paint_completed + qc_direct_asm minus asm_completed)
            'awaiting_qc' => 0,
            'qc_approved' => 0,
            'qc_rejected' => 0,
            'qc_rework' => 0,
            'rework_pending' => 0,
            'rework_in_progress' => 0,
            'rework_completed' => 0,
            'paint_ready' => 0,
            'paint_completed' => 0,
            'assembly_ready' => 0,
            'assembly_completed' => 0,
            'total_items' => $bomItems->count(),
        ];

        foreach ($bomItems as $item) {
            $itemReceipts = $receiptsGrouped->get($item->id, collect());
            $itemQc = $qcGrouped->get($item->id, collect());
            $itemRework = $reworkGrouped->get($item->id, collect());
            $itemPaint = $paintGrouped->get($item->id, collect());
            $itemAsm = $asmGrouped->get($item->id, collect());

            foreach ($item->requirements as $req) {
                $side = $req->side;

                // Side isolation filter
                if (!empty($sideFilter) && $sideFilter !== $side && $side !== 'COMMON') {
                    continue;
                }

                $reqQty = (int) $req->required_quantity;
                $recForSide = $itemReceipts->where('side', $side);
                $qcForSide = $itemQc->where('side', $side);
                $reworkForSide = $itemRework->where('side', $side);
                $paintForSide = $itemPaint->where('side', $side);
                $asmForSide = $itemAsm->where('side', $side);

                $rawRecQty = (int) $recForSide->sum('received_quantity');
                $effectiveRecQty = min($rawRecQty, $reqQty); // Capped at BOM requirement
                $excessQty = max(0, $rawRecQty - $reqQty);   // Physical over-delivery
                $pendingQty = max(0, $reqQty - $effectiveRecQty);

                // QC Inspection stats for this side
                $qcAppPaint = (int) $qcForSide->filter(fn($q) => $q->approved_quantity > 0 && ($q->destination === 'PAINT' || empty($q->destination)))->sum('approved_quantity');
                $qcAppDirectAssembly = (int) $qcForSide->filter(fn($q) => $q->approved_quantity > 0 && $q->destination === 'ASSEMBLY')->sum('approved_quantity');
                $qcApp = $qcAppPaint + $qcAppDirectAssembly;
                $qcRej = (int) $qcForSide->sum('rejected_quantity');
                $qcRew = (int) $qcForSide->sum('rework_quantity');

                // Rework stats for this side
                $rewComp = (int) $reworkForSide->whereIn('status', ['completed', 'returned_to_qc'])->sum('quantity');
                $rewActive = max(0, $qcRew - $rewComp);

                // Paint stats for this side - include both completed and assembled so Paint never re-acquires assembled parts
                $paintComp = (int) $paintForSide->whereIn('status', ['completed', 'assembled'])->sum('quantity');
                $paintActive = max(0, $qcAppPaint - $paintComp);

                // Assembly stats for this side (Active assembly vs Assembly completed)
                $asmComp = (int) $asmForSide->where('status', 'completed')->sum('quantity');
                $asmReached = $paintComp + $qcAppDirectAssembly;
                $asmReady = max(0, $asmReached - $asmComp);

                // Dispatched to QC (valid quantity that left store for QC)
                $qcDispatchedFromReceipts = (int) $recForSide->whereNotIn('status', ['received', 'returned_to_store'])->sum('received_quantity');
                $qcTotalAccounted = $qcApp + $qcRej + $qcRew;
                $sentToQc = min($effectiveRecQty, max($qcDispatchedFromReceipts, $qcTotalAccounted));

                // State Transition Ledger (Section 12: Zero-sum conservation)
                // Strict zero-sum conservation: location sum == total_received
                $qcResident = max(0, $sentToQc + $rewComp - ($qcApp + $qcRej + $qcRew));
                $storeResident = max(0, $effectiveRecQty - ($qcResident + $qcRej + $rewActive + $paintActive + $asmReady + $asmComp));

                // Canonical BOM balance counters (effectiveRecQty so: required = received + pending)
                $metrics['total_required'] += $reqQty;
                $metrics['total_received'] += $effectiveRecQty;  // Capped: guarantees required = received + pending
                $metrics['raw_received'] += $rawRecQty;          // Physical gate intake (includes over-delivery)
                $metrics['excess_received'] += $excessQty;
                $metrics['total_pending'] += $pendingQty;

                // Location Resident Quantities (Section 11 reconciliation)
                $metrics['parts_in_store'] += $storeResident;
                $metrics['parts_in_qc'] += $qcResident;
                $metrics['parts_in_rework'] += $rewActive;
                $metrics['parts_in_paint'] += $paintActive;
                $metrics['parts_in_assembly'] += $asmReady; // Active Assembly only

                // QC & Operational Stats
                $metrics['awaiting_qc'] += $qcResident;
                $metrics['qc_approved'] += $qcApp;
                $metrics['qc_rejected'] += $qcRej;
                $metrics['qc_rework'] += $qcRew;
                $metrics['rework_pending'] += $rewActive;
                $metrics['rework_in_progress'] += (int) $reworkForSide->where('status', 'in_progress')->sum('quantity');
                $metrics['rework_completed'] += $rewComp;
                $metrics['paint_ready'] += $paintActive;
                $metrics['paint_completed'] += $paintComp;
                $metrics['assembly_ready'] += $asmReady;
                $metrics['assembly_completed'] += $asmComp;
            }
        }

        $metrics['completion_pct'] = $metrics['total_required'] > 0
            ? min(100, round(($metrics['total_received'] / $metrics['total_required']) * 100, 1))
            : 0;

        return $this->formatProjectSummaryResult($proj, $metrics);
    }

    /**
     * Compute authoritative dashboard summary across all projects (or filtered project).
     * Guarantees that Dashboard KPI cards equal the sum of active project metrics.
     *
     * @param array $filters ['project_id', 'side', 'supplier_id', 'date_from', 'date_to']
     * @return array
     */
    public function calculateDashboardSummary(array $filters = []): array
    {
        $projectId = !empty($filters['project_id']) ? (int) $filters['project_id'] : null;
        $side = !empty($filters['side']) ? $filters['side'] : null;

        $projectsQuery = Project::query();
        if ($projectId) {
            $projectsQuery->where('id', $projectId);
        } else {
            $projectsQuery->where('status', 'active');
        }

        $projects = $projectsQuery->get();
        $isSingleProject = ($projectId !== null);

        if ($isSingleProject) {
            $singleProj = $projects->first();
            $totalActiveProjects = ($singleProj && $singleProj->status === 'active') ? 1 : 0;
            $completedProjects = ($singleProj && $singleProj->status === 'completed') ? 1 : 0;
            $delayedProjects = 0;
            if ($singleProj && $singleProj->status === 'active') {
                $isDelayed = Project::where('id', $singleProj->id)
                    ->where('created_at', '<', now()->subDays(14))
                    ->whereDoesntHave('bomItems.receiptItems', fn($q) => $q->where('updated_at', '>=', now()->subDays(14)))
                    ->exists();
                $delayedProjects = $isDelayed ? 1 : 0;
            }
        } else {
            $totalActiveProjects = Project::where('status', 'active')->count();
            $completedProjects = Project::where('status', 'completed')->count();
            $delayedProjects = Project::where('status', 'active')
                ->where('created_at', '<', now()->subDays(14))
                ->whereDoesntHave('bomItems.receiptItems', fn($q) => $q->where('updated_at', '>=', now()->subDays(14)))
                ->count();
        }

        $pqQuery = PurchaseQueueItem::query();
        if ($projectId) {
            $pqQuery->where('project_id', $projectId);
        }
        if ($side) {
            $pqQuery->where('side', $side);
        }
        if (!empty($filters['date_from'])) {
            $pqQuery->where('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $pqQuery->where('created_at', '<=', $filters['date_to']);
        }
        $pendingPurchase = (int) $pqQuery->where('status', 'pending_purchase')->count();

        $grandSummary = [
            'total_projects' => $totalActiveProjects,
            'active_projects' => $totalActiveProjects,
            'completed_projects' => $completedProjects,
            'delayed_projects' => $delayedProjects,
            'total_parts' => 0,
            'total_parts_received' => 0,
            'parts_pending' => 0,
            'total_required' => 0,
            'total_received' => 0,
            'raw_received' => 0,
            'excess_received' => 0,
            'total_pending' => 0,
            'pending_store' => 0,
            'parts_in_store' => 0,
            'parts_in_qc' => 0,
            'parts_in_rework' => 0,
            'parts_in_paint' => 0,
            'parts_in_assembly' => 0,
            'awaiting_qc' => 0,
            'qc_approved' => 0,
            'qc_rework' => 0,
            'qc_rejected' => 0,
            'pending_purchase' => $pendingPurchase,
            'paint_pending' => 0,
            'paint_completed' => 0,
            'assembly_pending' => 0,
            'assembly_completed' => 0,
            'completion_pct' => 0,
        ];

        foreach ($projects as $proj) {
            $pMetrics = $this->calculateProjectMetrics($proj, $side, $filters);
            $grandSummary['total_required'] += $pMetrics['required_qty'];
            $grandSummary['total_received'] += $pMetrics['received_qty'];
            $grandSummary['total_parts'] += $pMetrics['required_qty'];
            $grandSummary['total_parts_received'] += $pMetrics['received_qty'];
            $grandSummary['raw_received'] += $pMetrics['raw_received'];
            $grandSummary['excess_received'] += $pMetrics['excess_received'];
            $grandSummary['total_pending'] += $pMetrics['pending_qty'];
            $grandSummary['parts_pending'] += $pMetrics['pending_qty'];
            $grandSummary['pending_store'] += $pMetrics['pending_qty'];
            $grandSummary['parts_in_store'] += $pMetrics['parts_in_store'];
            $grandSummary['parts_in_qc'] += $pMetrics['parts_in_qc'];
            $grandSummary['parts_in_rework'] += $pMetrics['parts_in_rework'];
            $grandSummary['parts_in_paint'] += $pMetrics['parts_in_paint'];
            $grandSummary['parts_in_assembly'] += $pMetrics['parts_in_assembly'];
            $grandSummary['awaiting_qc'] += $pMetrics['awaiting_qc'];
            $grandSummary['qc_approved'] += $pMetrics['approved_qty'];
            $grandSummary['qc_rework'] += $pMetrics['qc_rework'];
            $grandSummary['qc_rejected'] += $pMetrics['rejected_qty'];
            $grandSummary['paint_pending'] += $pMetrics['paint_ready'];
            $grandSummary['paint_completed'] += $pMetrics['paint_qty'];
            $grandSummary['assembly_pending'] += $pMetrics['assembly_ready'];
            $grandSummary['assembly_completed'] += $pMetrics['assembly_qty'];
        }

        $grandSummary['completion_pct'] = $grandSummary['total_required'] > 0
            ? min(100, round(($grandSummary['total_received'] / $grandSummary['total_required']) * 100, 1))
            : 0;

        return $grandSummary;
    }

    /**
     * Compute progress breakdown across all projects for Dashboard Project Progress table.
     *
     * @param array $filters
     * @return Collection
     */
    public function calculateProjectsProgress(array $filters = []): Collection
    {
        $projects = Project::where('status', 'active')
            ->withCount('bomItems')
            ->orderBy('name')
            ->get();
        $side = $filters['side'] ?? null;

        return $projects->map(function ($proj) use ($side, $filters) {
            $pMetrics = $this->calculateProjectMetrics($proj, $side, $filters);

            return [
                'id' => $proj->id,
                'project_code' => $proj->project_code,
                'name' => $proj->name,
                'total_items' => $proj->bom_items_count,
                'required_qty' => $pMetrics['required_qty'],
                'received_qty' => $pMetrics['received_qty'],
                'raw_received' => $pMetrics['raw_received'],
                'excess_received' => $pMetrics['excess_received'],
                'pending_qty' => $pMetrics['pending_qty'],
                'parts_in_store' => $pMetrics['parts_in_store'],
                'parts_in_qc' => $pMetrics['parts_in_qc'],
                'parts_in_rework' => $pMetrics['parts_in_rework'],
                'parts_in_paint' => $pMetrics['parts_in_paint'],
                'parts_in_assembly' => $pMetrics['parts_in_assembly'],
                'approved_qty' => $pMetrics['approved_qty'],
                'rework_qty' => $pMetrics['rework_qty'],
                'rejected_qty' => $pMetrics['rejected_qty'],
                'paint_qty' => $pMetrics['paint_qty'],
                'assembly_qty' => $pMetrics['assembly_qty'],
                'progress_percent' => $pMetrics['progress_percent'],
                'completion_pct' => $pMetrics['completion_pct'],
                'is_complete' => $pMetrics['is_complete'],
            ];
        });
    }

    /**
     * Calculate Top Projects Near Completion for Dashboard.
     * Ranks active projects by completion percentage descending.
     *
     * @param array $filters
     * @param int $limit
     * @return array
     */
    public function getTopProjectsNearCompletion(array $filters = [], int $limit = 10): array
    {
        $progress = $this->calculateProjectsProgress($filters);

        // Filter active incomplete projects with required > 0
        $activeIncomplete = $progress->filter(function ($p) {
            return !$p['is_complete'] && $p['required_qty'] > 0;
        })->sortByDesc('progress_percent')->values();

        $topSubset = $activeIncomplete->take($limit);

        return [
            'labels' => $topSubset->pluck('project_code')->toArray(),
            'names' => $topSubset->map(fn($p) => ($p['project_code'] ?: $p['name']) . ' - ' . $p['name'])->toArray(),
            'percentages' => $topSubset->pluck('progress_percent')->toArray(),
            'required' => $topSubset->pluck('required_qty')->toArray(),
            'received' => $topSubset->pluck('received_qty')->toArray(),
            'pending' => $topSubset->pluck('pending_qty')->toArray(),
            'projects' => $topSubset->toArray(),
            'total_active_incomplete' => $activeIncomplete->count(),
        ];
    }

    /**
     * Calculate Overall Project Health Distribution for Upper Management.
     * Segments active projects into: Near Completion, On Track, At Risk, Delayed.
     *
     * @param array $filters
     * @return array
     */
    public function calculateProjectHealthDistribution(array $filters = []): array
    {
        $projects = Project::where('status', 'active')
            ->with(['bomItems.receiptItems', 'bomItems.qcInspections'])
            ->get();

        $counts = [
            'near_completion' => 0,
            'on_track' => 0,
            'at_risk' => 0,
            'delayed' => 0,
        ];

        $projectsByHealth = [
            'near_completion' => [],
            'on_track' => [],
            'at_risk' => [],
            'delayed' => [],
        ];

        foreach ($projects as $proj) {
            $pMetrics = $this->calculateProjectMetrics($proj, $filters['side'] ?? null, $filters);
            $completion = $pMetrics['completion_pct'];
            $req = $pMetrics['required_qty'];

            if ($req === 0) {
                continue;
            }

            // Check latest activity timestamp across receipts and QC
            $latestReceipt = $proj->bomItems->flatMap->receiptItems->max('updated_at');
            $latestQc = $proj->bomItems->flatMap->qcInspections->max('updated_at');
            $latestActivity = max($latestReceipt, $latestQc, $proj->created_at);

            $daysSinceActivity = $latestActivity ? now()->diffInDays($latestActivity) : 999;

            if ($completion >= 85.0) {
                $category = 'near_completion';
            } elseif ($daysSinceActivity > 14 && $completion < 85.0) {
                $category = 'delayed';
            } elseif ($daysSinceActivity > 7 && $completion < 85.0) {
                $category = 'at_risk';
            } else {
                $category = 'on_track';
            }

            $counts[$category]++;
            $projectsByHealth[$category][] = [
                'id' => $proj->id,
                'project_code' => $proj->project_code,
                'name' => $proj->name,
                'completion_pct' => $completion,
                'days_inactive' => $daysSinceActivity,
                'category' => $category,
            ];
        }

        $totalActive = array_sum($counts);

        return [
            'counts' => $counts,
            'percentages' => [
                'near_completion' => $totalActive > 0 ? round(($counts['near_completion'] / $totalActive) * 100, 1) : 0,
                'on_track' => $totalActive > 0 ? round(($counts['on_track'] / $totalActive) * 100, 1) : 0,
                'at_risk' => $totalActive > 0 ? round(($counts['at_risk'] / $totalActive) * 100, 1) : 0,
                'delayed' => $totalActive > 0 ? round(($counts['delayed'] / $totalActive) * 100, 1) : 0,
            ],
            'total_active' => $totalActive,
            'details' => $projectsByHealth,
        ];
    }

    /**
     * Format standardized project summary result dictionary.
     */
    protected function formatProjectSummaryResult(Project $proj, array $m): array
    {
        $req = $m['total_required'] ?? $m['required_qty'] ?? 0;
        $rec = $m['total_received'] ?? $m['received_qty'] ?? 0;
        $pending = $m['total_pending'] ?? $m['pending_qty'] ?? max(0, $req - $rec);
        $completion = $m['completion_pct'] ?? ($req > 0 ? min(100, round(($rec / $req) * 100, 1)) : 0);
        $asmComp = $m['assembly_completed'] ?? $m['assembly_qty'] ?? 0;
        $isComplete = ($req > 0 && $asmComp >= $req);

        return [
            'id' => $proj->id,
            'name' => $proj->name,
            'project_code' => $proj->project_code,
            'description' => $proj->description,
            'status' => $proj->status,
            'total_items' => $m['total_items'] ?? 0,
            'total_parts' => $req,
            'total_parts_received' => $rec,
            'parts_pending' => $pending,
            'total_required' => $req,
            'total_received' => $rec,
            'required_qty' => $req,
            'received_qty' => $rec,
            'raw_received' => $m['raw_received'] ?? $rec,
            'excess_received' => $m['excess_received'] ?? 0,
            'pending_qty' => $pending,
            'total_pending' => $pending,
            'parts_in_store' => $m['parts_in_store'] ?? 0,
            'parts_in_qc' => $m['parts_in_qc'] ?? $m['awaiting_qc'] ?? 0,
            'parts_in_rework' => $m['parts_in_rework'] ?? $m['rework_pending'] ?? 0,
            'parts_in_paint' => $m['parts_in_paint'] ?? $m['paint_ready'] ?? 0,
            'parts_in_assembly' => $m['parts_in_assembly'] ?? $m['assembly_ready'] ?? 0,
            'awaiting_qc' => $m['awaiting_qc'] ?? 0,
            'approved_qty' => $m['qc_approved'] ?? $m['approved_qty'] ?? 0,
            'rejected_qty' => $m['qc_rejected'] ?? $m['rejected_qty'] ?? 0,
            'qc_rejected' => $m['qc_rejected'] ?? $m['rejected_qty'] ?? 0,
            'rework_qty' => $m['rework_pending'] ?? $m['rework_qty'] ?? 0,
            'qc_rework' => $m['qc_rework'] ?? 0,
            'paint_ready' => $m['paint_ready'] ?? 0,
            'paint_qty' => $m['paint_completed'] ?? $m['paint_qty'] ?? 0,
            'assembly_ready' => $m['assembly_ready'] ?? 0,
            'assembly_qty' => $asmComp,
            'assembly_completed' => $asmComp,
            'progress_percent' => $completion,
            'completion_pct' => $completion,
            'is_complete' => $isComplete,
        ];
    }
}
