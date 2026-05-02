<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlogPostDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'title'            => $this->title,
            'slug'             => $this->slug,
            'content'          => $this->content,
            'cover_image_url'  => $this->cover_image_url,
            'author'           => new UserResource($this->whenLoaded('author')),
            'categories'       => CategoryResource::collection($this->whenLoaded('categories')),
            'tags'             => TagResource::collection($this->whenLoaded('tags')),
            'views'            => (int) ($this->views ?? 0),
            'likes_count'      => (int) ($this->likes_count ?? 0),
            'dislikes_count'   => (int) ($this->dislikes_count ?? 0),
            'status'           => $this->status,
            'reviewer_comment' => $this->reviewer_comment,
            'published_at'     => $this->published_at?->toIso8601String(),
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
