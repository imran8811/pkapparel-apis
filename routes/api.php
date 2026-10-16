<?php

declare(strict_types=1);

use App\Controllers\Admin;
use App\Controllers\ContentController;
use App\Controllers\HealthController;
use App\Controllers\InquiryController;
use App\Controllers\PageController;
use App\Controllers\PostController;
use App\Controllers\SiteController;
use App\Core\Router;
use App\Middleware\RequireClientKey;
use App\Middleware\RequireStaff;

/*
 * Every API route lives in this file.
 * Everything except /v1/health is only reachable by the website (X-Client-Key).
 * Admin routes also need a staff login token (Authorization: Bearer …).
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

        // Website forms
        $router->post('/inquiries', [InquiryController::class, 'store']);

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

            $router->get('/pages', [Admin\PageController::class, 'index']);
            $router->get('/pages/{slug:[a-z0-9-]+}', [Admin\PageController::class, 'show']);
            $router->put('/pages/{slug:[a-z0-9-]+}', [Admin\PageController::class, 'update']);

            $router->get('/settings', [Admin\SettingsController::class, 'index']);
            $router->put('/settings/{group:[a-z_]+}', [Admin\SettingsController::class, 'update']);

            $router->post('/uploads', [Admin\UploadController::class, 'store']);
        }, [RequireStaff::class]);
    }, [RequireClientKey::class]);
};
