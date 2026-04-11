<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompanyAccessLinksTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('appcfg.company_access_links')) {
            Schema::create('appcfg.company_access_links', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->primary();
                $table->string('access_slug', 120)->unique();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->index(['is_active'], 'idx_company_access_links_active');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('appcfg.company_access_links')) {
            Schema::drop('appcfg.company_access_links');
        }
    }
}
