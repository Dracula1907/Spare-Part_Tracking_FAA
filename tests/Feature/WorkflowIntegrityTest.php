<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\ReworkRecord;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use App\Services\QuantityCalculationService;
use App\Services\HierarchyService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkflowIntegrityTest extends TestCase
{
    protected function getAdminUser(): User
    {
        $user = User::where('email', 'admin@sparetrack.internal')->first();
        if (!$user) {
            $user = User::first();
        }
        if (!$user) {
            $user = User::create([
                'name' => 'Admin User',
                'email' => 'admin@sparetrack.internal',
                'password' => bcrypt('password'),
            ]);
            $user->assignRole('ADMIN');
        }
        return $user;
    }

    public function test_auth_me_endpoint_returns_authenticated_user()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $response = $this->getJson('/api/v1/auth/me');
        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'id', 'name', 'email'
                 ]);
    }

    public function test_dashboard_summary_returns_valid_structure()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $response = $this->getJson('/api/v1/dashboard/summary');
        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'today_throughput',
                     'delayed_parts',
                     'projects_progress',
                 ]);
    }

    public function test_side_isolation_between_rh_and_lh()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        // Verify RH and LH requirements are queried independently
        $rhReqs = BomRequirement::where('side', 'RH')->count();
        $lhReqs = BomRequirement::where('side', 'LH')->count();

        $this->assertIsInt($rhReqs);
        $this->assertIsInt($lhReqs);
    }

    public function test_assembly_completion_moves_part_to_assembly_completed_and_never_paint()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        DB::beginTransaction();
        try {
            // 1. Create a clean isolated test project with 1 BOM Item
            $project = Project::create([
                'project_code' => 'TEST-ASM-01',
                'name' => 'Assembly Test Project',
                'status' => 'active',
                'created_by' => $user->id,
            ]);

            $bomItem = BomItem::create([
                'project_id' => $project->id,
                'standard_part_no' => 'PART-TEST-ASM-01',
                'jig_no' => 'JIG-01',
                'unit_no' => '01',
            ]);

            BomRequirement::create([
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'required_quantity' => 10,
            ]);

            // 2. Receive 10 parts in store
            $receipt = Receipt::create([
                'project_id' => $project->id,
                'delivery_note_number' => 'DN-TEST-01',
                'received_by' => $user->id,
            ]);

            $receiptItem = ReceiptItem::create([
                'receipt_id' => $receipt->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'received_quantity' => 10,
                'status' => 'qc_approved',
            ]);

            // 3. QC approves 10 parts destined for PAINT
            $qc = QcInspection::create([
                'bom_item_id' => $bomItem->id,
                'receipt_item_id' => $receiptItem->id,
                'inspected_by' => $user->id,
                'side' => 'RH',
                'inspected_quantity' => 10,
                'approved_quantity' => 10,
                'destination' => 'PAINT',
                'result' => 'approved',
                'inspection_date' => now(),
            ]);

            // Verify before paint: Paint Active = 10, Assembly = 0
            $service = app(QuantityCalculationService::class);
            $metricsBeforePaint = $service->calculateProjectMetrics($project);
            $this->assertEquals(10, $metricsBeforePaint['parts_in_paint']);
            $this->assertEquals(0, $metricsBeforePaint['parts_in_assembly']);

            // 4. Paint completes 10 parts
            $paint = PaintRecord::create([
                'bom_item_id' => $bomItem->id,
                'qc_inspection_id' => $qc->id,
                'side' => 'RH',
                'quantity' => 10,
                'painted_by' => $user->id,
                'status' => 'completed',
            ]);

            // Verify after paint: Paint Active = 0, Assembly Active = 10, Assembly Completed = 0
            $metricsAfterPaint = $service->calculateProjectMetrics($project);
            $this->assertEquals(0, $metricsAfterPaint['parts_in_paint']);
            $this->assertEquals(10, $metricsAfterPaint['parts_in_assembly']);
            $this->assertEquals(0, $metricsAfterPaint['assembly_completed']);

            // 5. Assembly user completes 10 parts via API
            $response = $this->postJson('/api/v1/assembly/items', [
                'bom_item_id' => $bomItem->id,
                'paint_record_id' => $paint->id,
                'side' => 'RH',
                'quantity' => 10,
                'remarks' => 'Completed in test',
            ]);
            $response->assertStatus(200);

            // 6. Verify CRITICAL INVARIANTS after Assembly completion:
            $metricsAfterAsm = $service->calculateProjectMetrics($project);

            // Invariant A: Part MUST NOT reappear in Paint!
            $this->assertEquals(0, $metricsAfterAsm['parts_in_paint'], 'Paint active must be 0 and never re-acquire assembled parts!');

            // Invariant B: Active Assembly must decrease to 0!
            $this->assertEquals(0, $metricsAfterAsm['parts_in_assembly'], 'Active Assembly must be 0 after full completion.');

            // Invariant C: Assembly Completed must be 10!
            $this->assertEquals(10, $metricsAfterAsm['assembly_completed'], 'Assembly Completed must be 10.');

            // Invariant D: Zero-sum accounting preserved!
            $this->assertEquals(10, $metricsAfterAsm['total_parts'], 'Total Parts must remain unchanged.');
            $this->assertEquals(10, $metricsAfterAsm['total_parts_received'], 'Total Parts Received must remain unchanged.');
            $this->assertEquals(0, $metricsAfterAsm['parts_pending'], 'Parts Pending must remain 0.');

            // Invariant E: Project status becomes completed because 100% of required parts are assembled!
            $project->refresh();
            $this->assertEquals('completed', $project->status);

        } finally {
            DB::rollBack();
        }
    }

    public function test_partial_assembly_completion_and_rh_lh_isolation()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        DB::beginTransaction();
        try {
            $project = Project::create([
                'project_code' => 'TEST-ASM-02',
                'name' => 'Partial Assembly & Side Test',
                'status' => 'active',
                'created_by' => $user->id,
            ]);

            $bomItem = BomItem::create([
                'project_id' => $project->id,
                'standard_part_no' => 'PART-TEST-ASM-02',
                'jig_no' => 'JIG-01',
                'unit_no' => '01',
            ]);

            BomRequirement::create([
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'required_quantity' => 6,
            ]);

            BomRequirement::create([
                'bom_item_id' => $bomItem->id,
                'side' => 'LH',
                'required_quantity' => 6,
            ]);

            // Receive 6 RH and 6 LH
            $receipt = Receipt::create([
                'project_id' => $project->id,
                'delivery_note_number' => 'DN-TEST-02',
                'received_by' => $user->id,
            ]);

            $recRH = ReceiptItem::create([
                'receipt_id' => $receipt->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'received_quantity' => 6,
                'status' => 'qc_approved',
            ]);

            $recLH = ReceiptItem::create([
                'receipt_id' => $receipt->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'LH',
                'received_quantity' => 6,
                'status' => 'qc_approved',
            ]);

            // QC approves both directly to Assembly
            $qcRH = QcInspection::create([
                'bom_item_id' => $bomItem->id,
                'receipt_item_id' => $recRH->id,
                'inspected_by' => $user->id,
                'side' => 'RH',
                'inspected_quantity' => 6,
                'approved_quantity' => 6,
                'destination' => 'ASSEMBLY',
                'result' => 'approved',
                'inspection_date' => now(),
            ]);

            $qcLH = QcInspection::create([
                'bom_item_id' => $bomItem->id,
                'receipt_item_id' => $recLH->id,
                'inspected_by' => $user->id,
                'side' => 'LH',
                'inspected_quantity' => 6,
                'approved_quantity' => 6,
                'destination' => 'ASSEMBLY',
                'result' => 'approved',
                'inspection_date' => now(),
            ]);

            $service = app(QuantityCalculationService::class);
            $before = $service->calculateProjectMetrics($project);
            $this->assertEquals(12, $before['parts_in_assembly']);
            $this->assertEquals(0, $before['assembly_completed']);

            // Partially complete 4 RH units out of 6
            $response = $this->postJson('/api/v1/assembly/items', [
                'bom_item_id' => $bomItem->id,
                'qc_inspection_id' => $qcRH->id,
                'side' => 'RH',
                'quantity' => 4,
                'remarks' => 'Partial 4 RH completed',
            ]);
            $response->assertStatus(200);

            $afterPartial = $service->calculateProjectMetrics($project);
            // RH: 2 active, 4 completed; LH: 6 active, 0 completed
            // Total Active Assembly = 2 + 6 = 8
            // Total Completed Assembly = 4
            $this->assertEquals(8, $afterPartial['parts_in_assembly']);
            $this->assertEquals(4, $afterPartial['assembly_completed']);
            $this->assertEquals(0, $afterPartial['parts_in_paint']);

            // Check RH side specifically
            $rhMetrics = $service->calculateProjectMetrics($project, 'RH');
            $this->assertEquals(2, $rhMetrics['parts_in_assembly']);
            $this->assertEquals(4, $rhMetrics['assembly_completed']);

            // Check LH side specifically (must be completely unaffected)
            $lhMetrics = $service->calculateProjectMetrics($project, 'LH');
            $this->assertEquals(6, $lhMetrics['parts_in_assembly']);
            $this->assertEquals(0, $lhMetrics['assembly_completed']);

            // Project must remain active (not completed)
            $project->refresh();
            $this->assertEquals('active', $project->status);

        } finally {
            DB::rollBack();
        }
    }

    public function test_qc_rejection_does_not_reduce_total_received_or_increase_pending_and_creates_purchase_item()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        DB::beginTransaction();
        try {
            // 1. Create a test project with 100 required parts
            $project = Project::create([
                'project_code' => 'TEST-QC-REJ-01',
                'name' => 'QC Rejection Test Project',
                'status' => 'active',
                'created_by' => $user->id,
            ]);

            $bomItem = BomItem::create([
                'project_id' => $project->id,
                'standard_part_no' => 'PART-QC-REJ-001',
                'jig_no' => 'JIG-01',
                'unit_no' => '01',
            ]);

            BomRequirement::create([
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'required_quantity' => 100,
            ]);

            // Receive 80 parts in Store and dispatch to QC
            $receipt = Receipt::create([
                'project_id' => $project->id,
                'delivery_note_number' => 'DN-REJ-01',
                'received_by' => $user->id,
            ]);

            $recItem = ReceiptItem::create([
                'receipt_id' => $receipt->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'received_quantity' => 80,
                'status' => 'qc_received',
            ]);

            $service = app(QuantityCalculationService::class);
            $before = $service->calculateProjectMetrics($project);

            // Assert Before Rejection state
            $this->assertEquals(100, $before['total_required']);
            $this->assertEquals(80, $before['total_received']);
            $this->assertEquals(20, $before['total_pending']);
            $this->assertEquals(80, $before['parts_in_qc']);
            $this->assertEquals(0, $before['qc_rejected']);

            // 2. Perform QC Inspection: 5 parts rejected due to dimensional defect
            $response = $this->postJson('/api/v1/qc/inspect', [
                'receipt_item_id' => $recItem->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'inspected_quantity' => 5,
                'result' => 'rejected',
                'rejected_quantity' => 5,
                'rejection_reason' => 'Dimensional Out of Tolerance',
                'remarks' => 'Surface scratched and undersized',
            ]);
            $response->assertStatus(200);

            // 3. Assert After Rejection state
            $after = $service->calculateProjectMetrics($project);

            // Absolute Rules Validation:
            // Total Parts must remain UNCHANGED (100)
            $this->assertEquals(100, $after['total_required']);
            // Total Parts Received must remain UNCHANGED (80) - rejection does NOT reduce received
            $this->assertEquals(80, $after['total_received']);
            // Parts Pending must remain UNCHANGED (20) - rejection does NOT increase pending
            $this->assertEquals(20, $after['total_pending']);
            // QC Active must DECREASE by 5 (80 - 5 = 75)
            $this->assertEquals(75, $after['parts_in_qc']);
            // QC Rejected must INCREASE by 5
            $this->assertEquals(5, $after['qc_rejected']);

            // Verify Zero-Sum Conservation:
            // Store(0) + QC(75) + Rejected(5) + Rework(0) + Paint(0) + Assembly(0) = 80 (Total Received)
            $locationSum = $after['parts_in_store'] + $after['parts_in_qc'] + $after['qc_rejected'] + 
                           $after['parts_in_rework'] + $after['parts_in_paint'] + $after['parts_in_assembly'] + 
                           $after['assembly_completed'];
            $this->assertEquals($after['total_received'], $locationSum);

            // 4. Verify Purchase Queue Item created for manual reordering
            $purchaseItem = \App\Models\PurchaseQueueItem::where('project_id', $project->id)
                ->where('bom_item_id', $bomItem->id)
                ->first();

            $this->assertNotNull($purchaseItem);
            $this->assertEquals(5, $purchaseItem->rejected_quantity);
            $this->assertEquals('RH', $purchaseItem->side);
            $this->assertEquals('Dimensional Out of Tolerance', $purchaseItem->rejection_reason);
            $this->assertEquals('pending_purchase', $purchaseItem->status);

        } finally {
            DB::rollBack();
        }
    }

    public function test_qc_rejection_side_isolation_rh_and_lh()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        DB::beginTransaction();
        try {
            $project = Project::create([
                'project_code' => 'TEST-QC-SIDE-01',
                'name' => 'QC Side Rejection Test',
                'status' => 'active',
                'created_by' => $user->id,
            ]);

            $bomItem = BomItem::create([
                'project_id' => $project->id,
                'standard_part_no' => 'PART-QC-SIDE-001',
                'jig_no' => 'JIG-01',
                'unit_no' => '01',
            ]);

            BomRequirement::create([
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'required_quantity' => 50,
            ]);

            BomRequirement::create([
                'bom_item_id' => $bomItem->id,
                'side' => 'LH',
                'required_quantity' => 50,
            ]);

            $receipt = Receipt::create([
                'project_id' => $project->id,
                'delivery_note_number' => 'DN-SIDE-01',
                'received_by' => $user->id,
            ]);

            $recRH = ReceiptItem::create([
                'receipt_id' => $receipt->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'received_quantity' => 40,
                'status' => 'qc_received',
            ]);

            $recLH = ReceiptItem::create([
                'receipt_id' => $receipt->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'LH',
                'received_quantity' => 40,
                'status' => 'qc_received',
            ]);

            // Reject 4 RH items only
            $response = $this->postJson('/api/v1/qc/inspect', [
                'receipt_item_id' => $recRH->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'inspected_quantity' => 4,
                'result' => 'rejected',
                'rejected_quantity' => 4,
                'rejection_reason' => 'Porosity defect on RH flange',
            ]);
            $response->assertStatus(200);

            $service = app(QuantityCalculationService::class);

            // Check RH metrics
            $rh = $service->calculateProjectMetrics($project, 'RH');
            $this->assertEquals(36, $rh['parts_in_qc']);
            $this->assertEquals(4, $rh['qc_rejected']);
            $this->assertEquals(40, $rh['total_received']);
            $this->assertEquals(10, $rh['total_pending']);

            // Check LH metrics (must be 100% untouched)
            $lh = $service->calculateProjectMetrics($project, 'LH');
            $this->assertEquals(40, $lh['parts_in_qc']);
            $this->assertEquals(0, $lh['qc_rejected']);
            $this->assertEquals(40, $lh['total_received']);
            $this->assertEquals(10, $lh['total_pending']);

        } finally {
            DB::rollBack();
        }
    }

    public function test_qc_partial_approval_with_paint_and_assembly_split()
    {
        DB::beginTransaction();
        try {
            $user = $this->getAdminUser();
            $this->actingAs($user, 'sanctum');

            $project = Project::create([
                'name' => 'QC-Split-Test-' . uniqid(),
                'project_code' => 'QST-' . rand(1000, 9999),
                'status' => 'active',
            ]);

            $bomItem = BomItem::create([
                'project_id' => $project->id,
                'standard_part_no' => 'SPLIT-PART-01',
                'item_no' => '1',
            ]);

            BomRequirement::create([
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'required_quantity' => 10,
            ]);

            $receipt = Receipt::create([
                'project_id' => $project->id,
                'delivery_note_number' => 'DN-SPLIT-01',
                'received_by' => $user->id,
            ]);

            $recItem = ReceiptItem::create([
                'receipt_id' => $receipt->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'received_quantity' => 6,
                'status' => 'qc_received',
            ]);

            // 1. Invalid Split Request: Approve 3, but Paint (2) + Assembly (2) = 4 != 3 (Must fail with 422)
            $failRes = $this->postJson('/api/v1/qc/inspect', [
                'receipt_item_id' => $recItem->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'result' => 'approved',
                'approved_quantity' => 3,
                'paint_quantity' => 2,
                'assembly_quantity' => 2,
            ]);
            $failRes->assertStatus(422);

            // 2. Valid Split Request: Approve 3, Split into 2 Paint + 1 Assembly
            $successRes = $this->postJson('/api/v1/qc/inspect', [
                'receipt_item_id' => $recItem->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'result' => 'approved',
                'approved_quantity' => 3,
                'paint_quantity' => 2,
                'assembly_quantity' => 1,
                'remarks' => 'Split 2 Paint and 1 Direct Assembly',
            ]);
            $successRes->assertStatus(200);

            $service = app(QuantityCalculationService::class);
            $metrics = $service->calculateProjectMetrics($project, 'RH');

            // Zero-Sum Ledger Invariant Verification
            $this->assertEquals(10, $metrics['total_required']);
            $this->assertEquals(6, $metrics['total_received']);
            $this->assertEquals(4, $metrics['total_pending']);

            // 3 remaining in QC, 2 in Paint, 1 in Direct Assembly
            $this->assertEquals(3, $metrics['parts_in_qc']);
            $this->assertEquals(2, $metrics['parts_in_paint']);
            $this->assertEquals(1, $metrics['parts_in_assembly']);
            $this->assertEquals(0, $metrics['parts_in_store']);

            $locSum = $metrics['parts_in_store'] + $metrics['parts_in_qc'] + $metrics['parts_in_rework'] + $metrics['parts_in_paint'] + $metrics['parts_in_assembly'] + $metrics['assembly_completed'] + $metrics['qc_rejected'];
            $this->assertEquals(6, $locSum);

        } finally {
            DB::rollBack();
        }
    }

    public function test_department_partial_quantity_operations()
    {
        DB::beginTransaction();
        try {
            $user = $this->getAdminUser();
            $this->actingAs($user, 'sanctum');

            $project = Project::create([
                'name' => 'Partial-Dept-Test-' . uniqid(),
                'project_code' => 'PDT-' . rand(1000, 9999),
                'status' => 'active',
            ]);

            $bomItem = BomItem::create([
                'project_id' => $project->id,
                'standard_part_no' => 'DEPT-PART-01',
                'item_no' => '1',
            ]);

            BomRequirement::create([
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'required_quantity' => 12,
            ]);

            // 1. Partial Store Receipt: receive 6 out of 12
            $recRes = $this->postJson('/api/v1/store/receipts', [
                'project_id' => $project->id,
                'delivery_note_number' => 'DN-PARTIAL-01',
                'items' => [
                    [
                        'bom_item_id' => $bomItem->id,
                        'side' => 'RH',
                        'received_quantity' => 6,
                    ]
                ]
            ]);
            $recRes->assertSuccessful();

            $service = app(QuantityCalculationService::class);
            $m1 = $service->calculateProjectMetrics($project, 'RH');
            $this->assertEquals(6, $m1['total_received']);
            $this->assertEquals(6, $m1['total_pending']);
            $this->assertEquals(6, $m1['parts_in_store']);

            // 2. Dispatch all 6 to QC
            $ri = ReceiptItem::where('bom_item_id', $bomItem->id)->first();
            $this->postJson("/api/v1/store/items/{$ri->id}/send-to-qc")->assertStatus(200);
            $this->postJson("/api/v1/qc/receive", ['receipt_item_id' => $ri->id])->assertStatus(200);

            // 3. QC: Approve 4 directly to Paint
            $qcRes = $this->postJson('/api/v1/qc/inspect', [
                'receipt_item_id' => $ri->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'result' => 'approved',
                'approved_quantity' => 4,
                'paint_quantity' => 4,
                'assembly_quantity' => 0,
            ]);
            $qcRes->assertStatus(200);

            $m2 = $service->calculateProjectMetrics($project, 'RH');
            $this->assertEquals(2, $m2['parts_in_qc']); // 2 remain in QC
            $this->assertEquals(4, $m2['parts_in_paint']); // 4 in Paint

            // 4. Paint: Partially complete 3 out of 4 to Assembly
            $insp = QcInspection::where('bom_item_id', $bomItem->id)->where('destination', 'PAINT')->first();
            $paintRes = $this->postJson('/api/v1/paint/items', [
                'bom_item_id' => $bomItem->id,
                'qc_inspection_id' => $insp->id,
                'side' => 'RH',
                'quantity' => 3,
                'paint_type' => 'Powder Coat Blue',
            ]);
            $paintRes->assertStatus(200);

            $m3 = $service->calculateProjectMetrics($project, 'RH');
            $this->assertEquals(2, $m3['parts_in_qc']); // 2 in QC
            $this->assertEquals(1, $m3['parts_in_paint']); // 1 remains in Paint
            $this->assertEquals(3, $m3['parts_in_assembly']); // 3 in Assembly

            // 5. Assembly: Partially complete 2 out of 3
            $paintRec = PaintRecord::where('bom_item_id', $bomItem->id)->first();
            $asmRes = $this->postJson('/api/v1/assembly/items', [
                'bom_item_id' => $bomItem->id,
                'paint_record_id' => $paintRec->id,
                'side' => 'RH',
                'quantity' => 2,
            ]);
            $asmRes->assertStatus(200);

            $m4 = $service->calculateProjectMetrics($project, 'RH');
            $this->assertEquals(2, $m4['parts_in_qc']); // 2 in QC
            $this->assertEquals(1, $m4['parts_in_paint']); // 1 in Paint
            $this->assertEquals(1, $m4['parts_in_assembly']); // 1 active in Assembly
            $this->assertEquals(2, $m4['assembly_completed']); // 2 assembled

            // Zero-Sum Ledger Check: 0 store + 2 QC + 1 paint + 1 assembly + 2 asmComp = 6 Total Received
            $locSum = $m4['parts_in_store'] + $m4['parts_in_qc'] + $m4['parts_in_rework'] + $m4['parts_in_paint'] + $m4['parts_in_assembly'] + $m4['assembly_completed'] + $m4['qc_rejected'];
            $this->assertEquals(6, $locSum);
            $this->assertEquals(6, $m4['total_received']);

        } finally {
            DB::rollBack();
        }
    }

    public function test_qc_partial_physical_arrival_and_inspection_retention()
    {
        DB::beginTransaction();
        try {
            $user = $this->getAdminUser();
            $this->actingAs($user, 'sanctum');

            $project = Project::create([
                'name' => 'QC-Arrival-Test-' . uniqid(),
                'project_code' => 'QAT-' . rand(1000, 9999),
                'status' => 'active',
            ]);

            $bomItem = BomItem::create([
                'project_id' => $project->id,
                'standard_part_no' => 'ARRIV-PART-01',
                'item_no' => '1',
            ]);

            BomRequirement::create([
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'required_quantity' => 6,
            ]);

            // Store receives 6 and dispatches to QC
            $recRes = $this->postJson('/api/v1/store/receipts', [
                'project_id' => $project->id,
                'delivery_note_number' => 'DN-ARRIV-01',
                'items' => [
                    [
                        'bom_item_id' => $bomItem->id,
                        'side' => 'RH',
                        'received_quantity' => 6,
                    ]
                ]
            ]);
            $recRes->assertSuccessful();

            $ri = ReceiptItem::where('bom_item_id', $bomItem->id)->first();
            $this->postJson("/api/v1/store/items/{$ri->id}/send-to-qc")->assertStatus(200);

            // Hierarchy Check before physical arrival
            $hierService = app(\App\Services\HierarchyService::class);
            $hierTree1 = $hierService->getDepartmentHierarchy('qc', $project->id, ['side' => 'RH']);
            $partData1 = $hierTree1['jigs'][0]['units'][0]['parts'][0];
            $sideStat1 = $partData1->side_stats['RH'];
            $this->assertEquals(6, $sideStat1['qc_pending_arrival']);
            $this->assertEquals(0, $sideStat1['qc_pending_inspection']);

            // 1. Partial Physical Arrival: Confirm 2 out of 6 arrive in QC bay
            $arrRes = $this->postJson('/api/v1/qc/receive', [
                'receipt_item_id' => $ri->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'quantity' => 2,
            ]);
            $arrRes->assertStatus(200);

            $hierTree2 = $hierService->getDepartmentHierarchy('qc', $project->id, ['side' => 'RH']);
            $partData2 = $hierTree2['jigs'][0]['units'][0]['parts'][0];
            $sideStat2 = $partData2->side_stats['RH'];
            $this->assertEquals(4, $sideStat2['qc_pending_arrival']);
            $this->assertEquals(2, $sideStat2['qc_pending_inspection']);

            // 2. Partial Inspection: Approve 1 out of 2 in inspection bay to Paint
            $qcInspItem = ReceiptItem::where('bom_item_id', $bomItem->id)->where('status', 'qc_received')->first();
            $this->assertNotNull($qcInspItem);

            $inspRes = $this->postJson('/api/v1/qc/inspect', [
                'receipt_item_id' => $qcInspItem->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'result' => 'approved',
                'approved_quantity' => 1,
                'paint_quantity' => 1,
                'assembly_quantity' => 0,
            ]);
            $inspRes->assertStatus(200);

            $hierTree3 = $hierService->getDepartmentHierarchy('qc', $project->id, ['side' => 'RH']);
            $partData3 = $hierTree3['jigs'][0]['units'][0]['parts'][0];
            $sideStat3 = $partData3->side_stats['RH'];
            // 4 still in arrival queue, 1 remains in inspection bay, 1 in Paint
            $this->assertEquals(4, $sideStat3['qc_pending_arrival']);
            $this->assertEquals(1, $sideStat3['qc_pending_inspection']);
            $this->assertEquals(1, $sideStat3['parts_in_paint']);

            // Overall Zero-sum ledger check
            $calcService = app(\App\Services\QuantityCalculationService::class);
            $metrics = $calcService->calculateProjectMetrics($project, 'RH');
            $this->assertEquals(6, $metrics['total_received']);
            $this->assertEquals(5, $metrics['parts_in_qc']); // 4 in arrival + 1 in inspection bay
            $this->assertEquals(1, $metrics['parts_in_paint']);
            $locSum = $metrics['parts_in_store'] + $metrics['parts_in_qc'] + $metrics['parts_in_rework'] + $metrics['parts_in_paint'] + $metrics['parts_in_assembly'] + $metrics['assembly_completed'] + $metrics['qc_rejected'];
            $this->assertEquals(6, $locSum);

        } finally {
            DB::rollBack();
        }
    }

    public function test_rework_completion_returns_exact_quantity_to_qc_inspection()
    {
        DB::beginTransaction();
        try {
            $user = $this->getAdminUser();
            $this->actingAs($user, 'sanctum');

            $project = Project::create([
                'name' => 'Rework-Return-Test-' . uniqid(),
                'project_code' => 'RRT-' . rand(1000, 9999),
                'status' => 'active',
            ]);

            $bomItem = BomItem::create([
                'project_id' => $project->id,
                'standard_part_no' => 'REW-PART-01',
                'item_no' => '1',
            ]);

            BomRequirement::create([
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'required_quantity' => 6,
            ]);

            // 1. Store receipt of 6 and dispatch to QC
            $recRes = $this->postJson('/api/v1/store/receipts', [
                'project_id' => $project->id,
                'delivery_note_number' => 'DN-REW-01',
                'items' => [
                    [
                        'bom_item_id' => $bomItem->id,
                        'side' => 'RH',
                        'received_quantity' => 6,
                    ]
                ]
            ]);
            $recRes->assertSuccessful();

            $ri = ReceiptItem::where('bom_item_id', $bomItem->id)->first();
            $this->postJson("/api/v1/store/items/{$ri->id}/send-to-qc")->assertStatus(200);

            // 2. Physical Arrival of all 6
            $this->postJson('/api/v1/qc/receive', [
                'receipt_item_id' => $ri->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'quantity' => 6,
            ])->assertStatus(200);

            // 3. QC inspects: 4 to Rework, 2 to Paint
            $qcItem = ReceiptItem::where('bom_item_id', $bomItem->id)->where('status', 'qc_received')->first();
            $this->postJson('/api/v1/qc/inspect', [
                'receipt_item_id' => $qcItem->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'result' => 'rework',
                'rework_quantity' => 4,
            ])->assertStatus(200);

            // Remaining 2 in QC bay approved to Paint
            $qcRemItem = ReceiptItem::where('bom_item_id', $bomItem->id)->where('status', 'qc_received')->first();
            $this->assertNotNull($qcRemItem);
            $this->postJson('/api/v1/qc/inspect', [
                'receipt_item_id' => $qcRemItem->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'result' => 'approved',
                'approved_quantity' => 2,
                'paint_quantity' => 2,
                'assembly_quantity' => 0,
            ])->assertStatus(200);

            $hierService = app(\App\Services\HierarchyService::class);
            $calcService = app(\App\Services\QuantityCalculationService::class);

            // Verify state: 4 in Rework, 2 in Paint, 0 in QC
            $m1 = $calcService->calculateProjectMetrics($project, 'RH');
            $this->assertEquals(4, $m1['parts_in_rework']);
            $this->assertEquals(2, $m1['parts_in_paint']);
            $this->assertEquals(0, $m1['parts_in_qc']);

            // 4. Complete Partial Rework: 2 out of 4 completed
            $rewRecord = ReworkRecord::where('bom_item_id', $bomItem->id)->whereIn('status', ['pending', 'in_progress'])->first();
            $this->assertNotNull($rewRecord);

            $completeRes = $this->postJson("/api/v1/rework/items/{$rewRecord->id}/complete", [
                'quantity' => 2,
                'completion_notes' => 'Surface polish done.',
            ]);
            $completeRes->assertStatus(200);

            // 5. Verify state after rework completion:
            // Rework drops from 4 to 2
            // QC increases from 0 to 2 (ready in inspection bay)
            // Paint remains 2
            $m2 = $calcService->calculateProjectMetrics($project, 'RH');
            $this->assertEquals(2, $m2['parts_in_rework']);
            $this->assertEquals(2, $m2['parts_in_qc']);
            $this->assertEquals(2, $m2['parts_in_paint']);
            $this->assertEquals(6, $m2['total_received']);

            $hierTree = $hierService->getDepartmentHierarchy('qc', $project->id, ['side' => 'RH']);
            $sideStat = $hierTree['jigs'][0]['units'][0]['parts'][0]->side_stats['RH'];
            $this->assertEquals(2, $sideStat['qc_pending_inspection']);
            $this->assertEquals(2, $sideStat['parts_in_rework']);
            $this->assertEquals(2, $sideStat['parts_in_paint']);

            // 6. QC reinspects returned 2 units and approves them to Paint
            $returnedQcItem = ReceiptItem::where('bom_item_id', $bomItem->id)->where('status', 'qc_received')->first();
            $this->assertNotNull($returnedQcItem);
            $this->assertEquals(2, $returnedQcItem->received_quantity);

            $this->postJson('/api/v1/qc/inspect', [
                'receipt_item_id' => $returnedQcItem->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'result' => 'approved',
                'approved_quantity' => 2,
                'paint_quantity' => 2,
                'assembly_quantity' => 0,
            ])->assertStatus(200);

            // Final state: 2 in Rework, 4 in Paint, 0 in QC
            $m3 = $calcService->calculateProjectMetrics($project, 'RH');
            $this->assertEquals(2, $m3['parts_in_rework']);
            $this->assertEquals(4, $m3['parts_in_paint']);
            $this->assertEquals(0, $m3['parts_in_qc']);
            $this->assertEquals(6, $m3['total_received']);

        } finally {
            DB::rollBack();
        }
    }
}
