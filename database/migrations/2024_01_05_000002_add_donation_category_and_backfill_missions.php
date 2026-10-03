<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kriteria validasi foto per misi (sumber: kolom missions.validation_prompt).
     * Dipakai GeminiService untuk membangun prompt spesifik per misi.
     *
     * @var array<string, string>
     */
    private const WASTE_CRITERIA = [
        'Pahlawan Plastik Terpilah' => 'Foto harus menunjukkan sampah PLASTIK yang sudah dipilah '
            .'dan dikumpulkan terpisah dalam wadah atau kantong khusus. '
            .'Tolak foto yang sampahnya tercampur, bukan plastik, '
            .'atau tidak menunjukkan upaya pemilahan.',
        'Pilah Sampah Elektronik' => 'Foto harus menunjukkan sampah ELEKTRONIK yang sudah dipilah '
            .'dan dikumpulkan terpisah. '
            .'Tolak foto yang bukan e-waste atau tercampur sampah lain.',
    ];

    private const CATEGORIES_WITH_DONATION = ['mobility', 'waste', 'quiz', 'donation'];

    private const CATEGORIES_ORIGINAL = ['mobility', 'waste', 'quiz'];

    public function up(): void
    {
        // doctrine/dbal tidak terinstal sehingga ->change() tidak bisa dipakai.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE missions MODIFY category ENUM('mobility', 'waste', 'quiz', 'donation') NOT NULL"
            );
        } else {
            // SQLite membuat CHECK constraint dari enum saat tabel dibuat,
            // jadi satu-satunya cara melebarkan nilai adalah rebuild tabel.
            $this->rebuildMissionsTable(self::CATEGORIES_WITH_DONATION);
        }

        // Donasi mangrove bukan misi foto pilahan -> keluar dari kategori waste.
        DB::table('missions')
            ->where('title', 'Donasi Pohon Mangrove')
            ->where('category', 'waste')
            ->update(['category' => 'donation']);

        // Backfill kriteria untuk misi waste yang sudah ada (idempoten).
        foreach (self::WASTE_CRITERIA as $title => $criteria) {
            DB::table('missions')
                ->where('title', $title)
                ->where('category', 'waste')
                ->whereNull('validation_prompt')
                ->update(['validation_prompt' => $criteria]);
        }
    }

    public function down(): void
    {
        DB::table('missions')
            ->where('title', 'Donasi Pohon Mangrove')
            ->where('category', 'donation')
            ->update(['category' => 'waste']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE missions MODIFY category ENUM('mobility', 'waste', 'quiz') NOT NULL"
            );
        } else {
            $this->rebuildMissionsTable(self::CATEGORIES_ORIGINAL);
        }
    }

    /**
     * SQLite me-rewrite referensi FK di tabel anak saat RENAME (perilaku
     * default modern), sehingga pola rename-swap MEMUTUS FK user_missions /
     * quizzes. Pola yang aman: buat missions_new -> salin -> drop missions
     * lama -> rename missions_new menjadi missions. Referensi anak yang
     * bernama 'missions' tetap valid sepanjang proses (FK dimatikan).
     *
     * @param  array<int, string>  $categories
     */
    private function rebuildMissionsTable(array $categories): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            Schema::create('missions_new', function (Blueprint $table) use ($categories) {
                $table->id();
                $table->string('title');
                $table->text('description');
                $table->text('validation_prompt')->nullable();
                $table->enum('category', $categories);
                $table->integer('xp_reward')->default(0);
                $table->integer('points_reward')->default(0);
                $table->string('icon')->nullable();
                $table->integer('max_participants')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            DB::table('missions_new')->insertUsing(
                ['id', 'title', 'description', 'validation_prompt', 'category',
                    'xp_reward', 'points_reward', 'icon', 'max_participants',
                    'is_active', 'created_at', 'updated_at'],
                DB::table('missions')->select(
                    ['id', 'title', 'description', 'validation_prompt', 'category',
                        'xp_reward', 'points_reward', 'icon', 'max_participants',
                        'is_active', 'created_at', 'updated_at']
                )
            );

            Schema::drop('missions');
            Schema::rename('missions_new', 'missions');
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
};
