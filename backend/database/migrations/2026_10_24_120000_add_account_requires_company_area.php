<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Default FALSE: preserve existing account rules and all historical journal entries.
        Schema::table('accounting_accounts', function (Blueprint $table): void {
            $table->boolean('requires_company_area')->default(false);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('No se elimina una regla de imputación contable sin un plan auditado.');
    }
};
