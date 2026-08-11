<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Kecamatan extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'kecamatan';

    protected $fillable = ['nama', 'kode', 'id_kota'];

    protected $hidden = ['created_at', 'updated_at', 'deleted_at'];

    public function kota()
    {
        return $this->belongsTo(Kota::class, 'id_kota');
    }
}
