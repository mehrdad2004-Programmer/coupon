<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostsModel extends Model
{
    protected $table = "id9j49_posts";

    public $timestamps = false; // WordPress uses its own date columns

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

    // Optional: Casts for specific columns
    protected $casts = [
        "post_author" => "integer",
        "post_parent" => "integer",
        "menu_order" => "integer",
        "comment_count" => "integer",
        "post_date" => "datetime",
        "post_date_gmt" => "datetime",
        "post_modified" => "datetime",
        "post_modified_gmt" => "datetime"
    ];
}
