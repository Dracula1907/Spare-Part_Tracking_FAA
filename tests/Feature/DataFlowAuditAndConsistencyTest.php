<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\QcInspection;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use App\Models\ReworkRecord;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\User;
use App\Services\QuantityCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataFlowAuditAndConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Project $project;
    protected BomItem $bomItem;
    protected QuantityCalculationService $quantityService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quantityService = new QuantityCalculationService();

        $this->admin = User::where('email', 'admin@sparetrack.internal')->first();
        if (!$this->admin) {
            $this->admin = User::create([
                'name' => 'Admin User',
                'email' => 'admin@sparetrack.internal',
                'password' => bcrypt('password123'),
            ]);
        }
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ADMIN']);
        $this->admin->assignRole($role);

        $this->project = Project::create([
            'name' => 'Automated Test Project',
            'project_code' => 'ATP-001',
            'status' => 'active',
        ]);

        $this->bomItem = BomItem::create([
            'project_id' => $this->project->id,
            'jig_no' => '169961',
            'unit_no' => '010',
            'part_no' => '010',
            'standard_part_no' => '010#R00',
            'item_no' => '010',
        ]);

        BomRequirement::create([
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'required_quantity' => 5,
        ]);

        BomRequirement::create([
            'bom_item_id' => $this->bomItem->id,
            'side' => 'RH',
            'required_quantity' => 5,
        ]);
    }

    public function test_all_11_dashboard_blocks_reconcile_with_kpi_summary(): void
    {
        $this->actingAs($this->admin);

        $summaryResp = $this->getJson('/api/v1/dashboard/summary');
        $summaryResp->assertStatus(200);
        $summary = $summaryResp->json('summary');

        $blocks = [
            'active_projects',
            'completed_projects',
            'delayed_projects',
            'total_parts',
            'total_parts_received',
            'parts_pending',
            'store',
            'qc',
            'rework',
            'paint',
            'assembly',
        ];

        foreach ($blocks as $blk) {
            $resp = $this->getJson("/api/v1/dashboard/block-details?block={$blk}");
            $resp->assertStatus(200);
            $data = $resp->json();

            $this->assertArrayHasKey('columns', $data);
            $this->assertArrayHasKey('items', $data);
            $this->assertArrayHasKey('total_quantity', $data);
            $this->assertArrayHasKey('total_records', $data);

            if ($blk === 'total_parts') {
                $this->assertEquals($summary['total_required'], $data['total_quantity']);
            } elseif ($blk === 'total_parts_received') {
                $this->assertEquals($summary['total_received'], $data['total_quantity']);
            } elseif ($blk === 'parts_pending') {
                $this->assertEquals($summary['total_pending'], $data['total_quantity']);
            } elseif ($blk === 'store') {
                $this->assertEquals($summary['parts_in_store'], $data['total_quantity']);
            } elseif ($blk === 'qc') {
                $this->assertEquals($summary['parts_in_qc'], $data['total_quantity']);
            } elseif ($blk === 'rework') {
                $this->assertEquals($summary['parts_in_rework'], $data['total_quantity']);
            } elseif ($blk === 'paint') {
                $this->assertEquals($summary['parts_in_paint'], $data['total_quantity']);
            } elseif ($blk === 'assembly') {
                $this->assertEquals($summary['parts_in_assembly'], $data['total_quantity']);
            }
        }
    }

    public function test_assembly_and_paint_queue_visibility_and_kpi_parity(): void
    {
        $this->actingAs($this->admin);

        // 1. Store Intake: Receive 2 LH pcs
        $receipt = Receipt::create([
            'project_id' => $this->project->id,
            'received_by' => $this->admin->id,
        ]);
        $recItem = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'received_quantity' => 2,
            'status' => 'sent_to_qc',
        ]);

        // QC Physical arrival
        $recItem->update(['status' => 'qc_received']);

        // 2. QC Inspection: 1 pc approved to PAINT, 1 pc approved direct to ASSEMBLY
        $paintQc = QcInspection::create([
            'bom_item_id' => $this->bomItem->id,
            'receipt_item_id' => $recItem->id,
            'side' => 'LH',
            'inspected_quantity' => 1,
            'approved_quantity' => 1,
            'result' => 'approved',
            'destination' => 'PAINT',
            'inspected_by' => $this->admin->id,
            'inspection_date' => now(),
        ]);

        $directAsmQc = QcInspection::create([
            'bom_item_id' => $this->bomItem->id,
            'receipt_item_id' => $recItem->id,
            'side' => 'LH',
            'inspected_quantity' => 1,
            'approved_quantity' => 1,
            'result' => 'approved',
            'destination' => 'ASSEMBLY',
            'inspected_by' => $this->admin->id,
            'inspection_date' => now(),
        ]);

        // Paint Block Details check: Must show 1 pc
        $paintDetails = $this->getJson('/api/v1/dashboard/block-details?block=paint')->json();
        $this->assertEquals(1, $paintDetails['total_quantity']);
        $this->assertCount(1, $paintDetails['items']);

        // Assembly Block Details check: Must show 1 pc (direct QC direct assembly)
        $asmDetails = $this->getJson('/api/v1/dashboard/block-details?block=assembly')->json();
        $this->assertEquals(1, $asmDetails['total_quantity']);
        $this->assertCount(1, $asmDetails['items']);

        // 3. Complete Painting for the 1 pc
        $paintRec = PaintRecord::create([
            'bom_item_id' => $this->bomItem->id,
            'qc_inspection_id' => $paintQc->id,
            'side' => 'LH',
            'quantity' => 1,
            'status' => 'completed',
        ]);

        // Paint Details must now be 0
        $paintDetailsAfter = $this->getJson('/api/v1/dashboard/block-details?block=paint')->json();
        $this->assertEquals(0, $paintDetailsAfter['total_quantity']);
        $this->assertCount(0, $paintDetailsAfter['items']);

        // Assembly Details must now be 2 pcs (1 from Paint + 1 direct QC)
        $asmDetailsAfter = $this->getJson('/api/v1/dashboard/block-details?block=assembly')->json();
        $this->assertEquals(2, $asmDetailsAfter['total_quantity']);
        $this->assertCount(2, $asmDetailsAfter['items']);

        // Assembly KPI must match exactly 2 pcs
        $summary = $this->getJson('/api/v1/dashboard/summary')->json('summary');
        $this->assertEquals(2, $summary['parts_in_assembly']);

        // 4. Complete Assembly for both
        AssemblyRecord::create([
            'bom_item_id' => $this->bomItem->id,
            'paint_record_id' => $paintRec->id,
            'side' => 'LH',
            'quantity' => 1,
            'status' => 'completed',
        ]);

        AssemblyRecord::create([
            'bom_item_id' => $this->bomItem->id,
            'qc_inspection_id' => $directAsmQc->id,
            'side' => 'LH',
            'quantity' => 1,
            'status' => 'completed',
        ]);

        // Assembly Details must now be 0
        $asmDetailsFinal = $this->getJson('/api/v1/dashboard/block-details?block=assembly')->json();
        $this->assertEquals(0, $asmDetailsFinal['total_quantity']);
        $this->assertCount(0, $asmDetailsFinal['items']);

        $summaryFinal = $this->getJson('/api/v1/dashboard/summary')->json('summary');
        $this->assertEquals(0, $summaryFinal['parts_in_assembly']);
        $this->assertEquals(2, $summaryFinal['assembly_completed']);
    }

    public function test_rework_completion_and_state_transitions_preserve_zero_sum_conservation(): void
    {
        $this->actingAs($this->admin);

        // Store intake: 2 RH pcs
        $receipt = Receipt::create([
            'project_id' => $this->project->id,
            'received_by' => $this->admin->id,
        ]);
        $recItem = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $this->bomItem->id,
            'side' => 'RH',
            'received_quantity' => 2,
            'status' => 'qc_received',
        ]);

        // QC Rejection -> Rework
        $qcInsp = QcInspection::create([
            'bom_item_id' => $this->bomItem->id,
            'receipt_item_id' => $recItem->id,
            'side' => 'RH',
            'inspected_quantity' => 2,
            'rework_quantity' => 2,
            'result' => 'rework',
            'inspected_by' => $this->admin->id,
            'inspection_date' => now(),
        ]);

        $rework = ReworkRecord::create([
            'bom_item_id' => $this->bomItem->id,
            'qc_inspection_id' => $qcInsp->id,
            'side' => 'RH',
            'quantity' => 2,
            'status' => 'pending',
        ]);

        $summaryRework = $this->getJson('/api/v1/dashboard/summary')->json('summary');
        $this->assertEquals(2, $summaryRework['parts_in_rework']);
        $this->assertEquals(0, $summaryRework['parts_in_qc']);

        // Complete Rework
        $this->postJson("/api/v1/rework/items/{$rework->id}/complete", [
            'completion_notes' => 'Corrective grinding completed',
        ])->assertStatus(200);

        $summaryReturned = $this->getJson('/api/v1/dashboard/summary')->json('summary');
        $this->assertEquals(0, $summaryReturned['parts_in_rework']);
        $this->assertEquals(2, $summaryReturned['parts_in_qc']);

        // Verify Rework block details is 0 records
        $reworkDetails = $this->getJson('/api/v1/dashboard/block-details?block=rework')->json();
        $this->assertEquals(0, $reworkDetails['total_quantity']);
        $this->assertCount(0, $reworkDetails['items']);

        // Verify QC block details is 2 pcs
        $qcDetails = $this->getJson('/api/v1/dashboard/block-details?block=qc')->json();
        $this->assertEquals(2, $qcDetails['total_quantity']);
    }
}
