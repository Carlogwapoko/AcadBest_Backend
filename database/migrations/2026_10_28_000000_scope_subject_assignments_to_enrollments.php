
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Check whether an index exists.
     */
    private function indexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $candidate): bool => ($candidate['name'] ?? null) === $index);
    }

    /**
     * Get a valid academic year.
     */
    private function getAcademicYear(): string
    {
        $value = Schema::hasTable('portal_settings')
            ? DB::table('portal_settings')
                ->where('key', 'academic_year')
                ->value('value')
            : null;

        // Accept either plain text or JSON-encoded text.
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (is_string($decoded)) {
                $value = $decoded;
            }
        }

        if (
            is_string($value) &&
            preg_match('/^\d{4}-\d{4}$/', $value) &&
            (int) substr($value, 5, 4) ===
                (int) substr($value, 0, 4) + 1
        ) {
            return $value;
        }

        $year = (int) now()->format('Y');

        if ((int) now()->format('n') < 6) {
            $year--;
        }

        return $year.'-'.($year + 1);
    }

    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Update faculty_subject
        |--------------------------------------------------------------------------
        */

        if (! Schema::hasTable('faculty_subject')) {
            throw new RuntimeException(
                'The faculty_subject table does not exist.'
            );
        }

        // Add missing columns only.
        Schema::table('faculty_subject', function (Blueprint $table) {
            if (! Schema::hasColumn('faculty_subject', 'semester')) {
                $table->string('semester')->nullable();
            }

            if (! Schema::hasColumn('faculty_subject', 'academic_year')) {
                $table->string('academic_year', 9)->nullable();
            }

            if (! Schema::hasColumn('faculty_subject', 'section')) {
                $table->string('section', 10)->default('All');
            }
        });

        /*
         * IMPORTANT:
         * Create supporting indexes BEFORE dropping the old unique index.
         * MySQL may be using the old composite index for foreign keys.
         */
        Schema::table('faculty_subject', function (Blueprint $table) {
            if (! $this->indexExists(
                'faculty_subject',
                'faculty_subject_faculty_id_idx'
            )) {
                $table->index(
                    'faculty_id',
                    'faculty_subject_faculty_id_idx'
                );
            }

            if (! $this->indexExists(
                'faculty_subject',
                'faculty_subject_subject_code_idx'
            )) {
                $table->index(
                    'subject_code',
                    'faculty_subject_subject_code_idx'
                );
            }
        });

        $academicYear = $this->getAcademicYear();

        // Backfill existing faculty assignments.
        foreach (
            DB::table('faculty_subject')->orderBy('id')->get() as $assignment
        ) {
            $subject = DB::table('subjects')
                ->where('code', $assignment->subject_code)
                ->first(['semester', 'academic_year']);

            DB::table('faculty_subject')
                ->where('id', $assignment->id)
                ->update([
                    'semester' => $assignment->semester
                        ?: ($subject?->semester ?: '1st Semester'),

                    'academic_year' => $assignment->academic_year
                        ?: ($subject?->academic_year ?: $academicYear),

                    'section' => $assignment->section ?: 'All',
                ]);
        }

        /*
         * Drop the old unique index only after the supporting indexes
         * have been created.
         */
        if ($this->indexExists(
            'faculty_subject',
            'faculty_subject_faculty_id_subject_code_unique'
        )) {
            Schema::table('faculty_subject', function (Blueprint $table) {
                $table->dropUnique(
                    'faculty_subject_faculty_id_subject_code_unique'
                );
            });
        }

        if (! $this->indexExists(
            'faculty_subject',
            'faculty_subject_assignment_scope_unique'
        )) {
            Schema::table('faculty_subject', function (Blueprint $table) {
                $table->unique(
                    [
                        'faculty_id',
                        'subject_code',
                        'semester',
                        'academic_year',
                        'section',
                    ],
                    'faculty_subject_assignment_scope_unique'
                );
            });
        }

        if (! $this->indexExists(
            'faculty_subject',
            'faculty_subject_scope_index'
        )) {
            Schema::table('faculty_subject', function (Blueprint $table) {
                $table->index(
                    [
                        'subject_code',
                        'semester',
                        'academic_year',
                        'section',
                    ],
                    'faculty_subject_scope_index'
                );
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Create enrollment_subject
        |--------------------------------------------------------------------------
        */

        if (! Schema::hasTable('enrollment_subject')) {
            Schema::create('enrollment_subject', function (Blueprint $table) {
                $table->id();

                $table->foreignId('enrollment_application_id')
                    ->constrained('enrollment_applications')
                    ->cascadeOnDelete();

                $table->string('subject_code', 10);

                $table->timestamps();

                $table->unique(
                    ['enrollment_application_id', 'subject_code'],
                    'enrollment_subject_application_code_unique'
                );

                $table->foreign('subject_code')
                    ->references('code')
                    ->on('subjects')
                    ->cascadeOnDelete();
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Copy existing student subject assignments
        |--------------------------------------------------------------------------
        */

        $requiredTables = [
            'student_subject',
            'students',
            'enrollment_applications',
            'subjects',
        ];

        $canMigrateStudentSubjects = true;

        foreach ($requiredTables as $requiredTable) {
            if (! Schema::hasTable($requiredTable)) {
                $canMigrateStudentSubjects = false;
                break;
            }
        }

        if (
            $canMigrateStudentSubjects &&
            Schema::hasColumn('students', 'enrollment_application_id')
        ) {
            foreach (
                DB::table('student_subject')->orderBy('id')->get() as $assignment
            ) {
                $student = DB::table('students')
                    ->where('id', $assignment->student_id)
                    ->first();

                if (! $student) {
                    continue;
                }

                $subject = DB::table('subjects')
                    ->where('code', $assignment->subject_code)
                    ->first(['semester', 'academic_year']);

                $application = DB::table('enrollment_applications')
                    ->where('status', 'Enrolled')
                    ->where(function ($query) use ($student) {
                        $query->where('student_id', $student->id);

                        if ($student->enrollment_application_id) {
                            $query->orWhere(
                                'id',
                                $student->enrollment_application_id
                            );
                        }
                    })
                    ->when(
                        $subject?->semester,
                        fn ($query, $semester) => $query->where('semester', $semester)
                    )
                    ->when(
                        $subject?->academic_year,
                        fn ($query, $year) => $query->where('academic_year', $year)
                    )
                    ->latest('id')
                    ->first();

                if ($application) {
                    DB::table('enrollment_subject')->insertOrIgnore([
                        'enrollment_application_id' => $application->id,
                        'subject_code' => $assignment->subject_code,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 4. Update grades
        |--------------------------------------------------------------------------
        */

        if (! Schema::hasTable('grades')) {
            throw new RuntimeException(
                'The grades table does not exist.'
            );
        }

        // Keep foreign-key-supporting indexes before removing the old index.
        Schema::table('grades', function (Blueprint $table) {
            if (! $this->indexExists('grades', 'grades_student_id_idx')) {
                $table->index('student_id', 'grades_student_id_idx');
            }

            if (! $this->indexExists('grades', 'grades_subject_code_idx')) {
                $table->index('subject_code', 'grades_subject_code_idx');
            }
        });

        if (! Schema::hasColumn('grades', 'enrollment_application_id')) {
            Schema::table('grades', function (Blueprint $table) {
                $table->foreignId('enrollment_application_id')
                    ->nullable()
                    ->constrained('enrollment_applications')
                    ->nullOnDelete();
            });
        }

        if ($this->indexExists(
            'grades',
            'grades_student_id_subject_code_term_unique'
        )) {
            Schema::table('grades', function (Blueprint $table) {
                $table->dropUnique(
                    'grades_student_id_subject_code_term_unique'
                );
            });
        }

        if (! $this->indexExists(
            'grades',
            'grades_enrollment_subject_term_unique'
        )) {
            Schema::table('grades', function (Blueprint $table) {
                $table->unique(
                    [
                        'enrollment_application_id',
                        'subject_code',
                        'term',
                    ],
                    'grades_enrollment_subject_term_unique'
                );
            });
        }
    }

    public function down(): void
    {
        /*
         * Check for duplicates before restoring the old unique indexes.
         */
        $duplicateFacultySubjects = DB::table('faculty_subject')
            ->select('faculty_id', 'subject_code')
            ->groupBy('faculty_id', 'subject_code')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateFacultySubjects) {
            throw new RuntimeException(
                'Cannot roll back: faculty subject assignments contain duplicates.'
            );
        }

        $duplicateGrades = DB::table('grades')
            ->whereNull('enrollment_application_id')
            ->select('student_id', 'subject_code', 'term')
            ->groupBy('student_id', 'subject_code', 'term')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateGrades) {
            throw new RuntimeException(
                'Cannot roll back: grades contain duplicate records.'
            );
        }

        /*
         * Restore the old grades index.
         */
        if ($this->indexExists(
            'grades',
            'grades_enrollment_subject_term_unique'
        )) {
            Schema::table('grades', function (Blueprint $table) {
                $table->dropUnique(
                    'grades_enrollment_subject_term_unique'
                );
            });
        }

        if (Schema::hasColumn('grades', 'enrollment_application_id')) {
            $hasEnrollmentForeignKey = collect(Schema::getForeignKeys('grades'))
                ->contains(fn (array $foreignKey): bool => ($foreignKey['name'] ?? null) === 'grades_enrollment_application_id_foreign'
                );
            if ($hasEnrollmentForeignKey) {
                Schema::table('grades', function (Blueprint $table) {
                    $table->dropForeign(
                        'grades_enrollment_application_id_foreign'
                    );
                });
            }

            Schema::table('grades', function (Blueprint $table) {
                $table->dropColumn('enrollment_application_id');
            });
        }

        if (! $this->indexExists(
            'grades',
            'grades_student_id_subject_code_term_unique'
        )) {
            Schema::table('grades', function (Blueprint $table) {
                $table->unique(
                    ['student_id', 'subject_code', 'term'],
                    'grades_student_id_subject_code_term_unique'
                );
            });
        }

        /*
         * Remove the new enrollment subject table.
         */
        Schema::dropIfExists('enrollment_subject');

        /*
         * Restore the old faculty subject index.
         */
        if ($this->indexExists(
            'faculty_subject',
            'faculty_subject_assignment_scope_unique'
        )) {
            Schema::table('faculty_subject', function (Blueprint $table) {
                $table->dropUnique(
                    'faculty_subject_assignment_scope_unique'
                );
            });
        }

        if ($this->indexExists(
            'faculty_subject',
            'faculty_subject_scope_index'
        )) {
            Schema::table('faculty_subject', function (Blueprint $table) {
                $table->dropIndex('faculty_subject_scope_index');
            });
        }

        Schema::table('faculty_subject', function (Blueprint $table) {
            if (Schema::hasColumn('faculty_subject', 'semester')) {
                $table->dropColumn('semester');
            }

            if (Schema::hasColumn('faculty_subject', 'academic_year')) {
                $table->dropColumn('academic_year');
            }

            if (Schema::hasColumn('faculty_subject', 'section')) {
                $table->dropColumn('section');
            }
        });

        if (! $this->indexExists(
            'faculty_subject',
            'faculty_subject_faculty_id_subject_code_unique'
        )) {
            Schema::table('faculty_subject', function (Blueprint $table) {
                $table->unique(
                    ['faculty_id', 'subject_code'],
                    'faculty_subject_faculty_id_subject_code_unique'
                );
            });
        }

        /*
         * Supporting indexes are intentionally retained because foreign
         * keys or other queries may still depend on them.
         */
    }
};
