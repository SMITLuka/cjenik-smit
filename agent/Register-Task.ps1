<#
.SYNOPSIS
  Registrira dnevni zadatak u Windows Task Scheduleru koji u 07:00 pokrece Send-Prices.ps1.
  Pokreni JEDNOM, kao Windows korisnik ispod kojeg ce agent raditi (DPAPI tajne vaze samo za tog korisnika).
#>
param([string]$At = '07:00', [string]$TaskName = 'Cjenik-smit agent')

$script = Join-Path $PSScriptRoot 'Send-Prices.ps1'
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$script`"" -WorkingDirectory $PSScriptRoot
$trigger = New-ScheduledTaskTrigger -Daily -At $At
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1) -RestartCount 2 -RestartInterval (New-TimeSpan -Minutes 5)
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Description 'Salje cjenike na cjenik.sm-it.hr' -Force | Out-Null
Write-Host "Zadatak '$TaskName' registriran za $At svaki dan. Provjeri: Get-ScheduledTask -TaskName '$TaskName'"
