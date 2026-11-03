<?php

declare(strict_types=1);

use App\Services\BrandingPresenter;

test('legacy product defaults are presented as OAAS without changing custom values', function (): void {
    assert_same(
        'Office Attendance and Analytic System',
        BrandingPresenter::companyValue(
            'company_name',
            'Smart Attendance And Performance Analytic System',
            'Office Attendance and Analytic System',
            'OAAS',
        ),
    );
    assert_same(
        'Office Attendance and Analytic System',
        BrandingPresenter::companyValue(
            'company_name',
            'Office Attendance and Performance Analytics System',
            'Office Attendance and Analytic System',
            'OAAS',
        ),
    );
    assert_same('OAAS', BrandingPresenter::companyValue(
        'short_name',
        'SAPAS',
        'Office Attendance and Analytic System',
        'OAAS',
    ));
    assert_same('OAAS Standard Office', BrandingPresenter::policyName('SAPAS Standard Office'));
    assert_same('OAAS Standard Office', BrandingPresenter::policyName('OAPAS Standard Office'));
    assert_same('Silver Oak Office', BrandingPresenter::companyValue(
        'company_name',
        'Silver Oak Office',
        'Office Attendance and Analytic System',
        'OAAS',
    ));
    assert_same('Flexible Operations', BrandingPresenter::policyName('Flexible Operations'));
});
