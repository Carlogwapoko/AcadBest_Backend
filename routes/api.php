<?php

use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PortalDataController;
use App\Http\Controllers\Api\ServiceRequestController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/enrollments', [EnrollmentController::class, 'store'])->middleware('throttle:10,1');
Route::post('/enrollments/track', [EnrollmentController::class, 'track'])->middleware('throttle:20,1');

Route::middleware('api.token')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/portal', [PortalDataController::class, 'index']);
    Route::get('/requests', [ServiceRequestController::class, 'index']);
    Route::get('/enrollments', [EnrollmentController::class, 'index'])->middleware('role:admin,registrar');
    Route::post('/requests', [ServiceRequestController::class, 'store'])->middleware('role:student');
    Route::put('/grades', [GradeController::class, 'storeBatch'])->middleware('role:faculty,registrar,admin');
    Route::put('/subjects/{subject}', [SubjectController::class, 'update'])->middleware('role:faculty,registrar,admin');
    Route::post('/announcements', [AnnouncementController::class, 'store'])->middleware('role:registrar,admin');
    Route::put('/notifications/{notification}/read', [NotificationController::class, 'read'])->middleware('role:student');
    Route::put('/notifications/read-all', [NotificationController::class, 'readAll'])->middleware('role:student');
    Route::post('/notifications', [NotificationController::class, 'store'])->middleware('role:faculty');

    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
        Route::put('/settings/rules', [SettingsController::class, 'rules']);
        Route::put('/settings/modules', [SettingsController::class, 'setModule']);
    });

    Route::middleware('role:registrar,admin')->group(function () {
        Route::put('/enrollments/{enrollment}', [EnrollmentController::class, 'update']);
        Route::put('/requests/{serviceRequest}', [ServiceRequestController::class, 'update']);
        Route::post('/subjects', [SubjectController::class, 'store']);
        Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy']);
        Route::put('/faculty/{user}/subjects', [SubjectController::class, 'assignFaculty']);
        Route::put('/students/{student}/subjects', [SubjectController::class, 'assignStudent']);
    });
});
