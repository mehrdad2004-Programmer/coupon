<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PostsModel;
use App\Models\PostMetaModel;
use Illuminate\Support\Str;

class PostsController extends Controller
{

    // User sends: { "group_name": "tigra", "auth_token": "x7k9m2", "prefix": "TIGRA", "length": 8 }
    public function insertNewGroup(Request $request){
        try{
            $groupName = $request->input('group_name');

            // Check if group name already exists
            $exists = PostsModel::where('post_title', $groupName)
                ->where('post_type', 'shop_coupon')
                ->exists();

            if($exists){
                return response()->json([
                    "msg" => "Group name already exists",
                    "statuscode" => 400
                ], 400);
            }

            $inserted = PostsModel::create([
                "post_author" => 1,
                "post_date" => now(),
                "post_date_gmt" => now(),
                "post_title" => $groupName,
                "post_name" => strtolower(str_replace(' ', '-', $groupName)),
                "post_status" => "publish",
                "post_type" => "shop_coupon",
                "post_modified" => now(),
                "post_modified_gmt" => now()
            ]);

            // Store group properties
            PostMetaModel::create([
                "post_id" => $inserted->ID,
                "meta_key" => "_auth_token",
                "meta_value" => $request->input('auth_token')
            ]);

            PostMetaModel::create([
                "post_id" => $inserted->ID,
                "meta_key" => "_prefix",
                "meta_value" => $request->input('prefix')
            ]);

            PostMetaModel::create([
                "post_id" => $inserted->ID,
                "meta_key" => "_length",
                "meta_value" => $request->input('length')
            ]);

            return response()->json([
                "msg" => "Group created successfully",
                "statuscode" => 201
            ], 201);

        } catch(\Exception $e){
            return response()->json([
                "msg" => $e->getMessage(),
                "statuscode" => 500
            ], 500);
        }
    }


    // User sends: { "group_id": 123, "product_ids": [123,456,789], "amount": 20, "discount_type": "percent" }
    public function addProductsToGroup(Request $request){
        try{
            $groupId = $request->input('group_id');
            $productIds = $request->input('product_ids');
            $amount = $request->input('amount'); // Required for WooCommerce
            $discountType = $request->input('discount_type'); // 'percent' or 'fixed_cart'

            $productIdsString = implode(',', $productIds);

            // Native WooCommerce meta keys
            PostMetaModel::create([
                "post_id" => $groupId,
                "meta_key" => "product_ids",
                "meta_value" => $productIdsString
            ]);

            PostMetaModel::create([
                "post_id" => $groupId,
                "meta_key" => "coupon_amount",
                "meta_value" => $amount
            ]);

            PostMetaModel::create([
                "post_id" => $groupId,
                "meta_key" => "discount_type",
                "meta_value" => $discountType
            ]);

            return response()->json([
                "msg" => "Products added to group with discount",
                "statuscode" => 201
            ], 201);

        } catch(\Exception $e){
            return response()->json([
                "msg" => $e->getMessage(),
                "statuscode" => 500
            ], 500);
        }
    }

    public function getProducts(){
        try{
            $data = PostsModel::where("post_type", "product")->get();

            if($data->isEmpty()){
                return response()->json([
                    "msg" => "not found",
                    "statuscode" => 404
                ], 404);
            }

            return response()->json([
                "msg" => $data,
                "statuscode" => 200
            ], 200);

        } catch(\Exception $e){
            return response()->json([
                "msg" => $e->getMessage(),
                "statuscode" => 500
            ], 500);
        }
    }

    public function createDiscountCode(Request $request){
        try{
            $organId = $request->input('organ_id');
            $productId = $request->input('product_id');

            // Get organ properties
            $organMeta = PostMetaModel::where("post_id", $organId)
                ->whereIn('meta_key', ['_prefix', '_length', '_expiry_hours'])
                ->get()
                ->pluck('meta_value', 'meta_key');

            $prefix = $organMeta['_prefix'] ?? 'ORG';
            $length = (int)($organMeta['_length'] ?? 8);
            $expiryHours = (int)($organMeta['_expiry_hours'] ?? 48);

            // Generate UUID and take first $length characters
            $uuid = str_replace('-', '', (string) Str::uuid());
            $randomCode = substr($uuid, 0, $length);
            $token = $prefix . '_' . $randomCode;

            // Check if token already exists
            $exists = PostsModel::where('post_title', $token)
                ->where('post_type', 'shop_coupon')
                ->exists();

            if($exists){
                // Regenerate with new UUID
                $uuid = str_replace('-', '', (string) Str::uuid());
                $randomCode = substr($uuid, 0, $length);
                $token = $prefix . '_' . $randomCode;
            }

            // Create the discount code post
            $inserted = PostsModel::create([
                "post_author" => 1,
                "post_date" => now(),
                "post_date_gmt" => now(),
                "post_title" => $token,
                "post_name" => strtolower($token),
                "post_status" => "publish",
                "post_type" => "shop_coupon",
                "post_modified" => now(),
                "post_modified_gmt" => now()
            ]);

            // Add native WooCommerce meta
            PostMetaModel::create([
                "post_id" => $inserted->ID,
                "meta_key" => "product_ids",
                "meta_value" => $productId
            ]);

            PostMetaModel::create([
                "post_id" => $inserted->ID,
                "meta_key" => "coupon_amount",
                "meta_value" => $request->input('amount', 20)
            ]);

            PostMetaModel::create([
                "post_id" => $inserted->ID,
                "meta_key" => "discount_type",
                "meta_value" => $request->input('discount_type', 'percent')
            ]);

            PostMetaModel::create([
                "post_id" => $inserted->ID,
                "meta_key" => "expiry_date",
                "meta_value" => now()->addHours($expiryHours)->format('Y-m-d H:i:s')
            ]);

            PostMetaModel::create([
                "post_id" => $inserted->ID,
                "meta_key" => "usage_limit",
                "meta_value" => "1"
            ]);

            return response()->json([
                "msg" => "Discount code created successfully",
                "token" => $token,
                "expires_in" => $expiryHours . " hours",
                "statuscode" => 201
            ], 201);

        } catch(\Exception $e){
            return response()->json([
                "msg" => $e->getMessage(),
                "statuscode" => 500
            ], 500);
        }
    }


}
