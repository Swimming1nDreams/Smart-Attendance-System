<?php

declare(strict_types=1);

use App\Services\AuditSourcePresenter;

test('audit source presenter classifies localhost private and public addresses', function (): void {
    assert_same('localhost', AuditSourcePresenter::classifyIp('::1'));
    assert_same('localhost', AuditSourcePresenter::classifyIp('127.0.0.1'));
    assert_same('localhost', AuditSourcePresenter::classifyIp('127.9.8.7'));
    assert_same('private_network', AuditSourcePresenter::classifyIp('10.0.0.2'));
    assert_same('private_network', AuditSourcePresenter::classifyIp('172.16.4.5'));
    assert_same('private_network', AuditSourcePresenter::classifyIp('172.31.4.5'));
    assert_same('private_network', AuditSourcePresenter::classifyIp('192.168.1.24'));
    assert_same('public_network', AuditSourcePresenter::classifyIp('8.8.8.8'));
    assert_same('unknown', AuditSourcePresenter::classifyIp('not an ip'));
});

test('audit source presenter summarizes stored user agents without geolocation', function (): void {
    $chromeWindows = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    $firefoxLinux = 'Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0';

    assert_same('Chrome · Windows', AuditSourcePresenter::summarizeUserAgent($chromeWindows));
    assert_same('Firefox · Linux', AuditSourcePresenter::summarizeUserAgent($firefoxLinux));
    assert_same(null, AuditSourcePresenter::summarizeUserAgent(''));
});
