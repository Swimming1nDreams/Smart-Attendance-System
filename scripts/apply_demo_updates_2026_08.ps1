$ErrorActionPreference = 'Stop'
$env:SAPAS_ALLOW_DEMO_SEED = '1'

Write-Host 'Adding/updating realistic 2026 holidays...'
php seeds\seed_2026_holidays.php

Write-Host 'Adding/updating August 1-4 attendance and overtime demo records...'
php seeds\seed_august_2026_demo.php

Write-Host 'Demo data updates completed.'
