<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('faculty_subject')) {
            return;
        }

        if (! Schema::hasColumn('faculty_subject', 'session')) {
            Schema::table('faculty_subject', function (Blueprint $table): void {
                $table->string('session', 20)->default('All');
            });
        }

        if (Schema::hasTable('subjects') && Schema::hasColumn('subjects', 'session')) {
            DB::table('faculty_subject')->orderBy('id')->get(['id', 'subject_code'])
                ->each(function (object $assignment): void {
                    $subjectSession = DB::table('subjects')
                        ->where('code', $assignment->subject_code)
                        ->value('session');
                    if (in_array($subjectSession, ['All', 'Afternoon', 'Evening', 'Weekends'], true)) {
                        DB::table('faculty_subject')->where('id', $assignment->id)
                            ->update(['session' => $subjectSession]);
                    }
                });
        }

        if ($this->indexExists('faculty_subject_assignment_scope_unique')) {
            Schema::table('faculty_subject', function (Blueprint $table): void {
                $table->dropUnique('faculty_subject_assignment_scope_unique');
            });
        }

        if (! $this->indexExists('faculty_subject_assignment_session_unique')) {
            Schema::table('faculty_subject', function (Blueprint $table): void {
                $table->unique(
                    ['faculty_id', 'subject_code', 'semester', 'academic_year', 'section', 'session'],
                    'faculty_subject_assignment_session_unique'
                );
            });
        }

        if ($this->indexExists('faculty_subject_scope_index')) {
            Schema::table('faculty_subject', function (Blueprint $table): void {
                $table->dropIndex('faculty_subject_scope_index');
            });
        }

        if (! $this->indexExists('faculty_subject_session_scope_index')) {
            Schema::table('faculty_subject', function (Blueprint $table): void {
                $table->index(
                    ['subject_code', 'semester', 'academic_year', 'section', 'session'],
                    'faculty_subject_session_scope_index'
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('faculty_subject') || ! Schema::hasColumn('faculty_subject', 'session')) {
            return;
        }

        $duplicateAssignments = DB::table('faculty_subject')
            ->select('faculty_id', 'subject_code', 'semester', 'academic_year', 'section')
            ->groupBy('faculty_id', 'subject_code', 'semester', 'academic_year', 'section')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicateAssignments) {
            throw new RuntimeException('Cannot remove assignment sessions while multiple session schedules exist.');
        }

        if ($this->indexExists('faculty_subject_session_scope_index')) {
            Schema::table('faculty_subject', function (Blueprint $table): void {
                $table->dropIndex('faculty_subject_session_scope_index');
            });
        }

        if ($this->indexExists('faculty_subject_assignment_session_unique')) {
            Schema::table('faculty_subject', function (Blueprint $table): void {
                $table->dropUnique('faculty_subject_assignment_session_unique');
            });
        }

        if (! $this->indexExists('faculty_subject_scope_index')) {
            Schema::table('faculty_subject', function (Blueprint $table): void {
                $table->index(
                    ['subject_code', 'semester', 'academic_year', 'section'],
                    'faculty_subject_scope_index'
                );
            });
        }

        if (! $this->indexExists('faculty_subject_assignment_scope_unique')) {
            Schema::table('faculty_subject', function (Blueprint $table): void {
                $table->unique(
                    ['faculty_id', 'subject_code', 'semester', 'academic_year', 'section'],
                    'faculty_subject_assignment_scope_unique'
                );
            });
        }

        Schema::table('faculty_subject', function (Blueprint $table): void {
            $table->dropColumn('session');
        });
    }

    private function indexExists(string $name): bool
    {
        return collect(Schema::getIndexes('faculty_subject'))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }
};
