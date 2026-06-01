<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostMetaModel extends Model
{
    protected $table = "id9j49_postmeta";
    protected $primaryKey = "ID";

    public $timestamps = false;

    protected $fillable = [
        "post_id",
        "meta_key",
        "meta_value"
    ];

    protected $casts = [
        "post_id" => "integer"
    ];
}
