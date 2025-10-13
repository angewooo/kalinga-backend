<?php

namespace App\Events;

use App\Models\HospitalResource;
use App\Models\ResourceThreshold;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ResourceThresholdBreached implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public HospitalResource $resource;
    public ResourceThreshold $threshold;
    public string $breachType; // 'critical' or 'reorder'

    /**
     * Create a new event instance.
     */
    public function __construct(HospitalResource $resource, ResourceThreshold $threshold, string $breachType)
    {
        $this->resource = $resource;
        $this->threshold = $threshold;
        $this->breachType = $breachType;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('resources'),
            new Channel('hospital.' . $this->resource->hospital_id),
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
            'resource_id' => $this->resource->id,
            'hospital_id' => $this->resource->hospital_id,
            'resource_type' => $this->resource->resource_type,
            'current_quantity' => $this->resource->quantity_available,
            'threshold_level' => $this->breachType === 'critical' 
                ? $this->threshold->critical_level 
                : $this->threshold->reorder_level,
            'breach_type' => $this->breachType,
            'severity' => $this->breachType === 'critical' ? 'high' : 'medium',
            'timestamp' => now()->toISOString(),
            'message' => "Resource {$this->resource->resource_type} has fallen below {$this->breachType} threshold",
        ];
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'threshold.breached';
    }
}