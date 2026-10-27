<?php

use App\Services\MailDeliveryService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('mail:deliver-due', function () {
    $sent = app(MailDeliveryService::class)->deliverDue();
    $this->info("Sent {$sent} email(s).");
})->purpose('Send due or failed portal emails');

Schedule::command('mail:deliver-due')->everyMinute()->withoutOverlapping();