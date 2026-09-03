<?php

namespace App\Http\Controllers;

use App\Models\SystemAuditLog;
use App\Services\SystemAuditLogger;
use App\Support\AuditCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SystemAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->filteredQuery($request);
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $page = $query->paginate($perPage);
        $page->getCollection()->transform(fn (SystemAuditLog $log) => $log->toAuditPayload());

        return response()->json([
            'status' => 'success',
            'message' => 'Audit logs retrieved.',
            'data' => $page,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filteredQuery($request)->limit(10000)->get();

        app(SystemAuditLogger::class)->record('export.audit_logs', $request->user(), null, [
            'count' => $rows->count(),
            'filters' => $request->only(['search', 'action', 'module', 'verb', 'from', 'to', 'actor']),
        ], $request);

        $filename = 'agri-akap-audit-logs-'.now()->setTimezone(AuditCatalog::TIMEZONE)->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Log ID', 'Timestamp (UTC+8)', 'Actor ID', 'Actor Name', 'Actor Email', 'Role', 'IP Address',
                'Verb', 'Action', 'Module', 'Target Table', 'Record Code', 'Target ID',
                'Remarks', 'Before', 'After',
            ]);
            /** @var SystemAuditLog $log */
            foreach ($rows as $log) {
                $payload = $log->toAuditPayload();
                fputcsv($out, [
                    $payload['id'],
                    $payload['logged_at'],
                    $payload['actor_id'],
                    $payload['actor_name'],
                    $payload['actor_email'],
                    $payload['actor_role'],
                    $payload['ip_address'],
                    $payload['verb'],
                    $payload['action'],
                    $payload['module'],
                    $payload['target_table'],
                    $payload['record_code'],
                    $payload['target_id'],
                    $payload['remarks'],
                    $payload['before_state'] ? json_encode($payload['before_state'], JSON_UNESCAPED_UNICODE) : '',
                    $payload['after_state'] ? json_encode($payload['after_state'], JSON_UNESCAPED_UNICODE) : '',
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function integrity(Request $request): JsonResponse
    {
        $prev = SystemAuditLog::genesisHash();
        $broken = [];
        $checked = 0;

        foreach (SystemAuditLog::query()->orderBy('created_at')->orderBy('id')->cursor() as $log) {
            $checked++;
            $expected = SystemAuditLog::computeRowHash([
                'id' => $log->id,
                'created_at' => $log->created_at?->clone()->utc()->format('Y-m-d H:i:s'),
                'actor_id' => $log->actor_id,
                'action' => $log->action,
                'target_id' => $log->target_id,
                'before_state' => $log->before_state,
                'after_state' => $log->after_state,
                'remarks' => (string) ($log->remarks ?? ''),
                'prev_hash' => $prev,
            ]);

            if ($log->prev_hash !== $prev || $log->row_hash !== $expected) {
                $broken[] = [
                    'id' => $log->id,
                    'logged_at' => $log->toAuditPayload()['logged_at'],
                    'action' => $log->action,
                ];
                if (count($broken) >= 50) {
                    break;
                }
            }

            $prev = $log->row_hash ?: $prev;
        }

        return response()->json([
            'status' => 'success',
            'message' => $broken === [] ? 'Audit chain is intact.' : 'Audit chain integrity failures detected.',
            'data' => [
                'checked' => $checked,
                'valid' => $broken === [],
                'broken' => $broken,
            ],
        ]);
    }

    private function filteredQuery(Request $request): Builder
    {
        $query = SystemAuditLog::query()->orderByDesc('created_at')->orderByDesc('id');

        $action = trim((string) $request->query('action', ''));
        if ($action !== '') {
            $query->where('action', $action);
        }

        $module = trim((string) $request->query('module', ''));
        if ($module !== '') {
            $query->where('module', $module);
        }

        $verb = trim((string) $request->query('verb', ''));
        if ($verb !== '') {
            $query->where('verb', strtoupper($verb));
        }

        $actor = trim((string) $request->query('actor', ''));
        if ($actor !== '') {
            $term = '%'.$actor.'%';
            $query->where(function ($q) use ($term) {
                $q->where('actor_email', 'like', $term)
                    ->orWhere('actor_name', 'like', $term)
                    ->orWhere('actor_id', 'like', $term);
            });
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('actor_email', 'like', $term)
                    ->orWhere('actor_name', 'like', $term)
                    ->orWhere('action', 'like', $term)
                    ->orWhere('record_code', 'like', $term)
                    ->orWhere('remarks', 'like', $term);
            });
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->query('to').' 23:59:59');
        }

        return $query;
    }
}
