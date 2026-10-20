<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Servicio de Trazabilidad y Registro de Auditoría (ISO/IEC 27001:2023 Control 8.15 & Control 8.11).
 *
 * Aplica enmascaramiento automático de credenciales y campos sensibles antes de persistir
 * en la base de datos inmutable.
 */
final readonly class AuditLoggerService
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'plain_text_token',
        'access_token',
        'api_key',
        'secret',
        'bank_account_number',
        'bank_cci_number',
        'credit_card',
    ];

    /**
     * Registra un evento de seguridad o auditoría operacional.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        string $eventType,
        ?int $companyId,
        ?int $userId,
        ?string $auditableType = null,
        ?string $auditableId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
        string $severity = 'INFO'
    ): void {
        try {
            $ip = $request?->ip() ?? '127.0.0.1';
            $userAgent = $request?->userAgent() ?? 'system';

            AuditLog::query()->create([
                'company_id' => $companyId,
                'user_id' => $userId,
                'event_type' => $eventType,
                'auditable_type' => $auditableType,
                'auditable_id' => $auditableId,
                'old_values' => $oldValues !== null ? $this->maskSensitiveData($oldValues) : null,
                'new_values' => $newValues !== null ? $this->maskSensitiveData($newValues) : null,
                'ip_address' => substr($ip, 0, 45),
                'user_agent' => substr($userAgent, 0, 500),
                'severity' => in_array($severity, ['INFO', 'WARNING', 'CRITICAL'], true) ? $severity : 'INFO',
            ]);
        } catch (Throwable $e) {
            // Un fallo en auditoría nunca debe tumbar una transacción crítica, pero se reporta al canal de logs seguro
            Log::channel('stack')->error('AUDIT_LOGGER_EXCEPTION: ' . $e->getMessage(), [
                'event_type' => $eventType,
                'user_id' => $userId,
                'company_id' => $companyId,
            ]);
        }
    }

    /**
     * Enmascara recursivamente cualquier campo confidencial para cumplir con ISO 27001 Control 8.11.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function maskSensitiveData(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $sanitized[$key] = '***MASKED_CONFIDENTIAL***';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->maskSensitiveData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($lower, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
