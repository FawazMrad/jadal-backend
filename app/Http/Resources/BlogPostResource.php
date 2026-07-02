<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BlogPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'title'           => $this->title,
            'slug'            => $this->slug,
            'excerpt'         => Str::limit(strip_tags($this->content), 200),
            // Legacy rows may still hold a raw external URL; uploaded covers are
            // stored as a relative path and need resolving (see UserResource::avatar_url).
            'cover_image_url' => $this->cover_image_url
                ? (str_starts_with($this->cover_image_url, 'http')
                    ? $this->cover_image_url
                    : Storage::disk('public')->url($this->cover_image_url))
                : null,
            'author'          => new UserResource($this->whenLoaded('author')),
            'categories'      => CategoryResource::collection($this->whenLoaded('categories')),
            'tags'            => TagResource::collection($this->whenLoaded('tags')),
            'views'           => (int) ($this->views ?? 0),
            'likes_count'     => (int) ($this->likes_count ?? 0),
            'dislikes_count'  => (int) ($this->dislikes_count ?? 0),
            'status'          => $this->status,
            'published_at'    => $this->published_at?->toIso8601String(),
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
