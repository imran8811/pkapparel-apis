<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Order;
use App\Models\OrderPhoto;
use App\Models\OrderStage;
use App\Services\Production;

/** Production updates from the factory floor (phones): stages and photos. Production staff, sales staff and admins. */
final class ProductionController extends AdminController
{
    public function __construct(
        private readonly Order $orders,
        private readonly OrderPhoto $photos,
        private readonly Production $production,
    ) {
    }

    /** PUT /v1/admin/orders/{id}/stages/{stage} {action: start|done|reset, date, note, notify} */
    public function stage(Request $request): Response
    {
        $this->authorize($request, 'sales', 'production');
        $data = Validator::make($request->all(), [
            'action' => 'required|in:start,done,reset',
            'date' => 'nullable|date',
            'note' => 'nullable|string|max:500',
            'notify' => 'nullable|bool',
        ])->validate();
        $stage = (string) $request->param('stage');
        if (!isset(OrderStage::STAGES[$stage])) {
            throw HttpException::notFound('Unknown production stage.');
        }
        $order = $this->order($request);
        $this->production->updateStage($order, $this->staff($request), $stage, $data['action'], $data['date'] ?? Production::today(), $data['note'] ?? null, (bool) ($data['notify'] ?? false));

        return Response::noContent();
    }

    /** POST /v1/admin/orders/{id}/photos (multipart: photo, stage, caption) */
    public function upload(Request $request): Response
    {
        $this->authorize($request, 'sales', 'production');
        $data = Validator::make($request->all(), [
            'stage' => 'nullable|in:' . implode(',', array_keys(OrderStage::STAGES)),
            'caption' => 'nullable|string|max:300',
        ])->validate();
        $upload = $request->files()['photo'] ?? null;

        return Response::data(
            $this->production->addPhoto($this->order($request), $this->staff($request), is_array($upload) ? $upload : null, $data['stage'] ?? null, $data['caption'] ?? null),
            [],
            201,
        );
    }

    /** GET /v1/admin/order-photos/{id}?size=preview|full */
    public function file(Request $request): Response
    {
        $this->authorize($request, 'sales', 'production');
        $photo = $this->photo($request);
        $content = $this->production->photoFile($photo, $request->query('size') !== 'full');
        if ($content === null) {
            throw HttpException::notFound('This photo file is missing.');
        }

        return Response::file($content, 'image/jpeg', "photo-{$photo['id']}.jpg", true);
    }

    /** DELETE /v1/admin/order-photos/{id} */
    public function destroy(Request $request): Response
    {
        $this->authorize($request, 'sales', 'production');
        $this->production->deletePhoto($this->photo($request));

        return Response::noContent();
    }

    /** @return array<string, mixed> */
    private function photo(Request $request): array
    {
        $photo = $this->photos->find($this->id($request));
        if ($photo === null) {
            throw HttpException::notFound('This photo does not exist.');
        }

        return $photo;
    }

    /** @return array<string, mixed> */
    private function order(Request $request): array
    {
        $order = $this->orders->find($this->id($request));
        if ($order === null) {
            throw HttpException::notFound('This order does not exist.');
        }

        return $order;
    }
}
