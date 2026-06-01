<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Model;

class PostsModel extends Model
{
    protected $table = "id9j49_posts";
    protected $primaryKey = "ID";
    public $timestamps = false;

    protected $fillable = [
        "post_author",
        "post_date",
        "post_date_gmt",
        "post_content",
        "post_title",
        "post_excerpt",
        "post_status",
        "comment_status",
        "ping_status",
        "post_password",
        "post_name",
        "to_ping",
        "pinged",
        "post_modified",
        "post_modified_gmt",
        "post_content_filtered",
        "post_parent",
        "guid",
        "menu_order",
        "post_type",
        "post_mime_type",
        "comment_count"
    ];

    // Add this relationship
    public function meta()
    {
        return $this->hasMany(PostMetaModel::class, 'post_id', 'ID');
    }
}
