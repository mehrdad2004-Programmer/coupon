<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\v1\PostsController;

Route::prefix("v1")->middleware("auth:sanctum")->group(function(){

    // Create a new group/coupon with custom name, prefix, length, auth_token
    Route::post("/insert_new_group", [PostsController::class, "insertNewGroup"]);

    // Add products to a group with discount amount and type
    Route::post("/add_products_to_group", [PostsController::class, "addProductsToGroup"]);

    // Update/replace existing products and discount in a group
    Route::patch("/update_products_in_group", [PostsController::class, "updateProductsInGroup"]);

    // Remove products (all or specific) from a group
    Route::post("/delete_products_from_group", [PostsController::class, "deleteProductsFromGroup"]);

    // Get all products and discount info for a specific group
    Route::get("/get_products_in_group", [PostsController::class, "getProductsInGroup"]);

    // Generate unique token (UUID) with prefix, length, 48hr expiry, one-time use
    Route::post("/create_discount_code", [PostsController::class, "createDiscountCode"]);

    // Get all tokens for an organ with filter (all/used/unused/active)
    Route::get("/get_discount_codes_by_organ", [PostsController::class, "getDiscountCodesByOrgan"]);

    // Get all WooCommerce products (ID, title, slug)
    Route::get("/get_products", [PostsController::class, "getProducts"]);

    // Get all discount codes for a specific group with search by product
    Route::get("/get_discount_codes_by_group", [PostsController::class, "getDiscountCodesByGroup"]);

    // Get all groups
    Route::get("/get_groups", [PostsController::class, "getGroups"]);

});

Route::get("/unauthorized", [PostsController::class, "unauthorized"])->name('login');
Route::get("/login", [PostsController::class, "login"]);
