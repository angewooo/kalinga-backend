<?php

namespace App\Services;

use App\Models\Supplier;

class SupplierService
{
    public function bulkImportSuppliers($file, $overrideDuplicates)
    {
        return ['imported' => 0, 'skipped' => 0, 'errors' => []];
    }
    
    public function processWebhookEvent($supplier, $eventType, $data, $timestamp)
    {
        return ['status' => 'processed', 'event' => $eventType];
    }
}