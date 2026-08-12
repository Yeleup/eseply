<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipt_templates', function (Blueprint $table) {
            $table->longText('html')->nullable()->after('settings');
            $table->text('css')->nullable()->after('html');
            $table->unsignedTinyInteger('copies_per_page')->default(2)->after('css');
        });

        foreach (DB::table('receipt_templates')->get(['id', 'settings']) as $row) {
            $settings = json_decode((string) $row->settings, true);
            $copies = $settings['appearance']['copies_per_page'] ?? 2;

            DB::table('receipt_templates')
                ->where('id', $row->id)
                ->update(['copies_per_page' => in_array($copies, [1, 2], true) ? $copies : 2]);
        }
    }

    public function down(): void
    {
        Schema::table('receipt_templates', function (Blueprint $table) {
            $table->dropColumn(['html', 'css', 'copies_per_page']);
        });
    }
};
