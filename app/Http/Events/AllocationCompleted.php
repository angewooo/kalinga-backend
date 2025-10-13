<?php

namespace App\Events;

use App\Models\ResourceAllocation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AllocationCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ResourceAllocation $allocation;

    /**
     * Create a new event instance.
     */
    public function __construct(ResourceAllocation $allocation)
    {
        $this->allocation = $allocation;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('allocations'),
            new Channel('request.' . $this->allocation->request_id),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'allocation_id' => $this->allocation->id,
            'request_id' => $this->allocation->request_id,
            'allocated_hospital_id' => $this->allocation->allocated_hospital_id,
            'allocated_quantity' => $this->allocation->allocated_quantity,
            'allocation_status' => $this->allocation->allocation_status,
            'algorithm_used' => $this->allocation->algorithm_used,
            'allocation_time_ms' => $this->allocation->allocation_time_ms,
            'timestamp' => now()->toISOString(),
            'message' => 'Resource allocation completed successfully',
        ];
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'allocation.completed';
    }
}