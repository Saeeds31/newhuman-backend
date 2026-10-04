<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('slug')->nullable();
            $table->string('video_title')->nullable();
            $table->string('video_summary')->nullable();
            $table->string('prerequisite_title')->nullable();
            $table->string('prerequisite_summary')->nullable();
            $table->text('prerequisites')->nullable();
            $table->text('suitable_for')->nullable();
            $table->string('changes_after_course_title')->nullable();
            $table->string('changes_after_course_summary')->nullable();
            $table->text('changes_after_course')->nullable();
            $table->text('course_files_summary')->nullable();
            $table->text('course_philosophy')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {});
    }
};
