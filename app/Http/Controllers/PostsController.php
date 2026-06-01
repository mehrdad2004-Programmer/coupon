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

    // User sends: { "group_id": 123, "product_ids": [123,456,789], "amount": 20, "discount_type": "percent" }
    public function updateProductsInGroup(Request $request){
        try{
            $groupId = $request->input('group_id');
            $productIds = $request->input('product_ids');
            $amount = $request->input('amount');
            $discountType = $request->input('discount_type');

            $productIdsString = implode(',', $productIds);

            // Update or create product_ids
            PostMetaModel::updateOrCreate(
                ["post_id" => $groupId, "meta_key" => "product_ids"],
                ["meta_value" => $productIdsString]
            );

            // Update or create coupon_amount
            PostMetaModel::updateOrCreate(
                ["post_id" => $groupId, "meta_key" => "coupon_amount"],
                ["meta_value" => $amount]
            );

            // Update or create discount_type
            PostMetaModel::updateOrCreate(
                ["post_id" => $groupId, "meta_key" => "discount_type"],
                ["meta_value" => $discountType]
            );

            return response()->json([
                "msg" => "Group products updated successfully",
                "statuscode" => 200
            ], 200);

        } catch(\Exception $e){
            return response()->json([
                "msg" => $e->getMessage(),
                "statuscode" => 500
            ], 500);
        }
    }

    // User sends: { "group_id": 123 }
    // OR { "group_id": 123, "delete_type": "all" }
    public function deleteProductsFromGroup(Request $request){
        try{
            $groupId = $request->input('group_id');
            $deleteType = $request->input('delete_type', 'all'); // 'all' or 'specific'
            $specificProductIds = $request->input('product_ids'); // For specific delete

            if($deleteType == 'all'){
                // Delete all group product associations
                PostMetaModel::where("post_id", $groupId)
                    ->whereIn("meta_key", ["product_ids", "coupon_amount", "discount_type"])
                    ->delete();

                return response()->json([
                    "msg" => "All products removed from group",
                    "statuscode" => 200
                ], 200);
            }

            if($deleteType == 'specific' && $specificProductIds){
                // Get existing product_ids
                $existing = PostMetaModel::where("post_id", $groupId)
                    ->where("meta_key", "product_ids")
                    ->first();

                if($existing){
                    $existingIds = explode(',', $existing->meta_value);
                    $remainingIds = array_diff($existingIds, $specificProductIds);

                    if(count($remainingIds) > 0){
                        $remainingString = implode(',', $remainingIds);
                        $existing->update(["meta_value" => $remainingString]);
                    } else {
                        $existing->delete();
                    }
                }

                return response()->json([
                    "msg" => "Specific products removed from group",
                    "statuscode" => 200
                ], 200);
            }

            return response()->json([
                "msg" => "Invalid delete type or missing product_ids",
                "statuscode" => 400
            ], 400);

        } catch(\Exception $e){
            return response()->json([
                "msg" => $e->getMessage(),
                "statuscode" => 500
            ], 500);
        }
    }

    // User sends: { "group_id": 123 }
    public function getProductsInGroup(Request $request){
        try{
            $groupId = $request->input('group_id');

            // Get product_ids from group meta
            $productIdsMeta = PostMetaModel::where("post_id", $groupId)
                ->where("meta_key", "product_ids")
                ->first();

            if(!$productIdsMeta){
                return response()->json([
                    "msg" => "No products found in this group",
                    "statuscode" => 404
                ], 404);
            }

            $productIdsString = $productIdsMeta->meta_value;
            $productIds = explode(',', $productIdsString);

            // Get full product details from posts table
            $products = PostsModel::whereIn("ID", $productIds)
                ->where("post_type", "product")
                ->get(['ID', 'post_title', 'post_name']);

            // Get group discount info
            $amount = PostMetaModel::where("post_id", $groupId)
                ->where("meta_key", "coupon_amount")
                ->first();

            $discountType = PostMetaModel::where("post_id", $groupId)
                ->where("meta_key", "discount_type")
                ->first();

            return response()->json([
                "msg" => "Products retrieved successfully",
                "data" => [
                    "group_id" => $groupId,
                    "product_ids" => $productIds,
                    "products" => $products,
                    "discount" => [
                        "amount" => $amount ? $amount->meta_value : null,
                        "type" => $discountType ? $discountType->meta_value : null
                    ]
                ],
                "statuscode" => 200
            ], 200);

        } catch(\Exception $e){
            return response()->json([
                "msg" => $e->getMessage(),
                "statuscode" => 500
            ], 500);
        }
    }

    // get all products without and limit on their groups
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

    // User sends: { "organ_id": 123, "filter": "all" } // filter: all, used, unused
    public function getDiscountCodesByOrgan(Request $request){
        try{
            $organId = $request->input('organ_id');
            $filter = $request->input('filter', 'all'); // all, used, unused

            // Get organ prefix to identify its tokens
            $organPrefix = PostMetaModel::where("post_id", $organId)
                ->where("meta_key", "_prefix")
                ->first();

            if(!$organPrefix){
                return response()->json([
                    "msg" => "No prefix found for this organ",
                    "statuscode" => 404
                ], 404);
            }

            $prefix = $organPrefix->meta_value;

            // Find all coupon posts that start with this prefix
            $coupons = PostsModel::where("post_type", "shop_coupon")
                ->where("post_title", "LIKE", $prefix . "_%")
                ->get(['ID', 'post_title', 'post_date', 'post_modified']);

            if($coupons->isEmpty()){
                return response()->json([
                    "msg" => "No discount codes found for this organ",
                    "statuscode" => 404
                ], 404);
            }

            $couponIds = $coupons->pluck('ID')->toArray();

            // Get all relevant meta data for these coupons
            $metas = PostMetaModel::whereIn("post_id", $couponIds)
                ->whereIn("meta_key", ["product_ids", "coupon_amount", "discount_type", "expiry_date", "usage_limit", "usage_count"])
                ->get()
                ->groupBy('post_id');

            $results = [];
            foreach($coupons as $coupon){
                $couponMetas = $metas[$coupon->ID] ?? collect();

                $usageCount = (int)($couponMetas->where('meta_key', 'usage_count')->first()->meta_value ?? 0);
                $usageLimit = (int)($couponMetas->where('meta_key', 'usage_limit')->first()->meta_value ?? 1);
                $expiryDate = $couponMetas->where('meta_key', 'expiry_date')->first()->meta_value ?? null;

                $isExpired = $expiryDate && strtotime($expiryDate) < time();
                $isUsed = $usageCount >= $usageLimit;

                $status = 'active';
                if($isUsed) $status = 'used';
                if($isExpired) $status = 'expired';

                // Apply filter
                if($filter == 'used' && !$isUsed) continue;
                if($filter == 'unused' && $isUsed) continue;
                if($filter == 'active' && $status != 'active') continue;

                $productId = $couponMetas->where('meta_key', 'product_ids')->first()->meta_value ?? null;
                $product = null;

                if($productId){
                    $productPost = PostsModel::where("ID", $productId)
                        ->where("post_type", "product")
                        ->first(['ID', 'post_title']);

                    if($productPost){
                        $product = [
                            "id" => $productPost->ID,
                            "name" => $productPost->post_title
                        ];
                    }
                }

                $results[] = [
                    "id" => $coupon->ID,
                    "token" => $coupon->post_title,
                    "product" => $product,
                    "amount" => $couponMetas->where('meta_key', 'coupon_amount')->first()->meta_value ?? null,
                    "discount_type" => $couponMetas->where('meta_key', 'discount_type')->first()->meta_value ?? null,
                    "expiry_date" => $expiryDate,
                    "usage_limit" => $usageLimit,
                    "usage_count" => $usageCount,
                    "status" => $status,
                    "created_at" => $coupon->post_date
                ];
            }

            return response()->json([
                "msg" => "Discount codes retrieved successfully",
                "data" => [
                    "organ_id" => $organId,
                    "prefix" => $prefix,
                    "total" => count($results),
                    "filter" => $filter,
                    "codes" => $results
                ],
                "statuscode" => 200
            ], 200);

        } catch(\Exception $e){
            return response()->json([
                "msg" => $e->getMessage(),
                "statuscode" => 500
            ], 500);
        }
    }


}
