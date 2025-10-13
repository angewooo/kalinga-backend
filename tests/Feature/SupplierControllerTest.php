<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class SupplierControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create permissions and roles first
        $this->createPermissionsAndRoles();
        
        // Create users
        $this->user = User::factory()->create();
        $this->adminUser = User::factory()->create();
        
        // Assign admin role to admin user
        $adminRole = Role::where('name', 'admin')->first();
        $this->adminUser->assignRole($adminRole);
    }

    protected function createPermissionsAndRoles()
    {
        // Create supplier permissions
        $supplierPermissions = [
            'view-suppliers',
            'create-suppliers',
            'update-suppliers',
            'delete-suppliers',
        ];

        foreach ($supplierPermissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'sanctum'
            ]);
        }

        // Create admin role if it doesn't exist
        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'sanctum'
        ]);

        // Assign all supplier permissions to admin role
        $adminRole->givePermissionTo($supplierPermissions);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_requires_authentication()
    {
        $response = $this->getJson('/api/suppliers');
        $response->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_list_suppliers_with_pagination()
    {
        Supplier::factory()->count(20)->create();

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/suppliers?per_page=10');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => [
                            'supplier_id',
                            'name',
                            'contact_info',
                            'address',
                            'created_at',
                            'updated_at'
                        ]
                    ],
                    'links',
                    'meta'
                ],
                'message'
            ])
            ->assertJson(['success' => true]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_search_suppliers_by_name()
    {
        $supplier1 = Supplier::factory()->create(['name' => 'ABC Medical Supplies']);
        $supplier2 = Supplier::factory()->create(['name' => 'XYZ Pharma']);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/suppliers?search=Medical');

        $response->assertStatus(200)
            ->assertJsonPath('data.data.0.name', 'ABC Medical Supplies')
            ->assertJsonCount(1, 'data.data');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_create_a_supplier()
    {
        $supplierData = [
            'name' => 'Test Supplier',
            'contact_info' => [
                'email' => 'test@supplier.com',
                'phone' => '+1234567890',
                'person' => 'John Doe'
            ],
            'address' => '123 Test Street, Test City'
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/suppliers', $supplierData);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Supplier created successfully'
            ]);

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Test Supplier'
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_validates_required_fields_when_creating_supplier()
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/suppliers', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_show_a_single_supplier()
    {
        $supplier = Supplier::factory()->create();

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/suppliers/{$supplier->supplier_id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'supplier_id' => $supplier->supplier_id,
                    'name' => $supplier->name
                ]
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_404_for_nonexistent_supplier()
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/suppliers/99999');

        $response->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_update_a_supplier()
    {
        $supplier = Supplier::factory()->create(['name' => 'Old Name']);

        $updateData = [
            'name' => 'Updated Supplier Name',
            'contact_info' => [
                'email' => 'updated@supplier.com'
            ]
        ];

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/suppliers/{$supplier->supplier_id}", $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Updated Supplier Name'
                ]
            ]);

        $this->assertDatabaseHas('suppliers', [
            'supplier_id' => $supplier->supplier_id,
            'name' => 'Updated Supplier Name'
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_delete_a_supplier()
    {
        $supplier = Supplier::factory()->create();

        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/suppliers/{$supplier->supplier_id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Supplier deleted successfully'
            ]);

        $this->assertDatabaseMissing('suppliers', [
            'supplier_id' => $supplier->supplier_id
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_search_suppliers_using_search_endpoint()
    {
        Supplier::factory()->create(['name' => 'Medical Supply Co']);
        Supplier::factory()->create(['name' => 'Pharma Distributors']);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/suppliers/search?query=Medical');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_get_supplier_statistics()
    {
        Supplier::factory()->count(5)->create();

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/suppliers/statistics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_suppliers',
                    'suppliers_with_contact',
                    'suppliers_with_address',
                    'recent_additions',
                    'top_suppliers_by_name'
                ]
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_get_supplier_performance()
    {
        $supplier = Supplier::factory()->create();

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/suppliers/{$supplier->supplier_id}/performance");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'supplier' => [
                        'supplier_id' => $supplier->supplier_id
                    ]
                ]
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_get_supplier_analytics()
    {
        $supplier = Supplier::factory()->create();

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/suppliers/{$supplier->supplier_id}/analytics");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_get_supplier_reliability()
    {
        $supplier = Supplier::factory()->create();

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/suppliers/{$supplier->supplier_id}/reliability");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_validates_email_format_in_contact_info()
    {
        $supplierData = [
            'name' => 'Test Supplier',
            'contact_info' => [
                'email' => 'invalid-email',
                'phone' => '+1234567890'
            ]
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/suppliers', $supplierData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['contact_info.email']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_enforces_permissions_for_supplier_operations()
    {
        // Regular user without permissions should be denied
        $response = $this->actingAs($this->user)
            ->getJson('/api/suppliers');

        $response->assertStatus(403); // Forbidden
    }
}