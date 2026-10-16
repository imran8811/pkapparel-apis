<?php

declare(strict_types=1);

use App\Controllers\Account;
use App\Controllers\Admin;
use App\Controllers\CartController;
use App\Controllers\ContentController;
use App\Controllers\HealthController;
use App\Controllers\InquiryController;
use App\Controllers\PageController;
use App\Controllers\PostController;
use App\Controllers\ShopController;
use App\Controllers\SiteController;
use App\Core\Router;
use App\Middleware\IdentifyBuyer;
use App\Middleware\RequireClientKey;
use App\Middleware\RequireCustomer;
use App\Middleware\RequireStaff;

/*
 * Every API route lives in this file.
 * Everything except /v1/health is only reachable by the website (X-Client-Key).
 * Buyer account routes need a buyer login token, admin routes a staff login token (Authorization: Bearer …).
 * Cart routes work for guests too: their cart is found by the token of their cart cookie (X-Cart-Token).
 */
return static function (Router $router): void {
    $router->get('/v1/health', [HealthController::class, 'show']);

    $router->group('/v1', static function (Router $router): void {
        // Site-wide settings
        $router->get('/site', [SiteController::class, 'show']);

        // Marketing content
        $router->get('/faqs', [ContentController::class, 'faqs']);
        $router->get('/testimonials', [ContentController::class, 'testimonials']);
        $router->get('/certifications', [ContentController::class, 'certifications']);
        $router->get('/factory', [ContentController::class, 'factory']);
        $router->get('/pages/{slug:[a-z0-9-]+}', [PageController::class, 'show']);

        // Blog
        $router->get('/posts', [PostController::class, 'index']);
        $router->get('/posts/{slug:[a-z0-9-]+}', [PostController::class, 'show']);

        // Wholesale shop
        $router->get('/products', [ShopController::class, 'products']);
        $router->get('/products/legacy/{article:[A-Za-z0-9-]+}', [ShopController::class, 'legacy']);
        $router->get('/products/{slug:[a-z0-9-]+}', [ShopController::class, 'product']);
        $router->get('/shop/categories', [ShopController::class, 'categories']);

        // Website forms
        $router->post('/inquiries', [InquiryController::class, 'store']);

        // Cart (guests and buyers)
        $router->group('/cart', static function (Router $router): void {
            $router->get('', [CartController::class, 'show']);
            $router->post('/lines', [CartController::class, 'add']);
            $router->put('/lines/{colorId:\d+}', [CartController::class, 'update']);
            $router->delete('/lines/{colorId:\d+}', [CartController::class, 'destroy']);
        }, [IdentifyBuyer::class]);

        // Buyer accounts
        $router->post('/account/signup', [Account\AuthController::class, 'signup']);
        $router->post('/account/login', [Account\AuthController::class, 'login']);
        $router->post('/account/verify-email', [Account\AuthController::class, 'verifyEmail']);
        $router->post('/account/forgot-password', [Account\AuthController::class, 'forgotPassword']);
        $router->post('/account/reset-password', [Account\AuthController::class, 'resetPassword']);

        $router->group('/account', static function (Router $router): void {
            $router->get('', [Account\ProfileController::class, 'show']);
            $router->put('', [Account\ProfileController::class, 'update']);
            $router->put('/password', [Account\ProfileController::class, 'changePassword']);
            $router->post('/logout', [Account\AuthController::class, 'logout']);
            $router->post('/verify-email/resend', [Account\AuthController::class, 'resendVerification']);

            $router->get('/addresses', [Account\AddressController::class, 'index']);
            $router->post('/addresses', [Account\AddressController::class, 'store']);
            $router->put('/addresses/{id:\d+}', [Account\AddressController::class, 'update']);
            $router->delete('/addresses/{id:\d+}', [Account\AddressController::class, 'destroy']);

            $router->get('/checkout', [Account\OrderController::class, 'checkout']);
            $router->get('/orders', [Account\OrderController::class, 'index']);
            $router->post('/orders', [Account\OrderController::class, 'store']);
            $router->get('/orders/{orderNo:PK-[0-9-]+}', [Account\OrderController::class, 'show']);
            $router->get('/orders/{orderNo:PK-[0-9-]+}/proforma', [Account\OrderController::class, 'proforma']);
            $router->post('/orders/{orderNo:PK-[0-9-]+}/cancel', [Account\OrderController::class, 'cancel']);
            $router->post('/orders/{orderNo:PK-[0-9-]+}/payments', [Account\OrderController::class, 'submitPayment']);
        }, [RequireCustomer::class]);

        // Admin panel
        $router->post('/admin/auth/login', [Admin\AuthController::class, 'login']);

        $router->group('/admin', static function (Router $router): void {
            $router->get('/auth/me', [Admin\AuthController::class, 'me']);
            $router->post('/auth/logout', [Admin\AuthController::class, 'logout']);
            $router->put('/auth/password', [Admin\AuthController::class, 'changePassword']);

            $router->get('/dashboard', [Admin\DashboardController::class, 'show']);

            $router->get('/inquiries', [Admin\InquiryController::class, 'index']);
            $router->get('/inquiries/{id:\d+}', [Admin\InquiryController::class, 'show']);
            $router->patch('/inquiries/{id:\d+}', [Admin\InquiryController::class, 'update']);

            foreach (['faqs' => Admin\FaqController::class, 'testimonials' => Admin\TestimonialController::class, 'certifications' => Admin\CertificationController::class] as $path => $controller) {
                $router->get("/{$path}", [$controller, 'index']);
                $router->post("/{$path}", [$controller, 'store']);
                $router->put("/{$path}/{id:\\d+}", [$controller, 'update']);
                $router->delete("/{$path}/{id:\\d+}", [$controller, 'destroy']);
            }

            $router->get('/factory/departments', [Admin\FactoryController::class, 'index']);
            $router->get('/factory/departments/{slug:[a-z0-9-]+}', [Admin\FactoryController::class, 'show']);
            $router->put('/factory/departments/{slug:[a-z0-9-]+}', [Admin\FactoryController::class, 'update']);

            $router->get('/posts', [Admin\PostController::class, 'index']);
            $router->post('/posts', [Admin\PostController::class, 'store']);
            $router->get('/posts/{id:\d+}', [Admin\PostController::class, 'show']);
            $router->put('/posts/{id:\d+}', [Admin\PostController::class, 'update']);
            $router->delete('/posts/{id:\d+}', [Admin\PostController::class, 'destroy']);

            $router->get('/products', [Admin\ProductController::class, 'index']);
            $router->post('/products', [Admin\ProductController::class, 'store']);
            $router->get('/products/{id:\d+}', [Admin\ProductController::class, 'show']);
            $router->put('/products/{id:\d+}', [Admin\ProductController::class, 'update']);
            $router->delete('/products/{id:\d+}', [Admin\ProductController::class, 'destroy']);
            $router->post('/products/{id:\d+}/colors', [Admin\ProductColorController::class, 'store']);
            $router->put('/product-colors/{id:\d+}', [Admin\ProductColorController::class, 'update']);
            $router->delete('/product-colors/{id:\d+}', [Admin\ProductColorController::class, 'destroy']);

            $router->get('/size-charts', [Admin\SizeChartController::class, 'index']);
            $router->post('/size-charts', [Admin\SizeChartController::class, 'store']);
            $router->put('/size-charts/{id:\d+}', [Admin\SizeChartController::class, 'update']);
            $router->delete('/size-charts/{id:\d+}', [Admin\SizeChartController::class, 'destroy']);

            $router->get('/categories', [Admin\CategoryController::class, 'index']);
            $router->put('/categories/{id:\d+}', [Admin\CategoryController::class, 'update']);

            $router->get('/pages', [Admin\PageController::class, 'index']);
            $router->get('/pages/{slug:[a-z0-9-]+}', [Admin\PageController::class, 'show']);
            $router->put('/pages/{slug:[a-z0-9-]+}', [Admin\PageController::class, 'update']);

            $router->get('/customers', [Admin\CustomerController::class, 'index']);
            $router->get('/customers/{id:\d+}', [Admin\CustomerController::class, 'show']);
            $router->patch('/customers/{id:\d+}', [Admin\CustomerController::class, 'update']);

            $router->get('/orders', [Admin\OrderController::class, 'index']);
            $router->get('/orders/{id:\d+}', [Admin\OrderController::class, 'show']);
            $router->get('/orders/{id:\d+}/proforma', [Admin\OrderController::class, 'proforma']);
            $router->get('/payments/{id:\d+}/slip', [Admin\OrderController::class, 'slip']);

            $router->get('/shipping/zones', [Admin\ShippingController::class, 'index']);
            $router->post('/shipping/zones', [Admin\ShippingController::class, 'store']);
            $router->put('/shipping/zones/{id:\d+}', [Admin\ShippingController::class, 'update']);
            $router->delete('/shipping/zones/{id:\d+}', [Admin\ShippingController::class, 'destroy']);

            $router->get('/addons', [Admin\AddonController::class, 'index']);
            $router->post('/addons', [Admin\AddonController::class, 'store']);
            $router->put('/addons/{id:\d+}', [Admin\AddonController::class, 'update']);
            $router->delete('/addons/{id:\d+}', [Admin\AddonController::class, 'destroy']);

            $router->get('/settings', [Admin\SettingsController::class, 'index']);
            $router->put('/settings/{group:[a-z_]+}', [Admin\SettingsController::class, 'update']);

            $router->post('/uploads', [Admin\UploadController::class, 'store']);
        }, [RequireStaff::class]);
    }, [RequireClientKey::class]);
};
