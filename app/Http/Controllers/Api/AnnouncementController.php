<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Student;
use App\Services\MailDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:255'],
            'message' => ['required', 'string', 'min:15'],
            'priority' => ['required', 'in:Normal,High'],
            'publish_date' => ['required', 'date', 'after_or_equal:today'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:publish_date'],
            'program' => ['required', Rule::in(['All', 'ABSTM', 'BSTM', 'BSED', 'BEED', 'BSE', 'BSMA', 'BSHM', 'BSIT', 'BSBA',
                'STEM', 'ABM', 'HUMSS', 'GAS', 'TVL'])],
            'year_level' => ['required', Rule::in(['All', '1', '2', '3', '4', '11', '12'])],
            'section' => ['required', Rule::in(['All', 'A', 'B'])],
        ]);

        $queued = 0;
        $targeted = 0;
        $timestamps = ['created_at' => now(), 'updated_at' => now()];

        // null = send immediately; a future date = the scheduler sends it on the publish date.
        $publishAt = Carbon::parse($data['publish_date'])->startOfDay();
        $sendAt = $publishAt->isFuture() ? $publishAt : null;

        $announcement = DB::transaction(function () use (
            $data, $request, &$queued, &$targeted, $timestamps, $sendAt
        ) {
            $announcement = Announcement::create($data + ['author_id' => $request->user()->id]);
            $students = Student::query()
                ->when($data['program'] !== 'All', fn ($q) => $q->where('program', $data['program']))
                ->when($data['year_level'] !== 'All', fn ($q) => $q->where('year_level', (int) $data['year_level']))
                ->when($data['section'] !== 'All', fn ($q) => $q->where('section', $data['section']));
            $targeted = (clone $students)->count();

            $students->with('user:id,email')
                ->select(['id', 'user_id'])
                ->chunkById(500, function ($batch) use ($announcement, $data, $timestamps, $sendAt, &$queued) {
                    $notifications = $batch->map(fn (Student $student) => [
                        'student_id' => $student->id,
                        'type' => 'alert',
                        'title' => ($data['priority'] === 'High' ? '[High] ' : '').$data['title'],
                        'message' => $data['message'],
                        'is_read' => false,
                        ...$timestamps,
                    ])->all();

                    if ($notifications !== []) {
                        DB::table('portal_notifications')->insert($notifications);
                    }

                    // One saved email record per student, so delivery can be verified later.
                    $mails = $batch->filter(fn (Student $student) => $student->user?->email)
                        ->map(fn (Student $student) => [
                            'announcement_id' => $announcement->id,
                            'recipient' => $student->user->email,
                            'subject' => $data['title'],
                            'body' => $data['message'],
                            'status' => 'queued',
                            'send_at' => $sendAt,
                            ...$timestamps,
                        ])->values()->all();

                    if ($mails !== []) {
                        DB::table('mail_deliveries')->insert($mails);
                        $queued += count($mails);
                    }
                });

            return $announcement;
        });

        // No queue worker needed. Future-dated announcements are sent by `mail:deliver-due`.
        if ($queued > 0 && $sendAt === null) {
            $announcementId = $announcement->id;
            dispatch(static fn () => app(MailDeliveryService::class)->deliverForAnnouncement($announcementId))
                ->afterResponse();
        }

        return response()->json([
            'id' => $announcement->id,
            'targeted' => $targeted,
            'notified' => $targeted,
            'email_queued' => $queued,
            'email_scheduled_for' => $sendAt?->toDateString(),
        ], 201);
    }

    /** Real email delivery result for one announcement (sent / pending / failed). */
    public function delivery(Announcement $announcement)
    {
        $rows = DB::table('mail_deliveries')->where('announcement_id', $announcement->id);
        $counts = (clone $rows)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'announcement_id' => $announcement->id,
            'total' => (int) $counts->sum(),
            'sent' => (int) ($counts['sent'] ?? 0),
            'pending' => (int) (($counts['queued'] ?? 0) + ($counts['sending'] ?? 0)),
            'failed' => (int) ($counts['failed'] ?? 0),
            'failed_recipients' => (clone $rows)->where('status', 'failed')->limit(20)
                ->get(['recipient', 'attempts', 'error']),
        ]);
    }
}
