<?php

namespace App\Services;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\ReworkRecord;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use App\Services\QuantityCalculationService;
use Illuminate\Support\Facades\DB;

class HierarchyService
{
    public function __construct(
        protected QuantityCalculationService $quantityService = new QuantityCalculationService()
    ) {}

    /**
     * Build unified hierarchy tree for any operational department.
     *
     * @param string $department 'store' | 'qc' | 'rework' | 'paint' | 'assembly' | 'manager'
     * @param int|null $projectId
     * @param array $filters ['side' => 'RH'|'LH', 'search' => '...']
     * @return array
     */
    public function getDepartmentHierarchy(string $department, ?int $projectId = null, array $filters = []): array
    {
        $projects = Project::orderBy('name')->get();
        $activeProjects = $projects->where('status', 'active')->values();
        $completedProjects = $projects->where('status', 'completed')->values();

        $projectsList = $projects->map(function ($proj) use ($department, $filters) {
            return $this->getProjectOverviewStats($proj, $department, $filters);
        });

        if (!$projectId) {
            return [
                'is_hierarchical' => false,
                'projects' => $projectsList,
                'active_projects' => $activeProjects,
                'completed_projects' => $completedProjects,
                'department' => $department,
                'message' => 'Select a project to view hierarchical breakdown.',
            ];
        }

        $project = Project::find($projectId);
        if (!$project) {
            return [
                'is_hierarchical' => false,
                'projects' => $projectsList,
                'active_projects' => $activeProjects,
                'completed_projects' => $completedProjects,
                'department' => $department,
                'message' => 'Project not found.',
            ];
        }

        // Query BOM Items with necessary relations
        $query = BomItem::query()
            ->with(['requirements', 'supplier', 'project'])
            ->where('project_id', $project->id);

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('standard_part_no', 'LIKE', "%{$search}%")
                  ->orWhere('item_no', 'LIKE', "%{$search}%");
            });
        }

        $bomItems = $query->orderBy('standard_part_no')->get();

        if ($bomItems->isEmpty()) {
            return [
                'is_hierarchical' => false,
                'project' => $project,
                'projects' => $projectsList,
                'active_projects' => $activeProjects,
                'completed_projects' => $completedProjects,
                'canonical_summary' => $this->quantityService->calculateProjectMetrics($project, $filters['side'] ?? null, $filters),
                'department' => $department,
                'message' => 'No BOM items found for this project.',
            ];
        }

        $bomItemIds = $bomItems->pluck('id')->toArray();

        // Pre-fetch related operational records in bulk, only valid non-reverted/non-scrapped receipts
        $receiptItemsGrouped = ReceiptItem::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->whereIn('status', QuantityCalculationService::VALID_RECEIPT_STATUSES)
            ->get()
            ->groupBy('bom_item_id');

        $qcInspectionsGrouped = QcInspection::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        $reworkRecordsGrouped = ReworkRecord::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        $paintRecordsGrouped = PaintRecord::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        $assemblyRecordsGrouped = AssemblyRecord::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        $jigsTree = [];

        foreach ($bomItems as $item) {
            $partNo = trim($item->standard_part_no);

            // Read JIG and Unit directly from the authoritative FA-279 BOM fields
            $jigName = !empty($item->jig_no) ? strtoupper(trim($item->jig_no)) : 'GENERAL';
            $rawUnit = !empty($item->unit_no) ? trim($item->unit_no) : '00';
            $unitNo = str_starts_with(strtoupper($rawUnit), 'UNIT') ? $rawUnit : ('Unit ' . $rawUnit);

            $itemReceipts = $receiptItemsGrouped->get($item->id, collect());
            $itemQcInspections = $qcInspectionsGrouped->get($item->id, collect());
            $itemReworks = $reworkRecordsGrouped->get($item->id, collect());
            $itemPaints = $paintRecordsGrouped->get($item->id, collect());
            $itemAssemblies = $assemblyRecordsGrouped->get($item->id, collect());

            // Compute side-specific breakdown
            $sideStats = [];
            $itemMetrics = [
                'total_required' => 0,
                'total_received' => 0,
                'total_pending' => 0,
                'parts_in_store' => 0,
                'parts_in_qc' => 0,
                'qc_pending_arrival' => 0,
                'qc_pending_inspection' => 0,
                'qc_approved' => 0,
                'qc_rejected' => 0,
                'qc_rework' => 0,
                'parts_in_rework' => 0,
                'rework_pending' => 0,
                'rework_in_progress' => 0,
                'rework_completed' => 0,
                'parts_in_paint' => 0,
                'paint_ready' => 0,
                'paint_completed' => 0,
                'parts_in_assembly' => 0,
                'assembly_ready' => 0,
                'assembly_completed' => 0,
            ];

            foreach ($item->requirements as $req) {
                $side = $req->side;

                $recForSide = $itemReceipts->where('side', $side);
                $qcForSide = $itemQcInspections->where('side', $side);
                $reworkForSide = $itemReworks->where('side', $side);
                $paintForSide = $itemPaints->where('side', $side);
                $assemblyForSide = $itemAssemblies->where('side', $side);

                $reqQty = (int) $req->required_quantity;
                $rawRecQty = (int) $recForSide->sum('received_quantity');
                $recQty = min($rawRecQty, $reqQty); // Canonical: capped so required = received + pending
                $pendingQty = max(0, $reqQty - $recQty);

                // QC Stats
                $qcAppPaint = (int) $qcForSide->filter(fn($q) => $q->approved_quantity > 0 && ($q->destination === 'PAINT' || empty($q->destination)))->sum('approved_quantity');
                $qcAppDirectAssembly = (int) $qcForSide->filter(fn($q) => $q->approved_quantity > 0 && $q->destination === 'ASSEMBLY')->sum('approved_quantity');
                $qcApp = $qcAppPaint + $qcAppDirectAssembly;
                $qcRej = (int) $qcForSide->sum('rejected_quantity');
                $qcRew = (int) $qcForSide->sum('rework_quantity');

                // Rework Stats
                $rewComp = (int) $reworkForSide->whereIn('status', ['completed', 'returned_to_qc'])->sum('quantity');
                $rewActive = max(0, $qcRew - $rewComp);

                // Paint Stats - Include all painted records (completed or assembled) so paint never re-acquires assembled parts
                $paintComp = (int) $paintForSide->whereIn('status', ['completed', 'assembled'])->sum('quantity');
                $paintActive = max(0, $qcAppPaint - $paintComp);

                // Assembly Stats
                $asmComp = (int) $assemblyForSide->where('status', 'completed')->sum('quantity');
                $asmReached = $paintComp + $qcAppDirectAssembly;
                $asmReady = max(0, $asmReached - $asmComp);

                // Dispatched to QC (valid quantity that left store for QC)
                $qcDispatchedFromReceipts = (int) $recForSide->whereNotIn('status', ['received', 'returned_to_store'])->sum('received_quantity');
                $qcTotalAccounted = $qcApp + $qcRej + $qcRew;
                $sentToQc = min($recQty, max($qcDispatchedFromReceipts, $qcTotalAccounted));

                // State Transition Ledger (Section 12: Zero-sum conservation)
                $qcResident = max(0, $sentToQc + $rewComp - ($qcApp + $qcRej + $qcRew));
                $storeResident = max(0, $recQty - ($qcResident + $qcRej + $rewActive + $paintActive + $asmReady + $asmComp));
                $qcPendingArrival = (int) $recForSide->whereIn('status', ['received', 'sent_to_qc'])->sum('received_quantity');
                $qcPendingInspection = max(0, (int) $recForSide->where('status', 'qc_received')->sum('received_quantity') + $rewComp - ($qcApp + $qcRej + $qcRew));

                // Determine Part Status Badge
                if ($reqQty > 0 && $asmComp >= $reqQty) {
                    $statusBadge = 'Done';
                    $statusColor = 'success';
                } elseif ($asmReady > 0) {
                    $statusBadge = 'Assembly';
                    $statusColor = 'pink';
                } elseif ($paintActive > 0) {
                    $statusBadge = 'Paint';
                    $statusColor = 'purple';
                } elseif ($rewActive > 0) {
                    $statusBadge = 'Rework';
                    $statusColor = 'danger';
                } elseif ($qcResident > 0) {
                    $statusBadge = 'QC';
                    $statusColor = 'info';
                } elseif ($qcRej > 0 && $storeResident === 0) {
                    $statusBadge = 'QC (Rejected)';
                    $statusColor = 'danger';
                } elseif ($storeResident > 0) {
                    $statusBadge = 'Store';
                    $statusColor = 'warning';
                } else {
                    $statusBadge = 'Pending';
                    $statusColor = 'secondary';
                }

                $sideStats[$side] = [
                    'side' => $side,
                    'required' => $reqQty,
                    'received' => $recQty,
                    'pending' => $pendingQty,
                    'parts_in_store' => $storeResident,
                    'parts_in_qc' => $qcResident,
                    'qc_pending_arrival' => $qcPendingArrival,
                    'qc_pending_inspection' => $qcPendingInspection,
                    'qc_approved' => $qcApp,
                    'qc_rejected' => $qcRej,
                    'qc_rework' => $qcRew,
                    'parts_in_rework' => $rewActive,
                    'rework_pending' => $rewActive,
                    'rework_in_progress' => (int) $reworkForSide->where('status', 'in_progress')->sum('quantity'),
                    'rework_completed' => $rewComp,
                    'parts_in_paint' => $paintActive,
                    'paint_ready' => $paintActive,
                    'paint_completed' => $paintComp,
                    'parts_in_assembly' => $asmReady,
                    'assembly_ready' => $asmReady,
                    'assembly_completed' => $asmComp,
                    'receipt_items' => $recForSide->values(),
                    'qc_inspections' => $qcForSide->values(),
                    'rework_records' => $reworkForSide->values(),
                    'paint_records' => $paintForSide->values(),
                    'assembly_records' => $assemblyForSide->values(),
                    'status_badge' => $statusBadge,
                    'status_color' => $statusColor,
                    'is_done' => ($reqQty > 0 && $asmComp >= $reqQty),
                ];

                // Accumulate into item metrics
                if (empty($filters['side']) || $filters['side'] === $side || $side === 'COMMON') {
                    $itemMetrics['total_required'] += $reqQty;
                    $itemMetrics['total_received'] += $recQty;
                    $itemMetrics['total_pending'] += $pendingQty;
                    $itemMetrics['parts_in_store'] += $storeResident;
                    $itemMetrics['parts_in_qc'] += $qcResident;
                    $itemMetrics['qc_pending_arrival'] += $qcPendingArrival;
                    $itemMetrics['qc_pending_inspection'] += $qcPendingInspection;
                    $itemMetrics['qc_approved'] += $qcApp;
                    $itemMetrics['qc_rejected'] += $qcRej;
                    $itemMetrics['qc_rework'] += $qcRew;
                    $itemMetrics['parts_in_rework'] += $rewActive;
                    $itemMetrics['rework_pending'] += $rewActive;
                    $itemMetrics['rework_in_progress'] += (int) $reworkForSide->where('status', 'in_progress')->sum('quantity');
                    $itemMetrics['rework_completed'] += $rewComp;
                    $itemMetrics['parts_in_paint'] += $paintActive;
                    $itemMetrics['paint_ready'] += $paintActive;
                    $itemMetrics['paint_completed'] += $paintComp;
                    $itemMetrics['parts_in_assembly'] += $asmReady;
                    $itemMetrics['assembly_ready'] += $asmReady;
                    $itemMetrics['assembly_completed'] += $asmComp;
                }
            }

            // If side filter is active and this item has no requirements for that side, skip
            if (!empty($filters['side']) && !isset($sideStats[$filters['side']]) && !isset($sideStats['COMMON'])) {
                continue;
            }

            $item->side_stats = $sideStats;
            $item->metrics = $itemMetrics;
            $item->receipt_items = $itemReceipts->values();
            $item->qc_inspections = $itemQcInspections->values();
            $item->paint_records = $itemPaints->values();
            $item->rework_records = $itemReworks->values();
            $item->assembly_records = $itemAssemblies->values();
            $item->is_done = ($itemMetrics['total_required'] > 0 && $itemMetrics['assembly_completed'] >= $itemMetrics['total_required']);

            // Group into JIG and Unit structure
            if (!isset($jigsTree[$jigName])) {
                $jigsTree[$jigName] = [
                    'jig_name' => $jigName,
                    'total_required' => 0,
                    'total_received' => 0,
                    'total_pending' => 0,
                    'total_parts' => 0,
                    'complete_units' => 0,
                    'total_units' => 0,
                    'is_complete' => false,
                    'completion_pct' => 0,
                    'metrics' => $this->initZeroMetrics(),
                    'units' => [],
                ];
            }

            if (!isset($jigsTree[$jigName]['units'][$unitNo])) {
                $jigsTree[$jigName]['units'][$unitNo] = [
                    'unit_no' => $unitNo,
                    'jig_name' => $jigName,
                    'total_required' => 0,
                    'total_received' => 0,
                    'total_pending' => 0,
                    'total_parts' => 0,
                    'is_complete' => false,
                    'completion_pct' => 0,
                    'metrics' => $this->initZeroMetrics(),
                    'parts' => [],
                ];
            }

            $jigsTree[$jigName]['units'][$unitNo]['parts'][] = $item;
            $jigsTree[$jigName]['units'][$unitNo]['total_parts']++;
            $jigsTree[$jigName]['units'][$unitNo]['total_required'] += $itemMetrics['total_required'];
            $jigsTree[$jigName]['units'][$unitNo]['total_received'] += $itemMetrics['total_received'];
            $jigsTree[$jigName]['units'][$unitNo]['total_pending'] += $itemMetrics['total_pending'];
            $this->accumulateMetrics($jigsTree[$jigName]['units'][$unitNo]['metrics'], $itemMetrics);

            $jigsTree[$jigName]['total_parts']++;
            $jigsTree[$jigName]['total_required'] += $itemMetrics['total_required'];
            $jigsTree[$jigName]['total_received'] += $itemMetrics['total_received'];
            $jigsTree[$jigName]['total_pending'] += $itemMetrics['total_pending'];
            $this->accumulateMetrics($jigsTree[$jigName]['metrics'], $itemMetrics);
        }

        // Format and compute percentage completions per Unit and JIG
        $formattedJigs = [];

        foreach ($jigsTree as $jigName => $jigData) {
            $formattedUnits = [];
            $completeUnitsCount = 0;

            foreach ($jigData['units'] as $unitNo => $unitData) {
                if (empty($unitData['parts'])) {
                    continue;
                }

                $req = $unitData['total_required'];
                $rec = $unitData['total_received'];
                $unitData['pending_quantity'] = max(0, $req - $rec);

                // Compute dedicated LH and RH side breakdowns (COMMON parts included in both)
                $lhParts = [];
                $rhParts = [];
                $lhMetrics = $this->initZeroMetrics();
                $rhMetrics = $this->initZeroMetrics();
                $lhRequired = 0; $lhReceived = 0; $lhPending = 0; $lhAsmComp = 0;
                $rhRequired = 0; $rhReceived = 0; $rhPending = 0; $rhAsmComp = 0;

                foreach ($unitData['parts'] as $part) {
                    $hasLh = isset($part->side_stats['LH']) || isset($part->side_stats['COMMON']);
                    $hasRh = isset($part->side_stats['RH']) || isset($part->side_stats['COMMON']);

                    if ($hasLh) {
                        $st = $part->side_stats['LH'] ?? $part->side_stats['COMMON'];
                        $lhParts[] = [
                            'id' => $part->id,
                            'standard_part_no' => $part->standard_part_no,
                            'item_no' => $part->item_no ?? '—',
                            'supplier' => $part->supplier?->name ?? ($part->supplier_name_raw ?? '—'),
                            'side' => isset($part->side_stats['LH']) ? 'LH' : 'COMMON',
                            'required_qty' => $st['required'] ?? 0,
                            'received_qty' => $st['received'] ?? 0,
                            'pending_qty' => $st['pending'] ?? 0,
                            'status_badge' => $st['status_badge'] ?? 'Pending',
                            'status_color' => $st['status_color'] ?? 'secondary',
                            'is_done' => $st['is_done'] ?? false,
                        ];
                        $lhRequired += $st['required'] ?? 0;
                        $lhReceived += $st['received'] ?? 0;
                        $lhPending += $st['pending'] ?? 0;
                        $lhAsmComp += $st['assembly_completed'] ?? 0;
                        $this->accumulateMetrics($lhMetrics, $st);
                    }
                    if ($hasRh) {
                        $st = $part->side_stats['RH'] ?? $part->side_stats['COMMON'];
                        $rhParts[] = [
                            'id' => $part->id,
                            'standard_part_no' => $part->standard_part_no,
                            'item_no' => $part->item_no ?? '—',
                            'supplier' => $part->supplier?->name ?? ($part->supplier_name_raw ?? '—'),
                            'side' => isset($part->side_stats['RH']) ? 'RH' : 'COMMON',
                            'required_qty' => $st['required'] ?? 0,
                            'received_qty' => $st['received'] ?? 0,
                            'pending_qty' => $st['pending'] ?? 0,
                            'status_badge' => $st['status_badge'] ?? 'Pending',
                            'status_color' => $st['status_color'] ?? 'secondary',
                            'is_done' => $st['is_done'] ?? false,
                        ];
                        $rhRequired += $st['required'] ?? 0;
                        $rhReceived += $st['received'] ?? 0;
                        $rhPending += $st['pending'] ?? 0;
                        $rhAsmComp += $st['assembly_completed'] ?? 0;
                        $this->accumulateMetrics($rhMetrics, $st);
                    }
                }

                $lhCompletionPct = match ($department) {
                    'store' => ($lhRequired > 0 ? min(100, round(($lhReceived / $lhRequired) * 100, 1)) : 100),
                    'qc' => ($lhRequired > 0 ? min(100, round(($lhMetrics['qc_approved'] / $lhRequired) * 100, 1)) : 100),
                    'rework' => ($lhMetrics['qc_rework'] > 0 ? min(100, round(($lhMetrics['rework_completed'] / $lhMetrics['qc_rework']) * 100, 1)) : 100),
                    'paint' => ($lhRequired > 0 ? min(100, round(($lhMetrics['paint_completed'] / $lhRequired) * 100, 1)) : 100),
                    default => ($lhRequired > 0 ? min(100, round(($lhAsmComp / $lhRequired) * 100, 1)) : 100),
                };

                $rhCompletionPct = match ($department) {
                    'store' => ($rhRequired > 0 ? min(100, round(($rhReceived / $rhRequired) * 100, 1)) : 100),
                    'qc' => ($rhRequired > 0 ? min(100, round(($rhMetrics['qc_approved'] / $rhRequired) * 100, 1)) : 100),
                    'rework' => ($rhMetrics['qc_rework'] > 0 ? min(100, round(($rhMetrics['rework_completed'] / $rhMetrics['qc_rework']) * 100, 1)) : 100),
                    'paint' => ($rhRequired > 0 ? min(100, round(($rhMetrics['paint_completed'] / $rhRequired) * 100, 1)) : 100),
                    default => ($rhRequired > 0 ? min(100, round(($rhAsmComp / $rhRequired) * 100, 1)) : 100),
                };

                $lhIsComplete = ($lhRequired > 0 && $lhAsmComp >= $lhRequired);
                $rhIsComplete = ($rhRequired > 0 && $rhAsmComp >= $rhRequired);

                // Section 10: Unit is complete only when both required sides are complete!
                $unitIsComplete = false;
                if ($lhRequired > 0 && $rhRequired > 0) {
                    $unitIsComplete = ($lhIsComplete && $rhIsComplete);
                } elseif ($lhRequired > 0) {
                    $unitIsComplete = $lhIsComplete;
                } elseif ($rhRequired > 0) {
                    $unitIsComplete = $rhIsComplete;
                }

                $unitData['sides'] = [
                    'LH' => [
                        'side' => 'LH',
                        'total_parts' => count($lhParts),
                        'total_required' => $lhRequired,
                        'total_received' => $lhReceived,
                        'pending_quantity' => $lhPending,
                        'assembly_completed' => $lhAsmComp,
                        'completion_pct' => $lhCompletionPct,
                        'is_complete' => $lhIsComplete,
                        'parts' => $lhParts,
                        'metrics' => $lhMetrics,
                    ],
                    'RH' => [
                        'side' => 'RH',
                        'total_parts' => count($rhParts),
                        'total_required' => $rhRequired,
                        'total_received' => $rhReceived,
                        'pending_quantity' => $rhPending,
                        'assembly_completed' => $rhAsmComp,
                        'completion_pct' => $rhCompletionPct,
                        'is_complete' => $rhIsComplete,
                        'parts' => $rhParts,
                        'metrics' => $rhMetrics,
                    ],
                ];

                $unitData['completion_pct'] = match ($department) {
                    'store' => ($req > 0 ? min(100, round(($rec / $req) * 100, 1)) : 100),
                    'qc' => ($req > 0 ? min(100, round(($unitData['metrics']['qc_approved'] / $req) * 100, 1)) : 100),
                    'rework' => ($unitData['metrics']['qc_rework'] > 0 ? min(100, round(($unitData['metrics']['rework_completed'] / $unitData['metrics']['qc_rework']) * 100, 1)) : 100),
                    'paint' => ($req > 0 ? min(100, round(($unitData['metrics']['paint_completed'] / $req) * 100, 1)) : 100),
                    default => ($req > 0 ? min(100, round(($unitData['metrics']['assembly_completed'] / $req) * 100, 1)) : 100),
                };
                $unitData['is_complete'] = $unitIsComplete;

                if ($unitData['is_complete']) {
                    $completeUnitsCount++;
                }

                $formattedUnits[] = $unitData;
            }

            if (empty($formattedUnits)) {
                continue;
            }

            usort($formattedUnits, fn($a, $b) => strcmp($a['unit_no'], $b['unit_no']));

            $totalUnitsCount = count($formattedUnits);
            $jigData['complete_units'] = $completeUnitsCount;
            $jigData['total_units'] = $totalUnitsCount;
            // Section 10: Jig turns green only when ALL units in it are complete
            $jigData['is_complete'] = ($totalUnitsCount > 0 && $completeUnitsCount === $totalUnitsCount);

            $jigReq = $jigData['total_required'];
            $jigRec = $jigData['total_received'];

            $jigData['completion_pct'] = match ($department) {
                'store' => ($jigReq > 0 ? min(100, round(($jigRec / $jigReq) * 100, 1)) : 100),
                'qc' => ($jigReq > 0 ? min(100, round(($jigData['metrics']['qc_approved'] / $jigReq) * 100, 1)) : 100),
                'rework' => ($jigData['metrics']['qc_rework'] > 0 ? min(100, round(($jigData['metrics']['rework_completed'] / $jigData['metrics']['qc_rework']) * 100, 1)) : 100),
                'paint' => ($jigReq > 0 ? min(100, round(($jigData['metrics']['paint_completed'] / $jigReq) * 100, 1)) : 100),
                default => ($jigReq > 0 ? min(100, round(($jigData['metrics']['assembly_completed'] / $jigReq) * 100, 1)) : 100),
            };

            $jigData['units'] = $formattedUnits;
            $formattedJigs[] = $jigData;
        }

        // Section 3: Ordering Jigs - Incomplete Jigs first, Completed Jigs at bottom
        usort($formattedJigs, function ($a, $b) {
            if ($a['is_complete'] !== $b['is_complete']) {
                return $a['is_complete'] ? 1 : -1;
            }
            return strcmp($a['jig_name'], $b['jig_name']);
        });

        $allProjects = Project::orderBy('name')->get();
        $activeProjects = $allProjects->where('status', 'active')->values();
        $completedProjects = $allProjects->where('status', 'completed')->values();

        return [
            'is_hierarchical' => count($formattedJigs) > 0,
            'department' => $department,
            'project' => $project,
            'canonical_summary' => $project ? $this->quantityService->calculateProjectMetrics($project, $filters['side'] ?? null, $filters) : null,
            'jigs' => $formattedJigs,
            'active_projects' => $activeProjects,
            'completed_projects' => $completedProjects,
            'total_jigs' => count($formattedJigs),
            'completed_jigs' => count(array_filter($formattedJigs, fn($j) => $j['is_complete'])),
            'message' => count($formattedJigs) === 0 ? "No BOM hierarchy found for this project." : null,
        ];
    }

    /**
     * Get high level progress stats for Project level cards
     */
    protected function getProjectOverviewStats(Project $proj, string $department, array $filters = []): array
    {
        $side = $filters['side'] ?? null;
        $m = $this->quantityService->calculateProjectMetrics($proj, $side, $filters);

        $reqSum = $m['required_qty'];
        $recSum = $m['received_qty'];
        $appSum = $m['approved_qty'];
        $paintCompSum = $m['paint_qty'];
        $asmCompSum = $m['assembly_qty'];
        $rewActiveSum = $m['rework_qty'];
        $rewCompSum = $m['rework_completed'] ?? 0;
        $qcPendingSum = $m['awaiting_qc'];

        $paintReadySum = $m['paint_ready'];
        $asmReadySum = $m['assembly_ready'];

        $eligibleCount = match ($department) {
            'store' => $m['pending_qty'],
            'qc' => $qcPendingSum,
            'rework' => $rewActiveSum,
            'paint' => $paintReadySum,
            'assembly' => $asmReadySum,
            default => $reqSum,
        };

        $progressPercent = match ($department) {
            'store' => ($reqSum > 0 ? min(100, round(($recSum / $reqSum) * 100, 1)) : 100),
            'qc' => ($reqSum > 0 ? min(100, round(($appSum / $reqSum) * 100, 1)) : 100),
            'rework' => ($rewCompSum + $rewActiveSum > 0 ? min(100, round(($rewCompSum / ($rewCompSum + $rewActiveSum)) * 100, 1)) : 100),
            'paint' => ($reqSum > 0 ? min(100, round(($paintCompSum / $reqSum) * 100, 1)) : 100),
            'assembly' => ($reqSum > 0 ? min(100, round(($asmCompSum / $reqSum) * 100, 1)) : 100),
            default => ($reqSum > 0 ? min(100, round(($asmCompSum / $reqSum) * 100, 1)) : 100),
        };

        return [
            'id' => $proj->id,
            'name' => $proj->name,
            'project_code' => $proj->project_code,
            'description' => $proj->description,
            'status' => $proj->status,
            'total_items' => $m['total_items'] ?? 0,
            'total_required' => $reqSum,
            'total_received' => $recSum,
            'required_qty' => $reqSum,
            'received_qty' => $recSum,
            'raw_received' => $m['raw_received'] ?? $recSum,
            'excess_received' => $m['excess_received'] ?? 0,
            'pending_qty' => $m['pending_qty'],
            'approved_qty' => $appSum,
            'paint_qty' => $paintCompSum,
            'assembly_qty' => $asmCompSum,
            'eligible_qty' => $eligibleCount,
            'has_eligible_parts' => ($department === 'store' || $department === 'manager' || $eligibleCount > 0 || $progressPercent >= 100),
            'progress_percent' => min(100, $progressPercent),
            'completion_pct' => min(100, $progressPercent),
            'is_complete' => ($progressPercent >= 100),
        ];
    }

    protected function initZeroMetrics(): array
    {
        return [
            'total_required' => 0,
            'total_received' => 0,
            'total_pending' => 0,
            'qc_pending_arrival' => 0,
            'qc_pending_inspection' => 0,
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
        ];
    }

    protected function accumulateMetrics(array &$target, array $source): void
    {
        foreach ($source as $k => $v) {
            if (isset($target[$k])) {
                $target[$k] += (int) $v;
            }
        }
    }
}
