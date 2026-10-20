<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\Request;
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
            'program' => ['required', Rule::in(['All', 'BSIT', 'BSBA'])],
            'year_level' => ['required', Rule::in(['All', '1', '2', '3', '4'])],
            'section' => ['required', Rule::in(['All', 'A', 'B'])],
        ]);
        $announcement = Announcement::create($data + ['author_id' => $request->user()->id]);

        return response()->json(['id' => $announcement->id], 201);
    }
}
