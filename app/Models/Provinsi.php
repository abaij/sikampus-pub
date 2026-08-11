<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Provinsi extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'provinsi';

    protected $fillable = ['nama', 'kode', 'id_negara'];

    protected $hidden = ['created_at', 'updated_at', 'deleted_at'];

    public function negara()
    {
        return $this->belongsTo(Negara::class, 'id_negara');
    }
}
