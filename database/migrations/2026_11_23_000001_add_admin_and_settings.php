<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            $table->timestamp('disabled_at')->nullable();
        });

        // Existing installs: the oldest account becomes the admin, so nobody is locked out of the new panel.
        $first = DB::table('users')->orderBy('id')->value('id');
        if ($first !== null) {
            DB::table('users')->where('id', $first)->update(['is_admin' => true]);
        }

        // Settings that admins change at runtime (they override the .env defaults).
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'disabled_at']);
        });
    }
};
