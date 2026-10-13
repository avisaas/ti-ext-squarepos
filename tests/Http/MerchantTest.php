<?php

use Igniter\User\Models\User;
use IgniterLabs\SquarePOS\Http\Controllers\Merchant;
use IgniterLabs\SquarePOS\Http\Controllers\Tasks;
use IgniterLabs\SquarePOS\Jobs\SyncCatalogJob;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Tests\Support\Square;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

it('connects, stores a token, disconnects, and queues import and export', function(): void {
    Square::configureSquare();
    Bus::fake();
    $this->actingAs(User::factory()->superUser()->create(), 'igniter-admin');

    Http::fake([
        '*/connect/token/square' => Http::sequence()
            ->push([
                'access_token' => 'connected-token',
                'merchant_id' => 'M',
                'refresh_token' => 'refresh-token',
                'expires_at' => '2030-01-01T00:00:00Z',
                'token_type' => 'bearer',
            ])
            ->push([
                'error_message' => 'Invalid or expired code',
            ]),
    ]);

    $merchant = new Merchant;
    $connect = $merchant->connect(null);
    expect($connect->getTargetUrl())->toContain('authorize/square')
        ->and($merchant->getSession('state'))->not->toBeEmpty();

    request()->merge([
        'state' => $merchant->getSession('state'),
        'code' => 'exchange-code',
        'error_message' => '',
    ]);
    $merchant->token(null);
    expect(Settings::accessToken())->toBe('connected-token')
        ->and(Settings::connectionData('refresh_token'))->toBe('refresh-token')
        ->and(Settings::connectionData('merchant_id'))->toBe('M');

    Http::assertSent(fn($request): bool => $request['code'] === 'exchange-code');

    request()->merge(['error_message' => 'denied']);
    $merchant->token(null);

    request()->merge(['state' => 'wrong', 'error_message' => '']);
    $merchant->token(null);

    $merchant->putSession('redirect', null);
    $merchant->token(null);

    expect($merchant->import()->getTargetUrl())->toContain('squarepos/settings');
    $merchant->export();
    Bus::assertDispatched(SyncCatalogJob::class);

    Square::configureSquare(['connection_status' => false]);
    Bus::fake();
    $merchant->import();
    $merchant->export();
    Bus::assertNothingDispatched();

    $merchant->revoke(null);
    expect(Settings::isConnected())->toBeFalse();

    $merchant->putSession('state', 'again');
    request()->merge([
        'state' => 'again',
        'code' => '',
        'error_message' => '',
    ]);
    $merchant->token(null);
    expect(Settings::isConnected())->toBeFalse();

    request()->merge([
        'state' => 'again',
        'code' => 'expired-code',
        'error_message' => '',
    ]);
    $merchant->token(null);
    expect(Settings::isConnected())->toBeFalse();

    expect(new Tasks)->toBeInstanceOf(Tasks::class);
});
