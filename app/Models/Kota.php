<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Kota extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'kota';

    protected $fillable = ['nama', 'kode', 'id_provinsi'];

    protected $hidden = ['created_at', 'updated_at', 'deleted_at'];

    public function provinsi()
    {
        return $this->belongsTo(Provinsi::class, 'id_provinsi');
    }
}
