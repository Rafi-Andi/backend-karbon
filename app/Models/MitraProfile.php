<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MitraProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'nama_usaha',
        'jenis_usaha',
        'alamat_usaha',
        'usaha_kelurahan',
        'usaha_kecamatan',
        'usaha_kota',
        'usaha_provinsi',
        'usaha_kode_pos',
        'nomor_ktp',
        'nomor_nib',
        'foto_ktp',
        'foto_nib',
        'foto_toko',
        'foto_toko_2',
        'foto_toko_3',
        'nama_bank',
        'nomor_rekening',
        'nama_pemilik_rekening',
        'bank_validation_status',
        'bank_validated_at',
        'bank_validation_note',
        'status_verifikasi',
        'verification_note',
        'is_active',
        'balance',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'is_active' => 'boolean',
            'bank_validated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(MitraProduct::class);
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class);
    }
}
