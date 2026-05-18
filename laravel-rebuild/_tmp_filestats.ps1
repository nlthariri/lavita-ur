$files = @(
    'app/Livewire/Reports/Filters.php',
    'resources/views/livewire/reports/filters.blade.php',
    'tests/Feature/Livewire/Reports/FiltersTest.php'
)
foreach ($f in $files) {
    $fi = Get-Item $f
    $lc = (Get-Content $f | Measure-Object -Line).Lines
    Write-Host ("{0,-60} bytes={1,7} lines={2,4}" -f $fi.Name, $fi.Length, $lc)
}
