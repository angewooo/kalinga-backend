<?php

namespace App\Events;

use App\Models\SensorReading;
use App\Models\RealTimeSensor;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SensorReadingReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public SensorReading $reading;
    public RealTimeSensor $sensor;
    public ?array $alert;

    /**
     * Create a new event instance.
     */
    public function __construct(SensorReading $reading, RealTimeSensor $sensor, ?array $alert = null)
    {
        $this->reading = $reading;
        $this->sensor = $sensor;
        $this->alert = $alert;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('sensors'),
            new Channel('sensor.' . $this->sensor->id),
            new Channel('hospital.' . $this->sensor->hospital_id),
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
            'reading_id' => $this->reading->id,
            'sensor_id' => $this->sensor->id,
            'sensor_type' => $this->sensor->sensor_type,
            'location' => $this->sensor->location,
            'value' => $this->reading->value,
            'unit' => $this->sensor->unit_of_measurement,
            'timestamp' => $this->reading->timestamp->toISOString(),
            'alert' => $this->alert,
            'message' => $this->alert 
                ? "Sensor threshold breached: {$this->alert['breach_type']}"
                : 'Sensor reading recorded',
        ];
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'sensor.reading';
    }
}