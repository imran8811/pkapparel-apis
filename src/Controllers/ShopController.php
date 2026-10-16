<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog;

/** The wholesale shop for the public website: styles, product pages and category texts. */
final class ShopController
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly Product $products,
        private readonly Category $categories,
    ) {
    }

    /** GET /v1/products?segment=men&type=jeans-pant&fit=&stretch=&q=&featured=1&sort=featured&page=1&per_page=24 */
    public function products(Request $request): Response
    {
        $query = Validator::make($request->allQuery(), [
            'segment' => 'nullable|in:' . implode(',', Product::SEGMENTS),
            'type' => 'nullable|in:' . implode(',', Product::TYPES),
            'fit' => 'nullable|string|max:60',
            'stretch' => 'nullable|in:stretch,non-stretch',
            'q' => 'nullable|string|max:80',
            'featured' => 'nullable|bool',
            'sort' => 'nullable|in:' . implode(',', Product::SORTS),
            'page' => 'nullable|int|min:1|max:1000',
            'per_page' => 'nullable|int|min:1|max:60',
        ])->validate();
        $page = $query['page'] ?? 1;
        $perPage = $query['per_page'] ?? 24;

        $result = $this->catalog->listing($query, $query['sort'] ?? 'featured', $page, $perPage);

        return Response::data($result['items'], [
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $result['total'], 'pages' => (int) ceil($result['total'] / $perPage)],
        ]);
    }

    /** GET /v1/products/{slug} */
    public function product(Request $request): Response
    {
        $product = $this->catalog->product((string) $request->param('slug'));
        if ($product === null) {
            throw HttpException::notFound('This product does not exist.');
        }

        return Response::data($product);
    }

    /** GET /v1/products/legacy/{article}: where an old product URL (…/{slug}-{article}) should redirect. */
    public function legacy(Request $request): Response
    {
        $target = $this->catalog->legacy((string) $request->param('article'));
        if ($target === null) {
            throw HttpException::notFound('No product has this article number.');
        }

        return Response::data($target);
    }

    /** GET /v1/shop/categories: texts of the segment and category pages, with the number of styles in each. */
    public function categories(Request $request): Response
    {
        $counts = [];
        foreach ($this->products->counts() as $row) {
            $counts[$row['segment']][$row['product_type']] = $row['count'];
        }

        return Response::data(array_map(static function (array $category) use ($counts): array {
            $segmentCounts = $counts[$category['segment']] ?? [];
            $styles = $category['product_type'] === '' ? array_sum($segmentCounts) : ($segmentCounts[$category['product_type']] ?? 0);
            unset($category['id'], $category['updated_at']);

            return $category + ['style_count' => $styles];
        }, $this->categories->all()));
    }
}
