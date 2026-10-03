<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Subscribers;

use Igniter\Cart\Http\Requests\MenuRequest;
use Igniter\Cart\Models\Menu;
use IgniterLabs\SquarePOS\Models\SquareLink;
use Illuminate\Events\Dispatcher;
use Illuminate\Validation\Rule;

class MenuFormValidationSubscriber
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            'system.formRequest.extendValidator' => 'extendMenuValidator',
        ];
    }

    public function extendMenuValidator($formRequest, $dataHolder): void
    {
        if (!$formRequest instanceof MenuRequest) {
            return;
        }

        $dataHolder->attributes = array_merge($dataHolder->attributes, [
            'squareLink.square_sync' => lang('igniterlabs.squarepos::default.label_square_sync'),
            'squareLink.square_sku' => lang('igniterlabs.squarepos::default.label_square_sku'),
            'squareLink.square_stock_mode' => lang('igniterlabs.squarepos::default.label_square_stock_mode'),
        ]);

        $dataHolder->rules = array_merge($dataHolder->rules, [
            'squareLink.square_sync' => ['boolean'],
            'squareLink.square_sku' => ['nullable', 'string', 'max:255', $this->uniqueSkuRule($formRequest)],
            'squareLink.square_stock_mode' => ['nullable', Rule::in(['', 'push', 'square'])],
        ]);
    }

    protected function uniqueSkuRule($formRequest)
    {
        $type = (new Menu)->getMorphClass();
        $rule = Rule::unique((new SquareLink)->getTable(), 'square_sku')
            ->where('linkable_type', $type);

        $recordId = ($slug = $formRequest->route('slug')) ? str_after($slug, '/') : null;
        if (!is_numeric($recordId)) {
            return $rule;
        }

        $linkId = SquareLink::query()
            ->where('linkable_type', $type)
            ->where('linkable_id', $recordId)
            ->value('id');

        return $linkId ? $rule->ignore($linkId) : $rule;
    }
}
