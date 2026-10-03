<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\BulkActionWidgets;

use Igniter\Admin\Classes\BaseBulkActionWidget;
use IgniterLabs\SquarePOS\Models\SquareLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Override;

class SquareSync extends BaseBulkActionWidget
{
    #[Override]
    public function handleAction(array $requestData, Collection $records): void
    {
        [, $statusCode] = explode('.', (string)array_get($requestData, 'code'), 2);
        $enabled = $statusCode === 'enable';

        if (($count = $records->count()) === 0) {
            flash()->warning(sprintf(lang('igniter::admin.alert_error_nothing'), strtolower(lang('igniter::admin.text_'.$statusCode.'d'))));

            return;
        }

        DB::transaction(function() use ($records, $enabled): void {
            foreach ($records as $record) {
                $link = SquareLink::forOwner($record);
                $link->square_sync = $enabled;
                $link->square_dirty = true;
                SquareLink::saveOwner($record);
            }
        });

        $prefix = ($count > 1) ? ' records' : 'record';
        flash()->success(sprintf(
            lang('igniter::admin.alert_success'),
            '['.$count.']'.$prefix.' '.strtolower(lang('igniter::admin.text_'.$statusCode.'d'))
        ));
    }
}
