<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_entries', function (Blueprint $table) {
            $table->string('hash', 64)->nullable()->after('id');
            $table->string('prev_hash', 64)->nullable()->after('hash');
        });

        Schema::table('audit_entries', function (Blueprint $table) {
            $table->unique('hash');
        });
    }

    public function down(): void
    {
        Schema::table('audit_entries', function (Blueprint $table) {
            $table->dropIndex(['hash']);
            $table->dropColumn(['hash', 'prev_hash']);
        });
    }
};
