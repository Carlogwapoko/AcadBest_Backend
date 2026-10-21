<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PortalNotice;
use App\Models\Announcement;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

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
            'program' => ['required', Rule::in(['All', 'BSIT', 'BSBA'])],
            'year_level' => ['required', Rule::in(['All', '1', '2', '3', '4'])],
            'section' => ['required', Rule::in(['All', 'A', 'B'])],
        ]);
        $emails = [];
        $targeted = 0;
        $timestamps = ['created_at' => now(), 'updated_at' => now()];

        $announcement = DB::transaction(function () use (
            $data, $request, &$emails, &$targeted, $timestamps
        ) {
            $announcement = Announcement::create($data + ['author_id' => $request->user()->id]);
            $students = Student::query()
                ->when($data['program'] !== 'All', fn ($q) => $q->where('program', $data['program']))
                ->when($data['year_level'] !== 'All', fn ($q) => $q->where('year_level', (int) $data['year_level']))
                ->when($data['section'] !== 'All', fn ($q) => $q->where('section', $data['section']));
            $targeted = (clone $students)->count();

            $students->with('user:id,email')
                ->select(['id', 'user_id'])
                ->chunkById(500, function ($batch) use (&$emails, $data, $timestamps) {
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

                    foreach ($batch as $student) {
                        if ($student->user?->email) {
                            $emails[] = $student->user->email;
                        }
                    }
                });

            return $announcement;
        });

        $sendAt = now()->parse($data['publish_date'])->startOfDay();
        if ($emails !== []) {
            dispatch(function () use ($emails, $data, $sendAt) {
                foreach ($emails as $email) {
                    try {
                        $mail = new PortalNotice($data['title'], $data['message']);
                        // Future-dated announcements are queued for their publish date.
                        $sendAt->isFuture()
                            ? Mail::to($email)->later($sendAt, $mail)
                            : Mail::to($email)->send($mail);
                    } catch (Throwable $error) {
                        report($error);
                    }
                }
            })->afterResponse();
        }

        return response()->json([
            'id' => $announcement->id,
            'targeted' => $targeted, 'notified' => $targeted, 'emailed' => count($emails),
        ], 201);
    }
}