<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Explicit retry semantics, scoped to (company_id, idempotency_key).
 *
 * Caller MUST run in a PostgreSQL transaction whose tenant GUC was set by
 * SetTenantContext. Two concurrent requests for the same company+key serialize
 * before any fiscal-period, FX rate, voucher or audit write.
 *
 * Only a successful POST creates a receipt. No pending rows are persisted
 * after domain rejection. RLS/ACL protect receipts from other tenants.
 */
final readonly class JournalPostIdempotencyService
{
    public static function validateHeader(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null) {
            return null; // legacy clients: no retry guarantee
        }
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/D', $key)) {
            throw new DomainException('Idempotency-Key debe contener 16 a 128 caracteres seguros.');
        }
        return $key;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array{key:string,digest:string,replayed:JournalEntry|null}
     */
    public function lockAndLookup(int $companyId, int $userId, string $key, array $validated): array
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('Idempotencia requiere una transacción del contexto tenant.');
        }

        $digest = hash('sha256', json_encode(
            ['company_id'=>$companyId, 'user_id'=>$userId, 'payload'=>$validated],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
            'nexus:journal-post-idempotency:'.$companyId.':'.$key,
        ]);

        $existing = DB::table('journal_post_idempotency')
            ->where('company_id', $companyId)
            ->where('idempotency_key', $key)->first();

        if ($existing === null) {
            return ['key'=>$key, 'digest'=>$digest, 'replayed'=>null];
        }
        if ((int)$existing->user_id !== $userId
            || ! hash_equals((string)$existing->request_sha256, $digest)) {
            throw new ConflictHttpException(
                'La clave de idempotencia ya está asociada a otro asiento, usuario o contenido.'
            );
        }

        $entry = JournalEntry::query()
            ->with(['lines.account', 'lines.costCenter', 'lines.entity', 'fiscalPeriod'])
            ->where('company_id', $companyId)
            ->where('id', (int)$existing->journal_entry_id)
            ->first();

        if (! $entry) {
            throw new \LogicException('El recibo idempotente apunta a un asiento inaccesible.');
        }

        return ['key'=>$key, 'digest'=>$digest, 'replayed'=>$entry];
    }

    public function record(
        int $companyId, int $userId, string $key, string $digest, JournalEntry $entry
    ): void {
        if (DB::connection()->transactionLevel() < 1
            || (int)$entry->company_id !== $companyId
            || (int)$entry->created_by_user_id !== $userId) {
            throw new \LogicException('No se puede persistir una idempotencia ajena al asiento.');
        }

        DB::table('journal_post_idempotency')->insert([
            'company_id'=>$companyId,
            'user_id'=>$userId,
            'idempotency_key'=>$key,
            'request_sha256'=>$digest,
            'journal_entry_id'=>(int)$entry->id,
        ]);
    }
}
