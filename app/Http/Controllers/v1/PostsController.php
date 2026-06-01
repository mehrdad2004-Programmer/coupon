<?php

namespace App\Http\Controllers\v1;

use Illuminate\Http\Request;
use App\Models\v1\PostsModel;
use App\Models\v1\PostMetaModel;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use App\Models\v1\User;
use Illuminate\Support\Facades\Hash;


class PostsController extends Controller
{

    // User sends: { "group_name": "tigra", "auth_token": "x7k9m2", "prefix": "TIGRA", "length": 8 }
public function insertNewGroup(Request $request){
    try{
        // First, call login to get token
        $loginResponse = $this->login();
        $loginData = json_decode($loginResponse->getContent(), true);

        if($loginData['statuscode'] !== 200){
            return response()->json([
                "msg" => "Failed to authenticate",
                "statuscode" => 401
            ], 401);
        }

        $authToken = $loginData['token'];

        $groupName = $request->input('group_name');
        $prefix = $request->input('prefix');
        $length = $request->input('length');
        $expiryHours = $request->input('expiry_hours', 48);

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
            "post_content" => "",
            "post_title" => $groupName,
            "post_excerpt" => "",
            "post_status" => "publish",
            "comment_status" => "open",
            "ping_status" => "open",
            "post_password" => "",
            "post_name" => strtolower(str_replace(' ', '-', $groupName)),
            "to_ping" => "",
            "pinged" => "",
            "post_modified" => now(),
            "post_modified_gmt" => now(),
            "post_content_filtered" => "",
            "post_parent" => 0,
            "guid" => "",
            "menu_order" => 0,
            "post_type" => "shop_coupon",
            "post_mime_type" => "",
            "comment_count" => 0
        ]);

        // Store the token from login as _auth_token
        PostMetaModel::create([
            "post_id" => $inserted->ID,
            "meta_key" => "_auth_token",
            "meta_value" => $authToken
        ]);

        PostMetaModel::create([
            "post_id" => $inserted->ID,
            "meta_key" => "_prefix",
            "meta_value" => $prefix
        ]);

        PostMetaModel::create([
            "post_id" => $inserted->ID,
            "meta_key" => "_length",
            "meta_value" => $length
        ]);

        PostMetaModel::create([
            "post_id" => $inserted->ID,
            "meta_key" => "_expiry_hours",
            "meta_value" => $expiryHours
        ]);

        return response()->json([
            "msg" => "Group created successfully",
            "group_id" => $inserted->ID,
            "group_name" => $groupName,
            "auth_token" => $authToken,
            "statuscode" => 201
        ], 201);

    } catch(\Exception $e){
        return response()->json([
            "msg" => $e->getMessage(),
            "statuscode" => 500
        ], 500);
    }
}

public function getGroups(Request $request){
    try{
        // Get all shop_coupon posts
        $allCoupons = PostsModel::where('post_type', 'shop_coupon')
            ->get(['ID', 'post_title', 'post_status', 'post_date']);

        if($allCoupons->isEmpty()){
            return response()->json([
                "msg" => "No groups found",
                "statuscode" => 404
            ], 404);
        }

        // Filter only groups (those that have _prefix meta)
        $groups = [];
        foreach($allCoupons as $coupon){
            $hasPrefix = PostMetaModel::where("post_id", $coupon->ID)
                ->where("meta_key", "_prefix")
                ->exists();

            if($hasPrefix){
                $properties = PostMetaModel::where("post_id", $coupon->ID)
                    ->whereIn('meta_key', ['_prefix', '_length', '_expiry_hours', '_auth_token'])
                    ->get()
                    ->pluck('meta_value', 'meta_key');

                $coupon->prefix = $properties['_prefix'] ?? null;
                $coupon->length = $properties['_length'] ?? null;
                $coupon->expiry_hours = $properties['_expiry_hours'] ?? null;
                $coupon->auth_token = $properties['_auth_token'] ?? null;

                $groups[] = $coupon;
            }
        }

        if(empty($groups)){
            return response()->json([
                "msg" => "No groups found",
                "statuscode" => 404
            ], 404);
        }

        return response()->json([
            "msg" => "Groups retrieved successfully",
            "data" => $groups,
            "total" => count($groups),
            "statuscode" => 200
        ], 200);

    } catch(\Exception $e){
        return response()->json([
            "msg" => $e->getMessage(),
            "statuscode" => 500
        ], 500);
    }
}

    // User sends: { "group_id": 123, "product_ids": [123,456,789], "amount": 20, "discount_type": "percent" }
// User sends: { "group_id": 14, "products": [{"id": 17, "amount": 50}, {"id": 18, "amount": 30}, {"id": 19, "amount": 20}], "discount_type": "percent" }
public function addProductsToGroup(Request $request){
    try{
        $groupId = $request->input('group_id');
        $products = $request->input('products'); // Array of {id, amount}
        $discountType = $request->input('discount_type');

        // Check if group exists
        $group = PostsModel::where('ID', $groupId)
            ->where('post_type', 'shop_coupon')
            ->first();

        if(!$group){
            return response()->json([
                "msg" => "Group not found",
                "statuscode" => 404
            ], 404);
        }

        // Extract product IDs
        $productIds = array_column($products, 'id');

        // Check if all product IDs exist
        $existingProducts = PostsModel::whereIn('ID', $productIds)
            ->where('post_type', 'product')
            ->pluck('ID')
            ->toArray();

        $nonExistentIds = array_diff($productIds, $existingProducts);

        if(!empty($nonExistentIds)){
            return response()->json([
                "msg" => "One or more product IDs do not exist",
                "non_existent_product_ids" => array_values($nonExistentIds),
                "statuscode" => 400
            ], 400);
        }

        // Get existing product_ids from group
        $existing = PostMetaModel::where("post_id", $groupId)
            ->where("meta_key", "product_ids")
            ->first();

        $existingIds = [];
        if($existing){
            $existingIds = explode(',', $existing->meta_value);
        }

        // Check for duplicates
        $duplicateIds = array_intersect($productIds, $existingIds);

        if(!empty($duplicateIds)){
            $duplicateProducts = PostsModel::whereIn('ID', $duplicateIds)
                ->where('post_type', 'product')
                ->get(['ID', 'post_title'])
                ->map(function($product) {
                    return [
                        "id" => $product->ID,
                        "title" => $product->post_title
                    ];
                });

            return response()->json([
                "msg" => "One or more products already exist in this group",
                "duplicates" => $duplicateProducts,
                "statuscode" => 400
            ], 400);
        }

        // Merge existing and new product IDs
        $allProductIds = array_merge($existingIds, $productIds);
        $productIdsString = implode(',', $allProductIds);

        // Update product_ids in group
        PostMetaModel::updateOrCreate(
            ["post_id" => $groupId, "meta_key" => "product_ids"],
            ["meta_value" => $productIdsString]
        );

        // For each product, create or update its discount amount
        foreach($products as $product){
            $productId = $product['id'];
            $amount = $product['amount'];

            // Check if token already exists for this product
            $groupPrefix = PostMetaModel::where("post_id", $groupId)
                ->where("meta_key", "_prefix")
                ->first();

            if($groupPrefix){
                $prefix = $groupPrefix->meta_value;

                $existingToken = PostsModel::where("post_type", "shop_coupon")
                    ->where("post_title", "LIKE", $prefix . "_%")
                    ->whereHas('meta', function($query) use ($productId){
                        $query->where("meta_key", "product_ids")
                            ->where("meta_value", $productId);
                    })
                    ->first();

                if($existingToken){
                    // Update existing token amount
                    PostMetaModel::updateOrCreate(
                        ["post_id" => $existingToken->ID, "meta_key" => "coupon_amount"],
                        ["meta_value" => $amount]
                    );
                } else {
                    // Create new token for this product with its specific amount
                    $this->createDiscountCode(new Request([
                        'organ_id' => $groupId,
                        'product_id' => $productId,
                        'amount' => $amount,
                        'discount_type' => $discountType
                    ]));
                }
            }
        }

        return response()->json([
            "msg" => "Products added to group successfully with individual discounts",
            "data" => [
                "group_id" => $groupId,
                "products" => $products,
                "discount_type" => $discountType
            ],
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
// User sends: { "group_id": 123, "products": [{"id": 123, "amount": 20}, {"id": 456, "amount": 15}, {"id": 789, "amount": 10}], "discount_type": "percent" }
// User sends: { "group_id": 123, "products": [{"id": 123, "amount": 20}, {"id": 456, "amount": 15}], "discount_type": "percent" }
public function updateProductsInGroup(Request $request){
    try{
        $groupId = $request->input('group_id');
        $products = $request->input('products');
        $discountType = $request->input('discount_type');

        // Get existing product_ids from group
        $existing = PostMetaModel::where("post_id", $groupId)
            ->where("meta_key", "product_ids")
            ->first();

        $existingIds = [];
        if($existing){
            $existingIds = explode(',', $existing->meta_value);
        }

        $newProductIds = [];
        foreach($products as $product){
            $newProductIds[] = $product['id'];
        }

        // Check for products to remove
        $productsToRemove = array_diff($existingIds, $newProductIds);

        // Get group prefix
        $groupPrefix = PostMetaModel::where("post_id", $groupId)
            ->where("meta_key", "_prefix")
            ->first();

        if($groupPrefix){
            $prefix = $groupPrefix->meta_value;

            // Find all tokens for this group
            $tokens = PostsModel::where("post_type", "shop_coupon")
                ->where("post_title", "LIKE", $prefix . "_%")
                ->get();

            foreach($tokens as $token){
                // Get product_id for this token
                $tokenProductId = PostMetaModel::where("post_id", $token->ID)
                    ->where("meta_key", "product_ids")
                    ->first();

                if($tokenProductId){
                    // If product is being removed, delete token
                    if(in_array($tokenProductId->meta_value, $productsToRemove)){
                        PostMetaModel::where("post_id", $token->ID)->delete();
                        $token->delete();
                    }

                    // Update amount for existing products
                    foreach($products as $product){
                        if($product['id'] == $tokenProductId->meta_value){
                            PostMetaModel::updateOrCreate(
                                ["post_id" => $token->ID, "meta_key" => "coupon_amount"],
                                ["meta_value" => $product['amount']]
                            );
                        }
                    }
                }
            }
        }

        // Update product_ids in group
        $productIdsString = implode(',', $newProductIds);
        PostMetaModel::updateOrCreate(
            ["post_id" => $groupId, "meta_key" => "product_ids"],
            ["meta_value" => $productIdsString]
        );

        // Update discount_type
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
// get all products in a specific group with search by id or name
public function getProductsInGroup(Request $request){
    try{
        $groupId = $request->input('group_id');
        $searchId = $request->input('product_id'); // search by product ID
        $searchName = $request->input('product_name'); // search by product name

        // Check if group exists
        $group = PostsModel::where('ID', $groupId)
            ->where('post_type', 'shop_coupon')
            ->first();

        if(!$group){
            return response()->json([
                "msg" => "Group not found",
                "statuscode" => 404
            ], 404);
        }

        // Get product_ids from group meta
        $productIdsMeta = PostMetaModel::where("post_id", $groupId)
            ->where("meta_key", "product_ids")
            ->first();

        if(!$productIdsMeta || empty($productIdsMeta->meta_value)){
            return response()->json([
                "msg" => "No products found in this group",
                "statuscode" => 404
            ], 404);
        }

        $productIds = explode(',', $productIdsMeta->meta_value);

        // Query products
        $query = PostsModel::whereIn('ID', $productIds)
            ->where('post_type', 'product');

        // Apply search filters
        if($searchId){
            $query->where('ID', $searchId);
        }

        if($searchName){
            $query->where('post_title', 'LIKE', "%{$searchName}%");
        }

        $products = $query->get();

        if($products->isEmpty()){
            return response()->json([
                "msg" => "No products match the search criteria",
                "statuscode" => 404
            ], 404);
        }

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
                "group_name" => $group->post_title,
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

// get all products with optional search by title
public function getProducts(Request $request){
    try{
        $search = $request->input('search'); // optional search parameter

        $query = PostsModel::where("post_type", "product");

        // Add search condition if provided
        if($search){
            $query->where("post_title", "LIKE", "%{$search}%");
        }

        $data = $query->get(); // removed select, now returns all columns

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


    // User sends: { "organ_id": 123, "filter": "all" } // filter: all, used, unused
public function createDiscountCode(Request $request){
    try{
        // Get bearer token from header
        $authKey = $request->bearerToken();

        $organId = $request->input('organ_id');
        $productId = $request->input('product_id');

        // Check if organ exists
        $organ = PostsModel::where('ID', $organId)
            ->where('post_type', 'shop_coupon')
            ->first();

        if(!$organ){
            return response()->json([
                "msg" => "Organ not found",
                "statuscode" => 404
            ], 404);
        }

        // Validate bearer token matches the stored _auth_token
        $storedAuthKey = PostMetaModel::where("post_id", $organId)
            ->where("meta_key", "_auth_token")
            ->first();

        if(!$storedAuthKey || $storedAuthKey->meta_value !== $authKey){
            return response()->json([
                "msg" => "Unauthorized: Invalid bearer token for this organ",
                "statuscode" => 401
            ], 401);
        }

        // Get product name
        $product = PostsModel::where('ID', $productId)
            ->where('post_type', 'product')
            ->first(['ID', 'post_title']);

        if(!$product){
            return response()->json([
                "msg" => "Product not found",
                "statuscode" => 404
            ], 404);
        }

        // Get the stored amount and discount_type for this product from group settings
        // First, get the group's discount_type (same for all products in group)
        $discountType = PostMetaModel::where("post_id", $organId)
            ->where("meta_key", "discount_type")
            ->first();

        $discountTypeValue = $discountType ? $discountType->meta_value : 'percent';

        // Get the specific amount for this product
        // You need to store product amounts somewhere. Where are they stored?
        // Option 1: In a separate table
        // Option 2: In postmeta with key like "_product_amount_{productId}"

        $amount = PostMetaModel::where("post_id", $organId)
            ->where("meta_key", "_product_amount_" . $productId)
            ->first();

        $amountValue = $amount ? $amount->meta_value : 20;

        // Get organ properties
        $organMeta = PostMetaModel::where("post_id", $organId)
            ->whereIn('meta_key', ['_prefix', '_length', '_expiry_hours'])
            ->get()
            ->pluck('meta_value', 'meta_key');

        $prefix = $organMeta['_prefix'] ?? 'ORG';
        $length = (int)($organMeta['_length'] ?? 8);
        $expiryHours = (int)($organMeta['_expiry_hours'] ?? 48);

        // Generate unique token
        $unique = false;
        $token = "";

        while(!$unique){
            $uuid = str_replace('-', '', (string) Str::uuid());
            $randomCode = substr($uuid, 0, $length);
            $token = $prefix . '_' . $randomCode;

            // Check if token string already exists
            $exists = PostsModel::where('post_title', $token)
                ->where('post_type', 'shop_coupon')
                ->exists();

            if(!$exists){
                $unique = true;
            }
        }

        // Create the discount code post
        $inserted = PostsModel::create([
            "post_author" => 1,
            "post_date" => now(),
            "post_date_gmt" => now(),
            "post_content" => "",
            "post_title" => $token,
            "post_excerpt" => "",
            "post_status" => "publish",
            "comment_status" => "open",
            "ping_status" => "open",
            "post_password" => "",
            "post_name" => strtolower($token),
            "to_ping" => "",
            "pinged" => "",
            "post_modified" => now(),
            "post_modified_gmt" => now(),
            "post_content_filtered" => "",
            "post_parent" => 0,
            "guid" => "",
            "menu_order" => 0,
            "post_type" => "shop_coupon",
            "post_mime_type" => "",
            "comment_count" => 0
        ]);

        // Add native WooCommerce meta using stored values
        PostMetaModel::create([
            "post_id" => $inserted->ID,
            "meta_key" => "product_ids",
            "meta_value" => $productId
        ]);

        PostMetaModel::create([
            "post_id" => $inserted->ID,
            "meta_key" => "coupon_amount",
            "meta_value" => $amountValue
        ]);

        PostMetaModel::create([
            "post_id" => $inserted->ID,
            "meta_key" => "discount_type",
            "meta_value" => $discountTypeValue
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
            "product_id" => $productId,
            "product_name" => $product->post_title,
            "amount" => $amountValue,
            "discount_type" => $discountTypeValue,
            "group_id" => $organId,
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


// get all discount codes for a specific group with search by product
// get all discount codes for a specific group with search by product
public function getDiscountCodesByGroup(Request $request){
    try{
        $groupId = $request->input('group_id');
        $searchProductId = $request->input('product_id');
        $searchProductName = $request->input('product_name');
        $filter = $request->input('filter', 'all'); // all, used, unused, expired, active

        // Check if group exists
        $group = PostsModel::where('ID', $groupId)
            ->where('post_type', 'shop_coupon')
            ->first();

        if(!$group){
            return response()->json([
                "msg" => "Group not found",
                "statuscode" => 404
            ], 404);
        }

        // Get group prefix
        $groupPrefix = PostMetaModel::where("post_id", $groupId)
            ->where("meta_key", "_prefix")
            ->first();

        if(!$groupPrefix){
            return response()->json([
                "msg" => "No prefix found for this group",
                "statuscode" => 404
            ], 404);
        }

        $prefix = $groupPrefix->meta_value;

        // Find all coupon posts that start with this prefix
        $coupons = PostsModel::where("post_type", "shop_coupon")
            ->where("post_title", "LIKE", $prefix . "_%")
            ->get(['ID', 'post_title', 'post_date']);

        if($coupons->isEmpty()){
            return response()->json([
                "msg" => "No discount codes found for this group",
                "statuscode" => 404
            ], 404);
        }

        $couponIds = $coupons->pluck('ID')->toArray();

        // Get all relevant meta data for these coupons
        $metas = PostMetaModel::whereIn("post_id", $couponIds)
            ->whereIn("meta_key", ["product_ids", "coupon_amount", "discount_type", "expiry_date", "usage_limit", "usage_count"])
            ->get()
            ->groupBy('post_id');

        // Get all product IDs from these coupons
        $allProductIds = [];
        foreach($metas as $couponMetas){
            $productId = $couponMetas->where('meta_key', 'product_ids')->first()->meta_value ?? null;
            if($productId){
                $allProductIds[] = $productId;
            }
        }
        $allProductIds = array_unique($allProductIds);

        // Get product details for all products
        $products = PostsModel::whereIn('ID', $allProductIds)
            ->where('post_type', 'product')
            ->get(['ID', 'post_title'])
            ->keyBy('ID');

        $results = [];
        foreach($coupons as $coupon){
            $couponMetas = $metas[$coupon->ID] ?? collect();

            $productId = $couponMetas->where('meta_key', 'product_ids')->first()->meta_value ?? null;
            $product = $products[$productId] ?? null;

            // Apply product filters
            if($searchProductId && $productId != $searchProductId){
                continue;
            }

            if($searchProductName && $product && stripos($product->post_title, $searchProductName) === false){
                continue;
            }

            $usageCount = (int)($couponMetas->where('meta_key', 'usage_count')->first()->meta_value ?? 0);
            $usageLimit = (int)($couponMetas->where('meta_key', 'usage_limit')->first()->meta_value ?? 1);
            $expiryDate = $couponMetas->where('meta_key', 'expiry_date')->first()->meta_value ?? null;

            $isExpired = $expiryDate && strtotime($expiryDate) < time();
            $isUsed = $usageCount >= $usageLimit;

            $status = 'active';
            if($isUsed) $status = 'used';
            if($isExpired && !$isUsed) $status = 'expired';

            // Apply status filter
            if($filter == 'used' && !$isUsed) continue;
            if($filter == 'unused' && ($isUsed || $isExpired)) continue;
            if($filter == 'expired' && !($isExpired && !$isUsed)) continue;
            if($filter == 'active' && $status != 'active') continue;

            $results[] = [
                "id" => $coupon->ID,
                "token" => $coupon->post_title,
                "product" => $product ? [
                    "id" => $product->ID,
                    "name" => $product->post_title
                ] : null,
                "amount" => $couponMetas->where('meta_key', 'coupon_amount')->first()->meta_value ?? null,
                "discount_type" => $couponMetas->where('meta_key', 'discount_type')->first()->meta_value ?? null,
                "expiry_date" => $expiryDate,
                "usage_limit" => $usageLimit,
                "usage_count" => $usageCount,
                "status" => $status,
                "created_at" => $coupon->post_date
            ];
        }

        if(empty($results)){
            return response()->json([
                "msg" => "No discount codes match the search criteria",
                "statuscode" => 404
            ], 404);
        }

        return response()->json([
            "msg" => "Discount codes retrieved successfully",
            "data" => [
                "group_id" => $groupId,
                "group_name" => $group->post_title,
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

public function unauthorized(){
    return response()->json([
        "msg" => "unauthorized",
        "statuscode" => 401
    ], 401);
}

public function login(){
    try{

        $user = User::where('name', "admin")->first();

        if(!$user || !Hash::check("978Cj@89", $user->password)){
            return response()->json([
                "msg" => "Invalid credentials",
                "statuscode" => 401
            ], 401);
        }

        // Create new token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            "msg" => "Login successful",
            "token" => $token,
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
