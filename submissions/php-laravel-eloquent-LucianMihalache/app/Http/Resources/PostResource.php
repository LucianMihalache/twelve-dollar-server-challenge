<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A post as the API answers it, in the key order SPEC.md gives. */
class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'created_at' => $this->created_at,
            'author' => $this->user->username,
            'like_count' => (int) $this->likes_count,
        ];
    }
}
