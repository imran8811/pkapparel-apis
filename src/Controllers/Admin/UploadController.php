<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Services\ImageStore;

/** POST /v1/admin/uploads (multipart: file, folder): images for posts, pages, factory and certificates. */
final class UploadController extends AdminController
{
    public function __construct(private readonly ImageStore $images)
    {
    }

    public function store(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $folder = Validator::make($request->all(), [
            'folder' => 'required|in:' . implode(',', array_keys(ImageStore::FOLDERS)),
        ])->validate()['folder'];

        $file = $request->files()['file'] ?? null;
        if (!is_array($file) || !is_string($file['tmp_name'] ?? null)) {
            throw new ValidationException(['file' => 'Choose an image to upload.']);
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The image is too large. The limit is 10 MB.',
                UPLOAD_ERR_NO_FILE => 'Choose an image to upload.',
                default => 'The upload did not finish. Please try again.',
            }]);
        }
        // Real uploads must come from PHP's upload handling (tests run on the command line).
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($file['tmp_name'])) {
            throw HttpException::badRequest('The upload is not valid.');
        }

        return Response::data($this->images->store($file['tmp_name'], (string) ($file['name'] ?? 'image'), $folder), [], 201);
    }
}
