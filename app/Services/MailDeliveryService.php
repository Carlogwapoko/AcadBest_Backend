<?php

namespace App\Services;

use App\Mail\PortalNotice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MailDeliveryService
{
    private const MAX_ATTEMPTS = 3;

    /** Save the email first, so every recipient stays traceable even if sending fails. */
    public function record(
        string $to,
        string $subject,
        string $body,
        ?int $announcementId = null,
        ?Carbon $sendAt = null
    ): int {
        return DB::table('mail_deliveries')->insertGetId([
            'announcement_id' => $announcementId,
            'recipient' => $to,
            'subject' => $subject,
            'body' => $body,
            'status' => 'queued',
            'send_at' => $sendAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Send one saved email and store the real result (sent or failed + error). */
    public function deliver(int $id): bool
    {
        // Claim the row so the scheduler and the after-response job never send it twice.
        $claimed = DB::table('mail_deliveries')
            ->where('id', $id)
            ->whereIn('status', ['queued', 'failed'])
            ->update(['status' => 'sending', 'updated_at' => now()]);

        if ($claimed !== 1) {
            return false;
        }

        $row = DB::table('mail_deliveries')->find($id);

        try {
            Mail::to($row->recipient)->send(new PortalNotice($row->subject, $row->body));

            DB::table('mail_deliveries')->where('id', $id)->update([
                'status' => 'sent',
                'sent_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);

            return true;
        } catch (Throwable $error) {
            report($error);

            DB::table('mail_deliveries')->where('id', $id)->update([
                'status' => 'failed',
                'attempts' => $row->attempts + 1,
                'error' => mb_substr($error->getMessage(), 0, 500),
                'updated_at' => now(),
            ]);

            return false;
        }
    }

    /** Send every email still queued for one announcement. */
    public function deliverForAnnouncement(int $announcementId): void
    {
        @set_time_limit(0);

        DB::table('mail_deliveries')
            ->where('announcement_id', $announcementId)
            ->where('status', 'queued')
            ->orderBy('id')
            ->pluck('id')
            ->each(fn ($id) => $this->deliver($id));
    }

    /** Used by the scheduler: due emails plus failed ones that still have attempts left. */
    public function deliverDue(int $limit = 200): int
    {
        @set_time_limit(0);

        // Rows stuck in 'sending' (process killed mid-send) go back to the queue.
        DB::table('mail_deliveries')
            ->where('status', 'sending')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => 'queued']);

        return DB::table('mail_deliveries')
            ->whereIn('status', ['queued', 'failed'])
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn ($q) => $q->whereNull('send_at')->orWhere('send_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->filter(fn ($id) => $this->deliver($id))
            ->count();
    }
}