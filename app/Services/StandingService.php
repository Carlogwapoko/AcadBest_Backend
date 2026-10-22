<?php

namespace App\Services;

use App\Mail\PortalNotice;
use App\Models\Grade;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class StandingService
{
    public const TERMS = ['Prelim', 'Midterm', 'Final'];
    private ?array $cachedRules = null;

    /** Configurable rules from Admin → Standing Rules. 'trend' = points drop that triggers monitoring. */
    public function rules(): array
    {
        if ($this->cachedRules !== null) {
            return $this->cachedRules;
        }

        $raw = DB::table('portal_settings')->where('key', 'standing_rules')->value('value');
        $r = $raw ? json_decode($raw, true) : [];

        return $this->cachedRules = [
            'pass' => $r['pass'] ?? 75,
            'safe' => $r['safe'] ?? 80,
            'trend' => $r['trend'] ?? 5,
        ];
    }

    /** Overall subject grade from three valid grading periods with equal weighting. */
    public function subjectAverage(Collection $byTerm): ?float
    {
        $scores = [];
        foreach (self::TERMS as $term) {
            $score = $byTerm->get($term)?->score;
            if ($score === null || $score < 0 || $score > 100) {
                return null;
            }
            $scores[] = $score;
        }

        return round(array_sum($scores) / count(self::TERMS), 2);
    }

    /**
     * @param  Collection<int, Subject>  $subjects
     * @param  Collection  $gradesBySubject  Grade models grouped by subject_code
     * @return array{gwa: ?float, status: string, failing: string[], declining: string[]}
     */
    public function evaluate(Collection $subjects, Collection $gradesBySubject): array
    {
        $rules = $this->rules();
        $sum = 0;
        $units = 0;
        $failing = [];
        $declining = [];

        foreach ($subjects as $subject) {
            $byTerm = $gradesBySubject->get($subject->code, collect())->keyBy('term');
            $avg = $this->subjectAverage($byTerm);
            if ($avg === null) {
                continue;
            }
            // weighted average = sum(subject grade x units) / sum(units)
            $sum += $avg * $subject->units;
            $units += $subject->units;

            if ($avg < $rules['pass']) {
                $failing[] = $subject->code;
            }
            $scores = collect(self::TERMS)->map(fn ($t) => $byTerm->get($t)?->score)
                ->filter(fn ($s) => $s !== null)->values();
            if ($scores->count() >= 2 && $scores->last() <= $scores[$scores->count() - 2] - $rules['trend']) {
                $declining[] = $subject->code;
            }
        }

        if (! $units) {
            return ['gwa' => null, 'status' => 'no_data', 'failing' => [], 'declining' => []];
        }
        $gwa = round($sum / $units, 2);

        $status = match (true) {
            $gwa < $rules['pass'] || $failing !== [] => 'at_risk',
            $gwa < $rules['safe'] || $declining !== [] => 'for_monitoring',
            default => 'good_standing',
        };

        return compact('gwa', 'status', 'failing', 'declining');
    }

    /** Re-check one student after grades change. Alerts only when the status actually changes. */
    public function refresh(Student $student): array
    {
        $student->load(['subjects', 'user']);
        $grades = Grade::where('student_id', $student->id)->get()->groupBy('subject_code');
        $result = $this->evaluate($student->subjects, $grades);

        if ($student->standing === $result['status']) {
            return $result;
        }
        $student->forceFill(['standing' => $result['status'], 'standing_changed_at' => now()])->save();

        if (in_array($result['status'], ['for_monitoring', 'at_risk'], true)) {
            $this->alert($student, $result);
        }

        return $result;
    }

    private function alert(Student $student, array $result): void
    {
        $name = $student->user?->name ?? 'A student';
        $label = $result['status'] === 'at_risk' ? 'At risk' : 'For monitoring';
        $detail = 'Weighted average: '.number_format($result['gwa'], 1).'.';
        if ($result['failing']) {
            $detail .= ' Below passing: '.implode(', ', $result['failing']).'.';
        }
        if ($result['declining']) {
            $detail .= ' Declining trend: '.implode(', ', $result['declining']).'.';
        }

        $facultyIds = $student->subjects->pluck('faculty_id')->filter()->unique();
        foreach (User::whereIn('id', $facultyIds)->get() as $faculty) {
            DB::table('faculty_alerts')->insert([
                'faculty_id' => $faculty->id, 'student_id' => $student->id, 'status' => $result['status'],
                'title' => $label.': '.$name, 'message' => $detail,
                'is_read' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->mail($faculty->email, $label.': '.$name, $detail);
        }

        // Student is notified only when 'at risk' (per the algorithm in the paper).
        if ($result['status'] === 'at_risk') {
            DB::table('portal_notifications')->insert([
                'student_id' => $student->id, 'type' => 'alert', 'title' => 'Academic standing alert',
                'message' => 'Your standing is now At Risk. '.$detail.' Please consult your instructor.',
                'is_read' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->mail($student->user?->email, 'Academic standing alert', $detail.' Please consult your instructor.');
        }
    }

    private function mail(?string $to, string $title, string $body): void
    {
        if (! $to) {
            return;
        }
        dispatch(function () use ($to, $title, $body) {
            try {
                Mail::to($to)->send(new PortalNotice($title, $body));
            } catch (\Throwable $error) {
                report($error);
            }
        })->afterResponse();
    }
}