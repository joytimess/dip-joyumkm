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
        Schema::table('umkms', function (Blueprint $table) {
            $table->string('omzet_period', 16)->nullable();
            $table->unsignedSmallInteger('year_founded')->nullable();
            $table->unsignedInteger('employee_count')->nullable();
            $table->string('nib', 32)->nullable();
            $table->text('description')->nullable();
            $table->string('city', 120)->nullable();
            $table->text('address')->nullable();
            $table->string('district', 120)->nullable();
            $table->string('subdistrict', 120)->nullable();
            $table->string('postal_code', 5)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('owner_name', 120)->nullable();
            $table->string('whatsapp_number', 20)->nullable();
            $table->string('business_email', 254)->nullable();
            $table->string('pic_name', 120)->nullable();
            $table->string('website_url', 2048)->nullable();
            $table->string('social_media_url', 2048)->nullable();
            $table->string('wa_registration_status', 16)->default('unchecked');
            $table->timestamp('wa_checked_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('umkms', function (Blueprint $table) {
            $table->dropColumn([
                'omzet_period', 'year_founded', 'employee_count', 'nib', 'description',
                'city', 'address', 'district', 'subdistrict', 'postal_code', 'latitude', 'longitude',
                'owner_name', 'whatsapp_number', 'business_email', 'pic_name', 'website_url', 'social_media_url',
                'wa_registration_status', 'wa_checked_at',
            ]);
        });
    }
};
