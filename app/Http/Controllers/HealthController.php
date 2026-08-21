<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class HealthController extends Controller
{
    /**
     * Comprehensive System Health Check Endpoint for Server, Docker, Mobile & Automation Scripts
     */
    public function check(Request $request)
    {
        $status = 'healthy';
        $checks = [];

        // 1. Database Check
        try {
            $pdo = DB::connection()->getPdo();
            $driver = config('database.default');
            $dbVersion = 'Unknown';
            if ($driver === 'pgsql') {
                $dbVersion = DB::select('SHOW server_version')[0]->server_version ?? 'Unknown';
            } elseif ($driver === 'sqlite') {
                $dbVersion = DB::select('SELECT sqlite_version() as ver')[0]->ver ?? 'SQLite';
            }
            $checks['database'] = [
                'status' => 'UP',
                'driver' => $driver,
                'version' => $dbVersion,
            ];
        } catch (\Throwable $e) {
            $status = 'degraded';
            $checks['database'] = [
                'status' => 'DOWN',
                'error' => $e->getMessage(),
            ];
        }

        // 2. Redis / Cache & Queue Check
        try {
            if (config('cache.default') === 'redis' || config('queue.default') === 'redis') {
                if (extension_loaded('redis')) {
                    Redis::ping();
                    $checks['redis'] = ['status' => 'UP'];
                } else {
                    $checks['redis'] = ['status' => 'SKIPPED', 'note' => 'Redis extension not loaded in CLI environment'];
                }
            } else {
                $checks['redis'] = ['status' => 'SKIPPED', 'note' => 'Redis not set as primary cache/queue driver'];
            }
        } catch (\Throwable $e) {
            $checks['redis'] = [
                'status' => 'DOWN',
                'error' => $e->getMessage(),
            ];
            // Only mark degraded if Redis was explicitly required
            if (config('cache.default') === 'redis' || config('queue.default') === 'redis') {
                $status = 'degraded';
            }
        }

        // 3. Storage Write Check
        try {
            $testFile = 'health_check_' . time() . '.tmp';
            Storage::disk('local')->put($testFile, 'ok');
            Storage::disk('local')->delete($testFile);
            $checks['storage'] = [
                'status' => 'UP',
                'writable' => true,
            ];
        } catch (\Throwable $e) {
            $status = 'degraded';
            $checks['storage'] = [
                'status' => 'DOWN',
                'error' => $e->getMessage(),
            ];
        }

        // 4. Application Metadata
        $checks['application'] = [
            'name' => config('app.name', 'FAITH AUTOMATION'),
            'env' => config('app.env'),
            'debug' => config('app.debug'),
            'timezone' => config('app.timezone'),
            'server_time' => now()->toIso8601String(),
            'version' => '1.0.0',
        ];

        $httpCode = $status === 'healthy' ? 200 : 503;

        return response()->json([
            'status' => $status,
            'timestamp' => now()->timestamp,
            'checks' => $checks,
        ], $httpCode);
    }
}
