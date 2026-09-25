<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class PrinterResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'connection' => $this->connection_type->value,
            'connection_label' => $this->connection_type->label(),
            'device_name' => $this->device_name,
            'ip_address' => $this->ip_address,
            'port' => $this->port,
            'address' => $this->address(),
            'paper_width' => $this->paper_width,
            'is_active' => $this->is_active,
            'last_tested_at' => static::iso($this->last_tested_at),
            'counters' => $this->refs('counters'),
            $this->trashFields(),
        ];
    }
}
