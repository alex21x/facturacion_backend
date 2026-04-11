<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompanyRateLimitsTable extends Migration
{
    public function up()
    {
        Schema::create('appcfg.company_rate_limits', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->primary();
            $table->unsignedInteger('requests_per_minute')->default(3600);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['is_enabled'], 'idx_company_rate_limits_enabled');
        });
    }

    public function down()
    {
        Schema::dropIfExists('appcfg.company_rate_limits');
    }
}
