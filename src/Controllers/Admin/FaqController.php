<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Faq;
use App\Services\WebRevalidator;

/** /v1/admin/faqs */
final class FaqController extends ListContentController
{
    public function __construct(Faq $faqs, WebRevalidator $revalidator)
    {
        parent::__construct($faqs, $revalidator);
    }

    protected function rules(): array
    {
        return [
            'category' => 'required|in:' . implode(',', Faq::CATEGORIES),
            'question' => 'required|string|min:5|max:300',
            'answer' => 'required|string|min:2|max:5000',
        ];
    }

    protected function cacheTag(): string
    {
        return 'faqs';
    }

    protected function label(): string
    {
        return 'FAQ';
    }
}
