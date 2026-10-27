<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotificationController extends Controller
{
    public function readAll(Request $request)
    {
        $student = $request->user()->student;
        if (! $student) {
            return response()->json(['message' => 'Student account required.'], 403);
        }

        DB::table('portal_notifications')->where('student_id', $student->id)
            ->where('is_read', false)->update(['is_read' => true, 'updated_at' => now()]);

        return response()->noContent();
    }

    public function read(Request $request, int $notification)
    {
        $student = $request->user()->student;
        if (! $student) {
            return response()->json(['message' => 'Student account required.'], 403);
        }
        $updated = DB::table('portal_notifications')->where('id', $notification)
            ->where('student_id', $student->id)->update(['is_read' => true, 'updated_at' => now()]);
        if (! $updated) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        return response()->noContent();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
        ]);
        $student = Student::findOrFail($data['student_id']);
        if (! $student->subjects()->whereHas(
            'faculty',
            fn ($query) => $query->whereKey($request->user()->id)
        )->exists()) {
            throw ValidationException::withMessages(['student_id' => ['You may only contact students in your assigned classes.']]);
        }

        DB::table('portal_notifications')->insert([
            'student_id' => $student->id, 'type' => 'alert', 'title' => $data['title'],
            'message' => $data['message'], 'is_read' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->noContent();
    }
}
