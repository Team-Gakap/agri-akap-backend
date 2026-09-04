<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrgyDashboardController;
use App\Http\Controllers\BroadcastController;
use App\Http\Controllers\DamageAssessmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DistributionController;
use App\Http\Controllers\ExecutiveReportingController;
use App\Http\Controllers\FacebookWeatherCardController;
use App\Http\Controllers\FarmerController;
use App\Http\Controllers\FarmPlotController;
use App\Http\Controllers\HarvestLogController;
use App\Http\Controllers\IntelligenceController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PestMonitoringController;
use App\Http\Controllers\PlantingLogController;
use App\Http\Controllers\ProgramController;
use App\Http\Controllers\PsgcController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ReportWorkflowController;
use App\Http\Controllers\SmsSettingsController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StandingCropLogController;
use App\Http\Controllers\SubsidyController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\SystemAuditLogController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WeatherController;
use Illuminate\Support\Facades\Route;

// ── Public ────────────────────────────────────────────────────────────────────
Route::post('/login', [AuthController::class, 'login']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgot'])
    ->middleware('throttle:5,1');
Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:5,1');

Route::get('/auth/mfa/setup-qr', [MfaController::class, 'setupQr']);
Route::post('/auth/mfa/setup', [MfaController::class, 'setup']);
Route::post('/auth/mfa/verify', [MfaController::class, 'verify']);
Route::post('/auth/mfa/sms/send', [MfaController::class, 'sendSms']);
Route::post('/auth/mfa/sms/verify', [MfaController::class, 'verifySms']);

// Cheap unauthenticated reachability probe for the mobile app's offline detector.
Route::get('/ping', fn () => response()->json(['status' => 'ok']));

// ── Authenticated ─────────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::get('/auth/mfa/status', [MfaController::class, 'status'])
        ->middleware('role:super_admin,admin');
    Route::post('/auth/mfa/recovery-codes', [MfaController::class, 'recoveryCodes'])
        ->middleware('role:super_admin,admin');
    Route::patch('/auth/mfa/mobile', [MfaController::class, 'updateMobile'])
        ->middleware('role:super_admin,admin');

    Route::get('/staff/summary', [StaffController::class, 'summary'])
        ->middleware('role:admin');
    Route::get('/staff', [StaffController::class, 'index'])
        ->middleware('role:admin');
    Route::post('/staff', [StaffController::class, 'store'])
        ->middleware('role:admin');
    Route::patch('/staff/{user}', [StaffController::class, 'update'])
        ->middleware('role:admin');
    Route::post('/staff/{user}/reset-password', [StaffController::class, 'resetPassword'])
        ->middleware('role:admin');
    Route::post('/staff/{user}/unlock', [StaffController::class, 'unlock'])
        ->middleware('role:admin');
    Route::post('/staff/{user}/revoke-sessions', [StaffController::class, 'revokeSessions'])
        ->middleware('role:admin');

    Route::get('/system/audit-logs', [SystemAuditLogController::class, 'index'])
        ->middleware('role:admin');
    Route::get('/system/audit-logs/export', [SystemAuditLogController::class, 'export'])
        ->middleware('role:admin');
    Route::get('/system/audit-logs/integrity', [SystemAuditLogController::class, 'integrity'])
        ->middleware('role:super_admin');
    Route::get('/system/sms-settings', [SmsSettingsController::class, 'show'])
        ->middleware('role:super_admin');
    Route::patch('/system/sms-settings', [SmsSettingsController::class, 'update'])
        ->middleware('role:super_admin');
    Route::get('/system/facebook-status', [FacebookWeatherCardController::class, 'status'])
        ->middleware('role:super_admin');

    // Farmer Registry
    Route::get('/farmers', [FarmerController::class, 'index']);
    Route::post('/farmers', [FarmerController::class, 'store']);
    Route::post('/farmers/import', [FarmerController::class, 'import'])
        ->middleware('role:admin');
    Route::get('/farmers/lookup', [FarmerController::class, 'lookup']);
    Route::get('/farmers/barangays', [FarmerController::class, 'barangays']);
    Route::get('/farmers/locations', [FarmerController::class, 'locations']);
    Route::prefix('psgc')->group(function () {
        Route::get('/regions', [PsgcController::class, 'regions']);
        Route::get('/regions/{code}/provinces', [PsgcController::class, 'provinces']);
        Route::get('/provinces/{code}/cities-municipalities', [PsgcController::class, 'cities']);
        Route::get('/cities-municipalities/{code}/barangays', [PsgcController::class, 'barangays']);
        Route::get('/defaults/echague', [PsgcController::class, 'echagueDefaults']);
    });
    Route::get('/farmers/commodities', [FarmerController::class, 'commodities']);
    Route::get('/farmers/{id}', [FarmerController::class, 'show']);
    Route::get('/farmers/{id}/active-planting', [FarmerController::class, 'activePlanting']);
    Route::patch('/farmers/{id}', [FarmerController::class, 'update'])
        ->middleware('role:admin');
    Route::delete('/farmers/{id}', [FarmerController::class, 'destroy'])
        ->middleware('role:admin');
    Route::post('/farmers/{id}/photo', [FarmerController::class, 'uploadPhoto'])
        ->middleware('role:admin,barangay_official');
    Route::post('/farmers/{id}/return-for-correction', [FarmerController::class, 'returnForCorrection'])
        ->middleware('role:admin');
    Route::post('/farmers/{id}/verify', [FarmerController::class, 'verify'])
        ->middleware('role:admin');
    Route::post('/farmers/{id}/notify', [FarmerController::class, 'notify'])
        ->middleware('role:admin');

    // Farm Plots
    Route::get('/users', [UserController::class, 'index'])
        ->middleware('role:admin');

    Route::get('/farm-plots', [FarmPlotController::class, 'index']);
    Route::post('/farm-plots', [FarmPlotController::class, 'store'])
        ->middleware('role:technician,admin');
    Route::get('/farm-plots/{id}', [FarmPlotController::class, 'show']);
    Route::patch('/farm-plots/{id}', [FarmPlotController::class, 'update'])
        ->middleware('role:admin,technician');
    Route::delete('/farm-plots/{id}', [FarmPlotController::class, 'destroy'])
        ->middleware('role:admin');

    // Subsidy Programs
    Route::get('/programs', [ProgramController::class, 'index']);
    Route::get('/programs/{id}', [ProgramController::class, 'show']);
    Route::post('/programs', [ProgramController::class, 'store'])
        ->middleware('role:admin');
    Route::patch('/programs/{id}/deactivate', [ProgramController::class, 'deactivate'])
        ->middleware('role:admin');
    Route::post('/programs/{id}/restock', [ProgramController::class, 'restock'])
        ->middleware('role:admin');
    Route::patch('/programs/{id}/config', [ProgramController::class, 'updateConfig'])
        ->middleware('role:admin');

    // Subsidy Auto-Masterlist programs (tbl_subsidy_programs)
    Route::get('/subsidies', [SubsidyController::class, 'index'])
        ->middleware('role:admin,technician,barangay_official');
    Route::post('/subsidies', [SubsidyController::class, 'store'])
        ->middleware('role:admin');
    Route::post('/subsidies/{id}/restock', [SubsidyController::class, 'restock'])
        ->middleware('role:admin');
    Route::patch('/subsidies/{id}/config', [SubsidyController::class, 'updateConfig'])
        ->middleware('role:admin');
    Route::patch('/subsidies/{id}/status', [SubsidyController::class, 'updateStatus'])
        ->middleware('role:admin');
    Route::post('/subsidies/{id}/generate-masterlist', [SubsidyController::class, 'generateMasterlist'])
        ->middleware('role:admin');
    Route::get('/subsidies/{id}/masterlist', [SubsidyController::class, 'masterlist'])
        ->middleware('role:admin');
    Route::post('/subsidies/{id}/verify-farmer', [SubsidyController::class, 'verifyFarmer'])
        ->middleware('role:admin,technician');
    Route::post('/subsidies/{id}/claim-farmer', [SubsidyController::class, 'claimForFarmer'])
        ->middleware('role:admin,technician');
    Route::patch('/subsidies/{id}/beneficiaries/{beneficiaryId}/claim', [SubsidyController::class, 'claimBeneficiary'])
        ->middleware('role:admin,technician');
    Route::patch('/subsidies/beneficiaries/{beneficiaryId}', [SubsidyController::class, 'updateBeneficiaryClaim'])
        ->middleware('role:admin');
    Route::delete('/subsidies/beneficiaries/{beneficiaryId}', [SubsidyController::class, 'voidBeneficiaryClaim'])
        ->middleware('role:admin');

    // Distribution / Claiming (field dispense + admin fallback)
    Route::post('/distributions/verify', [DistributionController::class, 'verify'])
        ->middleware('role:admin,technician');
    Route::post('/distributions/claim', [DistributionController::class, 'processClaim'])
        ->middleware('role:admin,technician');

    // Offline Bulk Sync (Dexie → Laravel)
    Route::post('/sync/bulk', [SyncController::class, 'bulkSync']);

    // Climate Monitoring (hyper-local Open-Meteo cache + weather SMS advisories)
    Route::get('/weather/current', [WeatherController::class, 'current']);
    Route::get('/weather/hourly/{barangay_name}', [WeatherController::class, 'hourly']);
    Route::get('/weather/historical/{barangay_name}', [WeatherController::class, 'historical']);
    Route::get('/weather/heatmap', [WeatherController::class, 'heatmap']);
    Route::get('/weather/radar', [WeatherController::class, 'radar']);
    Route::get('/weather/radar/point', [WeatherController::class, 'radarPoint']);
    Route::get('/weather/national-advisories', [WeatherController::class, 'nationalAdvisories']);
    Route::get('/weather/barangays', [WeatherController::class, 'barangays']);
    Route::get('/weather/nearest', [WeatherController::class, 'nearest']);
    Route::get('/weather/reverse', [WeatherController::class, 'reverse']);
    Route::get('/weather/advisories', [WeatherController::class, 'advisories'])
        ->middleware('role:admin');
    Route::post('/weather/advisories/send', [WeatherController::class, 'sendAdvisory'])
        ->middleware('role:admin');
    Route::get('/weather/facebook-card', [FacebookWeatherCardController::class, 'show'])
        ->middleware('role:admin');
    Route::get('/weather/facebook-card.png', [FacebookWeatherCardController::class, 'png'])
        ->middleware('role:admin');
    Route::post('/weather/facebook-card/post', [FacebookWeatherCardController::class, 'post'])
        ->middleware('role:admin');
    Route::get('/weather/facebook-posts', [FacebookWeatherCardController::class, 'history'])
        ->middleware('role:admin');

    // Analytics & Reports
    Route::get('/dashboard/stats', [DashboardController::class, 'getStats']);
    Route::get('/dashboard/overview', [DashboardController::class, 'overview'])
        ->middleware('role:admin');
    Route::get('/dashboard/barangay', [DashboardController::class, 'barangayOverview'])
        ->middleware('role:barangay_official,admin');

    // Barangay Localized Command Center (4-tier + Weather Hub)
    Route::get('/brgy/dashboard', [BrgyDashboardController::class, 'index'])
        ->middleware('role:barangay_official,admin');
    Route::get('/dashboard/map-data', [DashboardController::class, 'mapData'])
        ->middleware('role:admin,technician');
    Route::get('/dashboard/forecast', [DashboardController::class, 'forecast'])
        ->middleware('role:admin');
    Route::get('/dashboard/risk-index', [DashboardController::class, 'riskIndex'])
        ->middleware('role:admin');
    Route::get('/dashboard/report', [DashboardController::class, 'accomplishmentReport'])
        ->middleware('role:admin');
    Route::get('/reports/export/{type}', [ReportExportController::class, 'export'])
        ->middleware('role:admin');

    // 4-Tier Enterprise Analytics (Descriptive → Diagnostic → Predictive → Prescriptive)
    Route::get('/analytics/dashboard', [AnalyticsController::class, 'dashboard'])
        ->middleware('role:admin');

    // Statutory Report Workflows
    Route::post('/report-workflows/preview', [ReportWorkflowController::class, 'preview'])
        ->middleware('role:admin');
    Route::get('/report-workflows', [ReportWorkflowController::class, 'index'])
        ->middleware('role:admin');
    Route::post('/report-workflows', [ReportWorkflowController::class, 'store'])
        ->middleware('role:admin');
    Route::get('/report-workflows/{id}', [ReportWorkflowController::class, 'show'])
        ->middleware('role:admin');
    Route::patch('/report-workflows/{id}/verify', [ReportWorkflowController::class, 'verify'])
        ->middleware('role:admin');
    Route::patch('/report-workflows/{id}/finalize', [ReportWorkflowController::class, 'finalize'])
        ->middleware('role:admin');

    // Technician personal contribution history
    Route::get('/technician/activity-log', [DashboardController::class, 'activityLog'])
        ->middleware('role:technician');
    Route::get('/technician/history', [DashboardController::class, 'fieldHistory'])
        ->middleware('role:technician');

    // SMS Broadcast
    Route::get('/broadcasts', [BroadcastController::class, 'index']);
    Route::post('/broadcasts/preview', [BroadcastController::class, 'previewAudience'])
        ->middleware('role:admin');
    Route::post('/broadcasts/send', [BroadcastController::class, 'sendBulkSms'])
        ->middleware('role:admin');

    // Agricultural Intelligence
    Route::post('/intelligence/crop-log', [IntelligenceController::class, 'logCrop']);
    Route::get('/intelligence/dashboard', [IntelligenceController::class, 'getDashboardData']);
    Route::post('/intelligence/pest-report', [IntelligenceController::class, 'reportPest']);
    Route::get('/intelligence/crop-history', [IntelligenceController::class, 'cropHistory']);
    Route::get('/intelligence/monoculture-alerts', [IntelligenceController::class, 'monocultureAlerts'])
        ->middleware('role:admin');
    Route::patch('/intelligence/pest-outbreaks/{id}/status', [IntelligenceController::class, 'updatePestStatus'])
        ->middleware('role:admin,technician');
    Route::post('/intelligence/pest-outbreaks/{id}/advisory', [IntelligenceController::class, 'broadcastAdvisory'])
        ->middleware('role:admin');

    // Disaster Damage Assessment Workflow
    Route::get('/damage-assessments', [DamageAssessmentController::class, 'index']);
    Route::post('/damage-assessments', [DamageAssessmentController::class, 'store'])
        ->middleware('role:barangay_official,technician,admin');
    Route::get('/damage-assessments/{id}', [DamageAssessmentController::class, 'show']);
    Route::patch('/damage-assessments/{id}', [DamageAssessmentController::class, 'update'])
        ->middleware('role:admin,barangay_official');
    Route::patch('/damage-assessments/{id}/field-validate', [DamageAssessmentController::class, 'fieldValidate'])
        ->middleware('role:technician,admin');
    Route::patch('/damage-assessments/{id}/verify', [DamageAssessmentController::class, 'verify'])
        ->middleware('role:barangay_official,admin');
    Route::patch('/damage-assessments/{id}/decide', [DamageAssessmentController::class, 'decide'])
        ->middleware('role:admin');
    Route::delete('/damage-assessments/{id}', [DamageAssessmentController::class, 'destroy'])
        ->middleware('role:barangay_official,technician,admin');

    // Barangay / field encoding ledgers (planting + standing + harvest + pest)
    Route::get('/planting-logs', [PlantingLogController::class, 'index']);
    Route::post('/planting-logs', [PlantingLogController::class, 'store'])
        ->middleware('role:barangay_official,technician,admin');
    Route::patch('/planting-logs/{id}', [PlantingLogController::class, 'update'])
        ->middleware('role:admin,barangay_official');
    Route::delete('/planting-logs/{id}', [PlantingLogController::class, 'destroy'])
        ->middleware('role:barangay_official,technician,admin');
    Route::get('/standing-crop-logs', [StandingCropLogController::class, 'index']);
    Route::post('/standing-crop-logs', [StandingCropLogController::class, 'store'])
        ->middleware('role:barangay_official,technician,admin');
    Route::patch('/standing-crop-logs/{id}', [StandingCropLogController::class, 'update'])
        ->middleware('role:admin,barangay_official');
    Route::delete('/standing-crop-logs/{id}', [StandingCropLogController::class, 'destroy'])
        ->middleware('role:barangay_official,technician,admin');
    Route::get('/harvest-logs', [HarvestLogController::class, 'index']);
    Route::post('/harvest-logs', [HarvestLogController::class, 'store'])
        ->middleware('role:barangay_official,technician,admin');
    Route::patch('/harvest-logs/{id}', [HarvestLogController::class, 'update'])
        ->middleware('role:admin,barangay_official');
    Route::delete('/harvest-logs/{id}', [HarvestLogController::class, 'destroy'])
        ->middleware('role:barangay_official,technician,admin');
    Route::get('/pest-guidelines', [PestMonitoringController::class, 'guidelines']);
    Route::get('/pest-monitoring', [PestMonitoringController::class, 'index']);
    Route::get('/pest-monitoring/{id}', [PestMonitoringController::class, 'show']);
    Route::post('/pest-monitoring', [PestMonitoringController::class, 'store'])
        ->middleware('role:barangay_official,technician,admin');
    Route::patch('/pest-monitoring/{id}', [PestMonitoringController::class, 'update'])
        ->middleware('role:admin,barangay_official');
    Route::patch('/pest-monitoring/{id}/field-validate', [PestMonitoringController::class, 'fieldValidate'])
        ->middleware('role:technician,admin');
    Route::delete('/pest-monitoring/{id}', [PestMonitoringController::class, 'destroy'])
        ->middleware('role:barangay_official,technician,admin');

    // MAO Executive Reporting Suite (live encoded data)
    Route::get('/executive-reports', [ExecutiveReportingController::class, 'index'])
        ->middleware('role:admin');

    // MAO Dedicated Report Endpoints (new report module)
    Route::get('/reports/subsidies', [ReportsController::class, 'subsidies'])->middleware('role:admin,barangay_official');
    Route::get('/reports/crop-production', [ReportsController::class, 'cropProduction'])->middleware('role:admin,barangay_official');
    Route::get('/reports/pest-surveillance', [ReportsController::class, 'pestSurveillance'])->middleware('role:admin,barangay_official');
    Route::get('/reports/damage-calamity', [ReportsController::class, 'damageCalamity'])->middleware('role:admin,barangay_official');
});
