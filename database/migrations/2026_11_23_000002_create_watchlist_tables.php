<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Products a CVE affects: from NVD (CPE entries) and from CISA KEV.
        Schema::create('cve_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cve_id')->constrained()->cascadeOnDelete();
            $table->string('vendor');
            $table->string('product');
            // Lowercase, letters and digits only: "Palo Alto Networks" and "paloaltonetworks" match.
            $table->string('vendor_key')->index();
            $table->string('product_key')->index();
            $table->string('source', 8); // nvd | kev

            $table->unique(['cve_id', 'vendor_key', 'product_key']);
        });

        // What a user runs and wants to hear about.
        Schema::create('watchlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('vendor');
            $table->string('product')->nullable();
            $table->string('vendor_key');
            $table->string('product_key')->nullable();
            $table->json('aliases')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'vendor_key', 'product_key']);
        });

        // Stories that matched a watchlist item, and why.
        Schema::create('watchlist_hits', function (Blueprint $table) {
            $table->foreignId('watchlist_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cve_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 8); // cve | text
            $table->timestamp('created_at')->nullable();

            $table->primary(['watchlist_item_id', 'entry_id']);
            $table->index('entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist_hits');
        Schema::dropIfExists('watchlist_items');
        Schema::dropIfExists('cve_products');
    }
};
