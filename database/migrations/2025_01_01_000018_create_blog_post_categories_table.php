<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_post_categories', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('blog_categories')->cascadeOnDelete();
            $table->primary(['post_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_categories');
    }
};
