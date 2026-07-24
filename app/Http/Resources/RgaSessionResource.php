<?php

namespace App\Http\Resources;

use App\Models\Rga;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicit reveal of the RGA's stored session cookies — never exposed via
 * RgaResource, only through the dedicated session endpoint.
 *
 * @mixin Rga
 */
class RgaSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'rg_sess_id' => $this->cookies['rg_sess_id'] ?? null,
            'token' => $this->cookies['token'] ?? null,
            'cuserid2' => $this->cookies['cuserid2'] ?? null,
            'status' => $this->status,
            'last_login_at' => $this->last_login_at,
        ];
    }
}
