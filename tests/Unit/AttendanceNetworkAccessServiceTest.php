<?php

declare(strict_types=1);

use App\Services\AttendanceNetworkAccessService;

test('attendance network restriction is disabled by default', function (): void {
    $service = new AttendanceNetworkAccessService();

    assert_true($service->isAllowed('198.51.100.40'));
    assert_true($service->isAllowed(''));
});

test('attendance network restriction allows configured exact IPv4 address', function (): void {
    $service = new AttendanceNetworkAccessService([
        'enabled' => true,
        'allowed_ips' => ['203.0.113.20'],
    ]);

    assert_true($service->isAllowed('203.0.113.20'));
    assert_false($service->isAllowed('203.0.113.21'));
});

test('attendance network restriction compares canonical IPv6 addresses', function (): void {
    $service = new AttendanceNetworkAccessService([
        'enabled' => '1',
        'allowed_ips' => '2001:db8::15, ::1',
    ]);

    assert_true($service->isAllowed('2001:0db8:0000:0000:0000:0000:0000:0015'));
    assert_true($service->isAllowed('0:0:0:0:0:0:0:1'));
    assert_false($service->isAllowed('2001:db8::16'));
});

test('enabled attendance network restriction fails closed without allowed addresses', function (): void {
    assert_false((new AttendanceNetworkAccessService([
        'enabled' => true,
        'allowed_ips' => '',
    ]))->isAllowed('127.0.0.1'));

    assert_false((new AttendanceNetworkAccessService([
        'enabled' => true,
        'allowed_ips' => [],
    ]))->isAllowed('127.0.0.1'));
});

test('enabled attendance network restriction fails closed when any configured address is invalid', function (): void {
    $service = new AttendanceNetworkAccessService([
        'enabled' => true,
        'allowed_ips' => ['127.0.0.1', 'not-an-ip-address'],
    ]);

    assert_false($service->isAllowed('127.0.0.1'));
    assert_false($service->isAllowed('not-an-ip-address'));
});

test('forwarded client address cannot override the immediate remote address', function (): void {
    $service = new AttendanceNetworkAccessService([
        'enabled' => true,
        'allowed_ips' => ['203.0.113.50'],
    ]);

    $server = [
        'REMOTE_ADDR' => '198.51.100.25',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.50',
        'HTTP_FORWARDED' => 'for=203.0.113.50',
        'HTTP_X_REAL_IP' => '203.0.113.50',
    ];

    assert_false($service->isAllowed($server['REMOTE_ADDR']));
});
